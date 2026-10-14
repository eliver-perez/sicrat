<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Authorization;
use App\Core\HttpError;
use App\Core\Service;
use App\Repositories\PersonIdentityRepository;
use App\Repositories\PersonImportRepository;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell\FormulaCell;

class PersonImportService extends Service
{
    public const MAX_ROWS = 20000;
    public const MAX_BYTES = 10485760;
    private PersonIdentityService $identity;
    public function __construct(private PersonImportRepository $repository)
    {
        $this->identity = new PersonIdentityService(new PersonIdentityRepository($repository->connection()));
    }

    public function upload(array $file, ?string $organization, string $delimiter = ','): array
    {
        Authorization::requireOperation('persons.import');
        $organizationId = $this->identity->organization($organization);
        $effective = (new \App\Repositories\PermissionRepository($this->repository->connection()))->require(\App\Core\Auth::id() ?? 0,'personas.importar',\App\Core\Auth::processId());
        if (!$effective['super'] && !in_array('organizacion',$effective['scopes']['personas.importar'],true)) throw new HttpError('La importación requiere alcance de organización para revisar identidades y coincidencias.',403);
        if (in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new InvalidArgumentException('El archivo supera el límite de carga permitido por el servidor.');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) throw new InvalidArgumentException('Selecciona un archivo válido.');
        if (($file['size'] ?? 0) < 1) throw new InvalidArgumentException('El archivo está vacío.');
        if (($file['size'] ?? 0) > self::MAX_BYTES || filesize($file['tmp_name']) > self::MAX_BYTES) throw new InvalidArgumentException('El archivo debe pesar como máximo 10 MB.');
        $extension = strtolower(pathinfo($file['name'] ?? '',PATHINFO_EXTENSION));
        if (!in_array($extension,['csv','xlsx'],true)) throw new InvalidArgumentException('Solo se admiten CSV y XLSX.');
        if (!in_array($delimiter,[',',';',"\t"],true)) throw new InvalidArgumentException('Separador no válido.');
        if ($extension === 'xlsx') $this->validateArchive($file['tmp_name']);
        $id = $this->uuidBinaryToString($this->generateUuidBinary());
        $this->repository->purgeExpired();
        $number = 0;
        $db = $this->repository->connection();
        try {
            foreach ($this->readRows($file['tmp_name'],$extension,$delimiter) as $values) {
                if (count($values) > 50) throw new InvalidArgumentException('Se admiten hasta 50 columnas.');
                foreach ($values as $value) {
                    if (!mb_check_encoding($value,'UTF-8') || strlen($value) > 2000) throw new InvalidArgumentException('Usa texto UTF-8 y celdas de hasta 2000 bytes.');
                }
                if ($number === 0) {
                    $headers = array_map(static fn ($value) => trim(ltrim($value,"\xEF\xBB\xBF")),$values);
                    if ($headers === [] || count(array_filter($headers)) === 0) throw new InvalidArgumentException('El archivo necesita encabezados.');
                    $this->repository->create($id,$organizationId,$headers);
                    $number++;
                    continue;
                }
                if (count(array_filter($values,static fn ($value) => trim($value) !== '')) === 0) continue;
                if ($number > self::MAX_ROWS) throw new InvalidArgumentException('Se admiten hasta 20 000 personas por archivo. Divide el archivo.');
                if (!$db->inTransaction()) $db->beginTransaction();
                $this->repository->addRow($id,$number,$values);
                if ($number % 100 === 0) $db->commit();
                $number++;
            }
            if ($db->inTransaction()) $db->commit();
            if ($number < 2) throw new InvalidArgumentException('El archivo no contiene personas.');
            $this->repository->updateImport($id,['total'=>$number-1]);
            return ['id'=>$id,'headers'=>$headers,'total'=>$number-1,'fields'=>array_keys(PersonIdentityService::FIELDS),'expires_in_hours'=>24];
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) $db->rollBack();
            $this->repository->remove($id);
            throw $exception;
        }
    }

    private function validateArchive(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new InvalidArgumentException('El XLSX no es válido.');
        try {
            $total = 0;
            if ($zip->numFiles > 2000 || $zip->locateName('xl/workbook.xml') === false) throw new InvalidArgumentException('El XLSX no es válido o contiene demasiados componentes.');
            for ($index=0;$index<$zip->numFiles;$index++) {
                $entry = $zip->statIndex($index);
                $total += $entry['size'];
                if ($total > 104857600 || str_contains($entry['name'],'..') || preg_match('/(vbaProject|externalLinks|embeddings|\.bin$)/i',$entry['name'])) throw new InvalidArgumentException('No se admiten macros, objetos incrustados, vínculos externos o archivos descomprimidos de más de 100 MB.');
            }
        } finally { $zip->close(); }
    }

    private function readRows(string $path, string $extension, string $delimiter): \Generator
    {
        if ($extension === 'csv') {
            $handle = fopen($path,'rb');
            try { while (($row=fgetcsv($handle,0,$delimiter,'"','')) !== false) yield array_map(static fn ($value) => (string) ($value ?? ''),$row); }
            finally { fclose($handle); }
            return;
        }
        // OpenSpout lee secuencialmente; no evalúa fórmulas. Estas se rechazan explícitamente.
        $temporary = sys_get_temp_dir() . '/sicrat-xlsx-' . bin2hex(random_bytes(8));
        mkdir($temporary,0700);
        $options = new \OpenSpout\Reader\XLSX\Options();
        $options->setTempFolder($temporary);
        $reader = new \OpenSpout\Reader\XLSX\Reader($options);
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $values = [];
                    foreach ($row->getCells() as $cell) {
                        if ($cell instanceof FormulaCell) throw new InvalidArgumentException('El XLSX contiene fórmulas. Usa valores de texto o fecha.');
                        $value = $cell->getValue();
                        $values[] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) ($value ?? '');
                    }
                    yield $values;
                }
                break; // La interfaz informa que se importa únicamente la primera hoja.
            }
        } catch (\OpenSpout\Common\Exception\SpoutException $exception) {
            throw new InvalidArgumentException('El XLSX está dañado o no puede leerse.', 0, $exception);
        } finally { $reader->close(); @rmdir($temporary); }
    }

    public function configure(string $id, array $data): array
    {
        Authorization::requireOperation('persons.import');
        $this->repository->acquire($id);
        try {
            $import = $this->repository->get($id);
            if ($import['estado'] !== 'cargado') throw new HttpError('El mapeo ya fue fijado. Carga un archivo nuevo para cambiarlo.',409);
            $mapping = $data['mapping'] ?? [];
            if (!is_array($mapping) || !isset($mapping['name'])) throw new InvalidArgumentException('Asigna una columna al nombre.');
            $headers = json_decode($import['encabezados'],true,512,JSON_THROW_ON_ERROR);
            $normalized = [];
            foreach ($mapping as $field=>$column) {
                if (!array_key_exists($field,PersonIdentityService::FIELDS)) throw new InvalidArgumentException('Campo de destino desconocido.');
                if ($column === '' || $column === null) continue;
                $position = filter_var($column,FILTER_VALIDATE_INT);
                if ($position === false || $position < 0 || $position >= count($headers)) throw new InvalidArgumentException('Columna inválida.');
                $normalized[$field]=$position;
            }
            if (!isset($normalized['name'])) throw new InvalidArgumentException('Asigna una columna al nombre.');
            $mode=$data['mode'] ?? 'batches';
            if (!in_array($mode,['batches','automatic'],true)) throw new InvalidArgumentException('Selecciona una modalidad de importación.');
            $this->repository->updateImport($id,['mapeo'=>json_encode($normalized,JSON_THROW_ON_ERROR),'detectar_duplicados'=>$mode==='automatic' ? 1 : $this->normalizeStatus($data['detect_duplicates'] ?? 1),'modo'=>$mode,'estado'=>'revision']);
            return ['total'=>(int) $import['total']];
        } finally { $this->repository->release($id); }
    }

    public function preview(string $id, int $after): array
    {
        Authorization::requireOperation('persons.import');
        $this->repository->acquire($id);
        try {
            $import = $this->repository->get($id);
            if (!in_array($import['estado'],['revision','revisado'],true)) throw new HttpError('Primero asigna las columnas.',409);
            return $this->reviewBatch($id,$import,$after);
        } finally { $this->repository->release($id); }
    }

    private function reviewBatch(string $id,array $import,int $after): array
    {
            // La revisión secuencial permite encontrar coincidencias dentro del propio archivo.
            $next = $this->repository->firstPending($id);
            if ($next !== null && $after >= $next) throw new InvalidArgumentException('Revisa los lotes en orden.');
            $mapping = json_decode($import['mapeo'],true,512,JSON_THROW_ON_ERROR);
            $result = [];
            foreach ($this->repository->reviewRows($id,max(0,$after)) as $row) {
                if ($row['estado'] !== 'pendiente') { $result[]=$this->previewRow($row); continue; }
                $values = json_decode($row['datos'],true,512,JSON_THROW_ON_ERROR);
                $data=[];
                foreach ($mapping as $field=>$column) $data[$field]=$values[$column] ?? null;
                try {
                    $fields = $this->identity->normalize($data);
                    $inspection = $this->identity->inspect((int) $import['organizacion'],$fields,(bool) $import['detectar_duplicados']);
                    $normalizedName = trim(implode(' ',array_filter([$fields['nombre'],$fields['paterno'],$fields['materno']],static fn($value)=>$value!==null && $value!=='')));
                    $fileMatches = $this->repository->fileMatches($id,(int) $row['fila'],[...$fields,'nombre_completo'=>$normalizedName],(bool) $import['detectar_duplicados']);
                    $inspection['file_matches']=$fileMatches;
                    if ($import['modo']==='automatic') foreach ($fileMatches as $match) {
                        $previous=$this->repository->rows($id,(int)$match['fila']-1,1)[0];
                        if ($previous['estado']==='listo' && !$previous['decision']) {
                            $review=json_decode($previous['revision'],true,512,JSON_THROW_ON_ERROR);
                            $review['note']='Coincide con la fila '.(int)$row['fila'].' del mismo archivo. Revisa antes de crear.';
                            $this->repository->updateRow($id,(int)$match['fila'],['estado'=>'decision','revision'=>json_encode($review,JSON_THROW_ON_ERROR)]);
                        }
                    }
                    foreach ($fileMatches as $match) {
                        foreach (['curp','clave_elector'] as $column) {
                            if (isset($fields[$column]) && $fields[$column] !== '' && mb_strtoupper((string) $match[$column]) === $fields[$column] && (int) $inspection['policy'][$column.'_unica'] === 1) $inspection['blocked']=true;
                        }
                    }
                    $needsDecision = $inspection['matches'] !== [] || $fileMatches !== [];
                    $update=['normalizados'=>json_encode($data,JSON_THROW_ON_ERROR),'nombre_completo'=>$normalizedName,'curp'=>$fields['curp'],'clave_elector'=>$fields['clave_elector'],'telefono'=>$fields['telefono'],'email'=>$fields['email'],
                        'revision'=>json_encode($inspection,JSON_THROW_ON_ERROR),'estado'=>$needsDecision ? 'decision' : 'listo'];
                    $this->repository->updateRow($id,(int) $row['fila'],$update);
                    $row=[...$row,...$update];
                } catch (InvalidArgumentException|HttpError $exception) {
                    if ($exception instanceof HttpError && $exception->statusCode===403) throw $exception;
                    $update=['revision'=>json_encode(['error'=>$exception->getMessage()],JSON_THROW_ON_ERROR),'estado'=>'invalido'];
                    $this->repository->updateRow($id,(int) $row['fila'],$update); $row=[...$row,...$update];
                }
                $result[]=$this->previewRow($row);
            }
            $summary=$this->repository->summary($id);
            if ($summary['pendiente']===0) $this->repository->updateImport($id,['estado'=>'revisado']);
            return ['rows'=>$result,'summary'=>$summary,'next_after'=>$result === [] ? $after : $result[array_key_last($result)]['row'],'review_complete'=>$summary['pendiente']===0,'has_more_review'=>$result!==[] && $this->repository->reviewRows($id,$result[array_key_last($result)]['row'])!==[],'planned'=>$this->repository->plannedSummary($id)];
    }

    private function previewRow(array $row): array
    {
        return ['row'=>(int) $row['fila'],'data'=>json_decode($row['normalizados'] ?? $row['datos'],true,512,JSON_THROW_ON_ERROR),'state'=>$row['estado'],'review'=>$row['revision'] ? json_decode($row['revision'],true,512,JSON_THROW_ON_ERROR) : null,
            'decision'=>$row['decision'] ? json_decode($row['decision'],true,512,JSON_THROW_ON_ERROR) : null];
    }

    public function decisions(string $id, array $decisions): void
    {
        Authorization::requireOperation('persons.import');
        if (count($decisions)>100) throw new InvalidArgumentException('Decide hasta 100 filas por operación.');
        $this->repository->acquire($id);
        try {
            $import=$this->repository->get($id);
            if (!in_array($import['estado'],['revision','revisado'],true)) throw new HttpError('La importación ya fue confirmada.',409);
            foreach ($decisions as $decision) {
                if (!is_array($decision)) throw new InvalidArgumentException('Decisión inválida.');
                $number=$this->normalizeRequiredInt($decision['row'] ?? null,'Fila inválida.');
                $row=$this->repository->rows($id,$number-1,1)[0] ?? null;
                if (!$row || (int) $row['fila']!==$number || !in_array($row['estado'],['listo','decision'],true)) throw new InvalidArgumentException('La fila no está disponible para decidir.');
                $action=$decision['action'] ?? '';
                if (!in_array($action,['new','existing','fill','skip'],true)) throw new InvalidArgumentException('Acción inválida.');
                $review=json_decode($row['revision'],true,512,JSON_THROW_ON_ERROR);
                if ($action==='new' && $review['blocked']) throw new HttpError('La regla de identidad impide crear esa fila como nueva.',409);
                if (in_array($action,['existing','fill'],true)) {
                    if (!empty($decision['person'])) {
                        $person=$this->identity->detail($decision['person']);
                        $organization=(new PersonIdentityRepository($this->repository->connection()))->organization($this->uuidStringToBinary($person['organization']));
                        if ($organization!==(int) $import['organizacion'] || !in_array($decision['person'],array_column($review['matches'],'id'),true)) throw new HttpError('La persona seleccionada no corresponde a una coincidencia.',404);
                    } else {
                        $source=$this->normalizeRequiredInt($decision['source_row'] ?? null,'Selecciona una coincidencia.');
                        if (!in_array($source,array_map('intval',array_column($review['file_matches'],'fila')),true)) throw new InvalidArgumentException('Fila de origen inválida.');
                    }
                }
                $this->repository->updateRow($id,$number,['decision'=>json_encode($decision,JSON_THROW_ON_ERROR),'estado'=>'listo']);
            }
        } finally { $this->repository->release($id); }
    }

    public function confirm(string $id): array
    {
        Authorization::requireOperation('persons.import');
        $this->repository->acquire($id);
        try {
            $import=$this->repository->get($id);
            $summary=$this->repository->summary($id);
            if (in_array($import['estado'], ['confirmado', 'terminado'], true)) return $summary;
            if ($import['estado']!=='revisado' || $summary['pendiente'] || $summary['decision']) throw new HttpError('Revisa todas las filas y resuelve las coincidencias antes de confirmar.',409);
            $this->repository->updateImport($id,['estado'=>'confirmado']);
            return $summary;
        } finally { $this->repository->release($id); }
    }

    public function apply(string $id): array
    {
        Authorization::requireOperation('persons.import');
        $this->repository->acquire($id);
        $db=$this->repository->connection();
        try {
            $import=$this->repository->get($id);
            if (!in_array($import['estado'],['confirmado','terminado'],true)) throw new HttpError('Confirma la revisión antes de importar.',409);
            $first = $this->repository->firstPending($id, true);
            $results=[];
            if ($first) foreach ($this->repository->rows($id,(int) $first-1) as $row) {
                if (!in_array($row['estado'],['listo','invalido'],true)) continue;
                $decision=$row['decision'] ? json_decode($row['decision'],true,512,JSON_THROW_ON_ERROR) : ['action'=>'new'];
                $state='error'; $result=[];
                $db->beginTransaction();
                try {
                    if ($row['estado']==='invalido') { $state='rechazado'; $result=['message'=>json_decode($row['revision'],true)['error']]; }
                    elseif ($decision['action']==='skip') { $state='omitido'; }
                    else {
                        $data=json_decode($row['normalizados'],true,512,JSON_THROW_ON_ERROR);
                        if ($decision['action']==='new') {
                            $organizationUuid = $this->repository->organizationUuid((int) $import['organizacion']);
                            $created=$this->identity->create([...$data,'organization'=>$this->uuidBinaryToString($organizationUuid),'confirm_duplicate'=>true],(bool) $import['detectar_duplicados']);
                            if (!$created['created']) { $state='rechazado'; $result=['message'=>'Ya existe una persona con el identificador; la regla estricta sigue activa.']; }
                            else { $state='nuevo'; $result=['person'=>$created['uuid'],'code'=>$created['code']]; }
                        } else {
                            $person=$decision['person'] ?? ($this->repository->sourceResult($id,(int) $decision['source_row'])['person'] ?? null);
                            if (!$person) throw new InvalidArgumentException('La fila seleccionada no produjo una persona.');
                            $detail=$this->identity->detail($person);
                            $orgId=(new PersonIdentityRepository($db))->organization($this->uuidStringToBinary($detail['organization']));
                            if ($orgId!==(int) $import['organizacion']) throw new HttpError('La persona no pertenece a esta organización.',404);
                            if ($decision['action']==='fill') $this->identity->update($person,$data,true);
                            $state=$decision['action']==='fill' ? 'completado' : 'vinculado';
                            $result=['person'=>$person,'code'=>$detail['code']];
                        }
                    }
                    $this->repository->updateRow($id,(int) $row['fila'],['estado'=>$state,'resultado'=>json_encode($result,JSON_THROW_ON_ERROR)]);
                    $db->commit();
                } catch (InvalidArgumentException|HttpError $exception) {
                    if ($db->inTransaction()) $db->rollBack();
                    if ($exception instanceof HttpError && $exception->statusCode===403) throw $exception;
                    $state='rechazado'; $result=['message'=>$exception->getMessage()];
                    $this->repository->updateRow($id,(int) $row['fila'],['estado'=>$state,'resultado'=>json_encode($result,JSON_THROW_ON_ERROR)]);
                } catch (\Throwable $exception) {
                    if ($db->inTransaction()) $db->rollBack();
                    error_log((string) $exception);
                    $result=['message'=>'No se pudo procesar esta fila.']; $state='error';
                    $this->repository->updateRow($id,(int) $row['fila'],['estado'=>$state,'resultado'=>json_encode($result,JSON_THROW_ON_ERROR)]);
                }
                $results[]=['row'=>(int) $row['fila'],'state'=>$state,...$result];
            }
            $summary=$this->repository->summary($id);
            $complete=$summary['listo']===0 && $summary['invalido']===0;
            if ($complete) $this->repository->updateImport($id,['estado'=>'terminado']);
            return ['rows'=>$results,'summary'=>$summary,'complete'=>$complete];
        } finally { if ($db->inTransaction()) $db->rollBack(); $this->repository->release($id); }
    }

    public function automatic(string $id): array
    {
        Authorization::requireOperation('persons.import');
        $this->repository->acquire($id);
        $db=$this->repository->connection();
        try {
            $import=$this->repository->get($id);
            if ($import['modo']!=='automatic' || !in_array($import['estado'],['revision','revisado'],true)) throw new HttpError('La importación no está disponible para altas automáticas.',409);
            $permissions=new \App\Repositories\PermissionRepository($db);
            $permissions->require(\App\Core\Auth::id() ?? 0,'personas.crear',\App\Core\Auth::processId());
            $view=$permissions->require(\App\Core\Auth::id() ?? 0,'personas.ver',\App\Core\Auth::processId());
            if (!$view['super'] && !in_array('organizacion',$view['scopes']['personas.ver'],true)) throw new HttpError('Las altas automáticas requieren consultar el padrón de la organización para separar todas las coincidencias.',403);
            $pending=$this->repository->firstPending($id);
            if ($pending!==null) {
                $review=$this->reviewBatch($id,$import,$pending-1);
                return ['phase'=>'review','complete'=>false,'total'=>(int)$import['total'],'summary'=>$review['summary']];
            }
            // El archivo completo se revisa antes de crear: también se aparta la primera de las filas coincidentes.
            $organization=$this->uuidBinaryToString($this->repository->organizationUuid((int)$import['organizacion']));
            foreach ($this->repository->automaticRows($id) as $row) {
                $db->beginTransaction();
                try {
                    $this->repository->get($id); // Revalida permiso efectivo y alcance, incluso entre lotes.
                    $data=json_decode($row['normalizados'],true,512,JSON_THROW_ON_ERROR);
                    // Reinspección bajo el bloqueo de identidad: una coincidencia sobrevenida jamás se confirma sola.
                    $created=$this->identity->create([...$data,'organization'=>$organization],true);
                    $changes=$created['created']
                        ? ['estado'=>'nuevo','resultado'=>json_encode(['person'=>$created['uuid'],'code'=>$created['code']],JSON_THROW_ON_ERROR)]
                        : ['estado'=>'decision','revision'=>json_encode([...$created,'file_matches'=>[]],JSON_THROW_ON_ERROR)];
                    $this->repository->updateRow($id,(int)$row['fila'],$changes);
                    $db->commit();
                } catch (InvalidArgumentException|HttpError $error) {
                    if ($db->inTransaction()) $db->rollBack();
                    if ($error instanceof HttpError && $error->statusCode===403) throw $error;
                    $this->repository->updateRow($id,(int)$row['fila'],['estado'=>'invalido','revision'=>json_encode(['error'=>$error->getMessage()],JSON_THROW_ON_ERROR)]);
                } catch (\Throwable $error) {
                    if ($db->inTransaction()) $db->rollBack();error_log((string)$error);
                    $this->repository->updateRow($id,(int)$row['fila'],['estado'=>'error','revision'=>json_encode(['error'=>'No se pudo procesar esta fila. Revisa el resultado.'],JSON_THROW_ON_ERROR)]);
                }
            }
            $summary=$this->repository->summary($id);
            $complete=$summary['listo']===0;
            return ['phase'=>'create','complete'=>$complete,'total'=>(int)$import['total'],'summary'=>$summary];
        } finally {if($db->inTransaction())$db->rollBack();$this->repository->release($id);}
    }

    public function results(string $id, int $after): array
    {
        Authorization::requireOperation('persons.import');
        $this->repository->get($id);
        $rows = $this->repository->rows($id, max(0, $after));
        $results = array_map(static function (array $row): array {
            return ['row' => (int) $row['fila'], 'state' => $row['estado'],
                'result' => $row['resultado'] ? json_decode($row['resultado'], true, 512, JSON_THROW_ON_ERROR) : null];
        }, $rows);
        return ['rows' => $results, 'next_after' => $rows === [] ? $after : (int) $rows[array_key_last($rows)]['fila'], 'has_more' => count($rows) === 100];
    }

    public function status(string $id): array
    {
        Authorization::requireOperation('persons.import');
        $import=$this->repository->get($id);
        return ['id'=>$id,'state'=>$import['estado'],'total'=>(int) $import['total'],'mode'=>$import['modo'],'summary'=>$this->repository->summary($id),'planned'=>$this->repository->plannedSummary($id)];
    }
}
