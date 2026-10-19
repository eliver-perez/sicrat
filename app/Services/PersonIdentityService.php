<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Authorization;
use App\Core\Auth;
use App\Core\HttpError;
use App\Core\PersonCode;
use App\Core\Service;
use App\Repositories\PersonIdentityRepository;
use App\Repositories\ElectoralContextRepository;
use InvalidArgumentException;
use PDOException;

class PersonIdentityService extends Service
{
    // Lista cerrada: ni el alta ni la edición aceptan claves arbitrarias del cliente.
    public const FIELDS = [
        'name' => ['nombre',80], 'last_name' => ['paterno',80], 'last_name_2' => ['materno',80],
        'birth_date' => ['f_nacimiento',10], 'gender' => ['genero',1], 'phone' => ['telefono',20],
        'alternate_phone' => ['telefono_alternativo',20], 'email' => ['email',150],
        'curp' => ['curp',18], 'elector_key' => ['clave_elector',30], 'ocr' => ['ocr',30], 'section' => ['seccion',36],
    ];
    public const ADDRESS_FIELDS = ['street' => ['calle',150], 'exterior_number' => ['numero_exterior',20],
        'interior_number' => ['numero_interior',20], 'postal_code' => ['codigo_postal',10], 'references' => ['referencias',255],
        'neighborhood' => ['colonia',10], 'latitude' => ['latitud',12], 'longitude' => ['longitud',12]];

    public function __construct(private PersonIdentityRepository $repository, private PersonCode $codes = new PersonCode()) {}

    public function organization(?string $uuid): int
    {
        if ($uuid !== null && $uuid !== '') return $this->repository->organization($this->uuidStringToBinary($uuid));
        $organization = Authorization::scopeOrganizationId();
        if ($organization === null) throw new InvalidArgumentException('Selecciona una organización.');
        return $organization;
    }

    public function normalize(array $data, bool $partial = false): array
    {
        $fields = [];
        foreach (self::FIELDS as $key => [$column, $length]) {
            if ($partial && !array_key_exists($key, $data)) continue;
            $value = $data[$key] ?? null;
            if ($value !== null && !is_string($value) && !is_numeric($value)) throw new InvalidArgumentException('El campo ' . $key . ' debe contener texto.');
            $value = $value === null ? null : trim((string) $value);
            if ($value === '' || ($key === 'section' && $value === 'N/A')) $value = null;
            if ($value !== null && (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $length || str_contains($value, "\0"))) throw new InvalidArgumentException('El campo ' . $key . ' tiene longitud o formato inválido.');
            if ($value !== null && in_array($key, ['curp','elector_key','gender'], true)) $value = mb_strtoupper($value);
            if ($key === 'email' && $value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('El correo electrónico no es válido.');
            if ($key === 'birth_date' && $value !== null) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value || (int) $date->format('Y') < 1000) throw new InvalidArgumentException('La fecha debe ser válida y tener formato AAAA-MM-DD.');
            }
            if ($key === 'section' && $value !== null) {$value = $this->repository->sectionId($value);if(\App\Core\Auth::processId())(new GeographyService(new \App\Repositories\CommonRepository($this->repository->connection())))->assertSection($value);}
            if ($key === 'gender' && $value !== null && !$this->repository->genderExists($value)) {
                throw new InvalidArgumentException('El género no es válido.');
            }
            $fields[$column] = $value;
        }
        if ((!$partial || array_key_exists('nombre', $fields)) && ($fields['nombre'] ?? null) === null) throw new InvalidArgumentException('Es necesario capturar el nombre.');
        return $fields;
    }

    private function addressFields(array $data): array
    {
        $fields = [];
        foreach (self::ADDRESS_FIELDS as $key => [$column,$length]) {
            if (!array_key_exists($key, $data)) continue;
            $value = $data[$key];
            if ($value !== null && !is_string($value) && !is_numeric($value)) throw new InvalidArgumentException('El domicilio debe contener texto.');
            $value = $value === null ? null : trim((string) $value);
            if ($value !== null && (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0") || mb_strlen($value) > $length)) throw new InvalidArgumentException('El domicilio supera la longitud permitida.');
            $value = $value === '' ? null : $value;
            if ($key === 'neighborhood' && $value !== null) {
                $value = $this->normalizeRequiredInt($value, 'Selecciona una colonia válida.');
                if (!$this->repository->neighborhoodExists($value)) throw new InvalidArgumentException('La colonia no existe.');
            }
            if (in_array($key, ['latitude', 'longitude'], true) && $value !== null) {
                $bound = $key === 'latitude' ? 90 : 180;
                if (!is_numeric($value) || (float) $value < -$bound || (float) $value > $bound) throw new InvalidArgumentException('Las coordenadas no son válidas.');
                $value = number_format((float) $value, 7, '.', '');
            }
            $fields[$column] = $value;
        }
        return $fields;
    }

    public function inspect(int $organization, array $fields, bool $approximate = true, ?int $exclude = null): array
    {
        $policy = $this->repository->policy($organization, $this->repository->connection()->inTransaction());
        $blocked = false;
        foreach (['curp','clave_elector'] as $column) {
            if (isset($fields[$column]) && $fields[$column] !== '' && (int) $policy[$column . '_unica'] === 1
                && $this->repository->identifierExists($organization, $column, $fields[$column], $exclude)) $blocked = true;
        }
        $matches = $this->repository->matches($organization, $fields, $approximate, $exclude);
        return ['blocked' => $blocked, 'matches' => array_map(function ($person) { return [
            'id' => $this->uuidBinaryToString($person['uuid']), 'code' => $person['codigo_persona'],
            'name' => trim(implode(' ', [$person['nombre'], $person['paterno'] ?? '', $person['materno'] ?? ''])),
        ]; }, $matches), 'policy' => $policy, 'more_matches' => count($matches) > 20];
    }

    public function create(array $data, bool $approximate = true): array
    {
        Authorization::requireOperation('persons.create');
        $organization = $this->organization($data['organization'] ?? null);
        $fields = $this->normalize($data);
        $address = $this->addressFields($data);
        $db = $this->repository->connection();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();
        try {
            // Todos los escritores de identidad y política comparten este bloqueo para evitar carreras.
            $this->repository->policy($organization, true);
            $inspection = $this->inspect($organization, $fields, $approximate);
            if ($inspection['blocked'] || ($inspection['matches'] !== [] && empty($data['confirm_duplicate']))) {
                if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
                return ['created' => false, 'requires_confirmation' => !$inspection['blocked'], ...$inspection];
            }
            $uuid = $this->generateUuidBinary();
            for ($attempt = 0; $attempt < 20; $attempt++) {
                $code = $this->codes->generate();
                try {
                    $id = $this->repository->insert([...$fields, 'uuid' => $uuid, 'codigo_persona' => $code,
                        'organizacion' => $organization, 'registrado_por' => Auth::id()]);
                    break;
                } catch (PDOException $exception) {
                    if (($exception->errorInfo[1] ?? null) !== 1062 || !str_contains($exception->getMessage(), 'uq_personas_codigo') || $attempt === 19) throw $exception;
                }
            }
            if ($address !== [] && array_filter($address) !== []) $this->repository->saveAddress($id, $address);
            if ($ownsTransaction) $db->commit();
            return ['created' => true, 'uuid' => $this->uuidBinaryToString($uuid), 'code' => $code];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    public function detail(string $uuid): array
    {
        Authorization::requireOperation('persons.read');
        $person = $this->repository->person($this->uuidStringToBinary($uuid));
        (new \App\Repositories\ScopeRepository($this->repository->connection()))->assertPerson((int) $person['id'], 'personas.ver', Auth::processId());
        $data = ['id' => $uuid, 'code' => $person['codigo_persona'], 'organization' => $this->uuidBinaryToString($person['organizacion_uuid'])];
        foreach (self::FIELDS as $key => [$column]) $data[$key] = $key === 'section' ? ($person['seccion_uuid'] ? $this->uuidBinaryToString($person['seccion_uuid']) : null) : $person[$column];
        $address = $this->repository->address((int) $person['id']);
        foreach (self::ADDRESS_FIELDS as $key => [$column]) $data[$key] = $address[$column] ?? null;
        if ($data['neighborhood']) {
            $statement = $this->repository->connection()->prepare('SELECT c.colonia neighborhood,l.id locality,l.localidad,m.id municipality,m.municipio,e.id state,e.estado FROM colonias c JOIN localidades l ON l.id=c.localidad JOIN municipios m ON m.id=l.municipio JOIN estados e ON e.id=m.estado WHERE c.id=?');
            $statement->execute([$data['neighborhood']]); $data['location'] = $statement->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        $data['structure'] = (new StructureService(new \App\Repositories\StructureRepository($this->repository->connection())))->personContext((int)$person['id']);
        $data['editable']=$this->personAction((int)$person['id'],'personas.editar',Auth::processId());
        $data['participations'] = array_map(fn ($row) => ['id' => $this->uuidBinaryToString($row['uuid']), 'process' => $row['proceso'], 'active' => (int) $row['activo'], 'process_active' => (int) $row['estatus'], 'can_participate'=>$this->personAction((int)$person['id'],'personas.participar',(int)$row['id'])], $this->repository->participations((int) $person['id']));
        $data['participation_choices']=[];
        $context=new ElectoralContextRepository($this->repository->connection());
        for($offset=0;;$offset+=100){$choices=$context->available(100,$offset);foreach($choices as $row)if((int)$row['organizacion']===(int)$person['organizacion'] && $this->personAction((int)$person['id'],'personas.participar',(int)$row['id']))$data['participation_choices'][]=['id'=>$this->uuidBinaryToString($row['uuid']),'process'=>$row['proceso']];if(count($choices)<100)break;}
        return $data;
    }

    private function personAction(int $person,string $permission,?int $process): bool
    {
        try {(new \App\Repositories\PermissionRepository($this->repository->connection()))->require(Auth::id() ?? 0,$permission,$process);(new \App\Repositories\ScopeRepository($this->repository->connection()))->assertPerson($person,$permission,$process);return true;}
        catch(HttpError $error){if(!in_array($error->statusCode,[403,404],true))throw $error;return false;}
    }

    public function update(string $uuid, array $data, bool $missingOnly = false): array
    {
        Authorization::requireOperation('persons.update');
        $person = $this->repository->person($this->uuidStringToBinary($uuid));
        (new \App\Repositories\ScopeRepository($this->repository->connection()))->assertPerson((int) $person['id'], 'personas.editar', Auth::processId());
        $fields = $this->normalize($data, true);
        $address = $this->addressFields($data);
        $db = $this->repository->connection();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();
        try {
            $this->repository->policy((int) $person['organizacion'], true);
            // Relee después del bloqueo: completar nunca pisa cambios de otro escritor concurrente.
            $person = $this->repository->person($this->uuidStringToBinary($uuid), true);
            if ($missingOnly) {
                $fields = array_filter($fields, fn ($value, $column) => ($person[$column] === null || $person[$column] === '') && $value !== null, ARRAY_FILTER_USE_BOTH);
                $previousAddress = $this->repository->address((int) $person['id'], true);
                $address = array_filter($address, fn ($value, $column) => (!isset($previousAddress[$column]) || $previousAddress[$column] === '') && $value !== null, ARRAY_FILTER_USE_BOTH);
            }
            // Identificadores históricos repetidos pueden conservarse; cada cambio se valida por separado.
            $policy = $this->repository->policy((int) $person['organizacion'], true);
            foreach (['curp', 'clave_elector'] as $column) {
                if (array_key_exists($column, $fields) && $fields[$column] !== null && $fields[$column] !== $person[$column]
                    && (int) $policy[$column . '_unica'] === 1
                    && $this->repository->identifierExists((int) $person['organizacion'], $column, $fields[$column], (int) $person['id'])) {
                    throw new HttpError('Ya existe otra persona con ese identificador.', 409);
                }
            }
            $inspection = $this->inspect((int) $person['organizacion'], [...$person, ...$fields], false, (int) $person['id']);
            $this->repository->update((int) $person['id'], $fields);
            if ($address !== []) $this->repository->saveAddress((int) $person['id'], $address);
            if ($ownsTransaction) $db->commit();
            return ['id' => $uuid, 'changed' => $fields !== [] || $address !== [], 'identity_warnings' => $inspection['matches']];
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $exception;
        }
    }

    public function participation(string $uuid, array $data): void
    {
        Authorization::requireOperation('persons.participate');
        $person = $this->repository->person($this->uuidStringToBinary($uuid));
        $processes = $data['processes'] ?? [];
        if (!is_array($processes) || count($processes) < 1 || count($processes) > 100) throw new InvalidArgumentException('Selecciona entre una y cien elecciones.');
        $active = $this->normalizeStatus($data['active'] ?? 1);
        $context = new ElectoralContextRepository($this->repository->connection());
        $authorized = [];
        foreach ($processes as $process) {
            if (!is_string($process)) throw new InvalidArgumentException('La elección no es válida.');
            $target = $context->authorized($this->uuidStringToBinary($process), (int) $person['organizacion'], $active === 1);
            (new \App\Repositories\ScopeRepository($this->repository->connection()))->assertPerson((int) $person['id'], 'personas.participar', (int) $target['id']);
            $authorized[] = $target;
        }
        $db = $this->repository->connection();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();
        try {
            foreach ($authorized as $process) $this->repository->participate((int) $person['id'], (int) $process['id'], $active);
            if ($ownsTransaction) $db->commit();
        } catch (\Throwable $exception) { if ($ownsTransaction && $db->inTransaction()) $db->rollBack(); throw $exception; }
    }

    public function mapSettings(string $organization, ?array $data = null): array
    {
        Authorization::requireOperation($data===null ? 'persons.read' : 'identity.configure');
        $id=$this->organization($organization);
        if ($data!==null) {
            $settings=[];
            foreach(['latitude'=>90,'longitude'=>180,'zoom'=>19] as $key=>$bound) {
                $value=$data[$key] ?? null;
                if ((!is_string($value)&&!is_numeric($value)) || !is_numeric($value) || !is_finite((float)$value) || (float)$value > $bound || (float)$value < ($key==='zoom' ? 0 : -$bound) || ($key==='zoom' && (float)$value!==(float)(int)$value)) throw new InvalidArgumentException('Centro o zoom del mapa no válido.');
                $settings[$key]=$key==='zoom' ? (int)$value : round((float)$value,7);
            }
            $db=$this->repository->connection();$owns=!$db->inTransaction();if($owns)$db->beginTransaction();
            try {$this->repository->setMapSettings($id,$settings);if($owns)$db->commit();}
            catch(\Throwable $error){if($owns&&$db->inTransaction())$db->rollBack();throw $error;}
        }
        return $this->repository->mapSettings($id);
    }

    public function policy(string $organization, ?array $data = null): array
    {
        Authorization::requireOperation($data === null ? 'persons.read' : 'identity.configure');
        $id = $this->organization($organization);
        $db = $this->repository->connection();
        if ($data !== null) {
            $curp = $this->normalizeStatus($data['curp_unique'] ?? null);
            $key = $this->normalizeStatus($data['elector_key_unique'] ?? null);
            $ownsTransaction = !$db->inTransaction();
            if ($ownsTransaction) $db->beginTransaction();
            try { $this->repository->policy($id, true); $this->repository->setPolicy($id, $curp, $key); if ($ownsTransaction) $db->commit(); }
            catch (\Throwable $exception) { if ($ownsTransaction && $db->inTransaction()) $db->rollBack(); throw $exception; }
        }
        return $this->repository->policy($id);
    }
}
