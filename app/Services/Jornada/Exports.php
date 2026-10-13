<?php

declare(strict_types=1);

namespace App\Services\Jornada;

use App\Core\HttpError;
use App\Repositories\CommonRepository;
use App\Services\Common\ExportRegistry;
use App\Services\Common\ExportWriter;
use App\Services\Common\PdfService;
use App\Services\Common\Values;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

final class Exports
{
    public const DEFINITIONS = [
        'jornada_avance'=>['Avance de Jornada Electoral',['Indicador','Valor']],
        'jornada_candidaturas'=>['Resultados por candidatura',['Candidatura','Cargo','Votos derivados','Porcentaje','Denominador']],
        'jornada_opciones'=>['Resultados por fila del acta',['Opción exacta','Tipo','Votos crudos','Porcentaje','Denominador']],
        'jornada_secciones'=>['Resultados por sección',['Sección','Candidatura','Votos derivados','Modo']],
        'jornada_casillas'=>['Resultados por casilla',['Sección','Casilla','Estado','Versión','Opción exacta','Votos crudos','Modo']],
        'jornada_pendientes'=>['Casillas pendientes de captura',['Sección','Casilla','Capturistas','Estado']],
        'jornada_validacion'=>['Actas pendientes de validación',['Sección','Casilla','Versión','Capturista','Fecha UTC','Total del acta']],
        'jornada_historial'=>['Historial de versiones',['Sección','Casilla','Versión','Origen','Estado','Vigente','Capturista','Fecha UTC','Total','Motivo','Votos crudos']],
        'jornada_asignaciones'=>['Asignaciones de capturistas',['Sección','Casilla','Cuenta','Nombre','Activo','Asignado UTC','Retirado UTC']],
    ];
    public static function register(ExportRegistry $registry,CommonRepository $repository): void
    {
        foreach(self::DEFINITIONS as $type=>[$title,$columns]) {
            $registry->register($type,$title,$columns,static fn($context)=>self::rows($repository,$type,$context),false);
            $registry->writer($type,static fn($format,$path,$descriptor,$rows,$context)=>self::write($repository,$type,$format,$path,$descriptor,$rows,$context));
        }
    }
    public static function filters(string $type,array $filters): array
    {
        if(!isset(self::DEFINITIONS[$type])) return [];
        $mode=$filters['modo'] ?? 'validado';if(!in_array($mode,['preliminar','validado'],true)) throw new \InvalidArgumentException('Modo de informe inválido.');
        $clean=['modo'=>$mode,'estado'=>Values::text($filters['estado'] ?? '',24,true)];
        foreach(['seccion','municipio','distrito_local','distrito_federal'] as $key) if(!empty($filters[$key])) $clean[$key]=Values::integer($filters[$key]);
        if(!empty($filters['usuario'])) $clean['usuario']=Values::uuidText(Values::binary($filters['usuario']));
        if(($filters['mis'] ?? '')==='1') $clean['mis']='1';
        return $clean;
    }
    private static function authorize(CommonRepository $repository,array $context,string $type): Queries
    {
        $query=new Queries($repository);$ctx=$query->context;
        if($ctx->process!==$context['process'] || $ctx->organization!==$context['organization']) throw new HttpError('El informe no corresponde al contexto de trabajo.',404);
        $ctx->require('resultados.exportar');$ctx->require($context['scope_permission'] ?? 'exportaciones.generar');
        $ctx->require($type==='jornada_asignaciones' ? 'jornada.asignar_capturistas' : 'resultados.ver');
        if($type==='jornada_validacion') $ctx->require('resultados.validar');
        if(($context['filters']['modo'] ?? 'validado')==='preliminar') $ctx->require('resultados.ver_preliminares');
        return $query;
    }
    private static function rows(CommonRepository $repository,string $type,array $context): iterable
    {
        $query=self::authorize($repository,$context,$type);$ctx=$query->context;$filters=$context['filters'];
        $permission=$type==='jornada_asignaciones' ? 'jornada.asignar_capturistas' : 'resultados.ver';
        // Intersección de permisos del negocio, exportación específica y servicio común.
        [$base,$parameters]=$query->filter($filters,$permission,'resultados.exportar');
        [$exportScope,$exportParams]=$ctx->predicate($context['scope_permission'] ?? 'exportaciones.generar');
        $base.=" AND ($exportScope)";$parameters=[...$parameters,...$exportParams];
        if($type==='jornada_validacion') { [$reviewScope,$reviewParams]=$ctx->predicate('resultados.validar');$base.=" AND ($reviewScope)";$parameters=[...$parameters,...$reviewParams]; }
        if(($filters['modo'] ?? 'validado')==='preliminar') { [$pre,$preValues]=$ctx->predicate('resultados.ver_preliminares');$base.=" AND ($pre)";$parameters=[...$parameters,...$preValues]; }
        $last=0;$hash=hash_init('sha256');
        do {
            $ids=$repository->rows('SELECT jc.id,r.actual_version,r.revision '.$query->joins()." WHERE $base AND jc.id>? ORDER BY jc.id LIMIT 250",[...$parameters,$last]);
            foreach($ids as $row) { $last=(int)$row['id'];hash_update($hash,Values::json($row)."\n"); }
        } while(count($ids)===250);
        $fingerprint=hash_final($hash);
        $extra=['resultados.exportar',$context['scope_permission'] ?? 'exportaciones.generar'];
        if($type==='jornada_validacion')$extra[]='resultados.validar';
        if(($filters['modo'] ?? 'validado')==='preliminar')$extra[]='resultados.ver_preliminares';
        if(in_array($type,['jornada_avance','jornada_candidaturas','jornada_opciones'],true)) {
            $summary=$query->summary($filters,$extra);
            $fingerprint=hash('sha256',Values::json($summary));
            if($type==='jornada_avance') { foreach($summary['progreso'] as $key=>$value) yield ['source_id'=>$key.':'.$fingerprint,'indicador'=>$key,'valor'=>$value]; }
            else foreach($summary[$type==='jornada_candidaturas' ? 'candidaturas' : 'opciones'] as $row) yield ['source_id'=>$row['id'].':'.$fingerprint,'nombre'=>$row['nombre'],'tipo'=>$row['cargo'] ?? $row['tipo'],'votos'=>$row['votos'],'porcentaje'=>$row['porcentaje'],'denominador'=>$summary['denominador']];
            return;
        }
        if($type==='jornada_secciones') {
            $states=$filters['modo']==='preliminar' ? "('preliminar','validada')" : "('validada')";
            $lastSection=0;$lastCandidate=0;
            do {
                $groups=$repository->rows('SELECT s.id section_id,s.seccion,candidate.id candidate_id,candidate.nombre,SUM(votes.votos) votos '.$query->joins()." JOIN jornada_votos votes ON votes.version=current_v.id JOIN jornada_opciones option_row ON option_row.id=votes.opcion JOIN jornada_candidaturas candidate ON candidate.id=option_row.candidatura WHERE ($base) AND current_v.estado IN $states AND option_row.suma_candidatura=1 GROUP BY s.id,s.seccion,candidate.id,candidate.nombre HAVING s.id>? OR (s.id=? AND candidate.id>?) ORDER BY s.id,candidate.id LIMIT 250",[...$parameters,$lastSection,$lastSection,$lastCandidate]);
                foreach($groups as $row) { $lastSection=(int)$row['section_id'];$lastCandidate=(int)$row['candidate_id'];yield ['source_id'=>$lastSection.':'.$lastCandidate.':'.$fingerprint,'seccion'=>$row['seccion'],'nombre'=>$row['nombre'],'votos'=>(int)$row['votos'],'modo'=>$filters['modo']]; }
            } while(count($groups)===250);
            return;
        }
        $offset=0;
        do {
            $result=$query->listing([...$filters,'limit'=>100,'offset'=>$offset],$permission,$extra);
            foreach($result['rows'] as $booth) {
                if($type==='jornada_pendientes') {
                    if($booth['estado']==='sin_captura') yield ['source_id'=>$booth['id'].':'.$fingerprint,'seccion'=>$booth['seccion'],'casilla'=>$booth['nombre'],'capturistas'=>$booth['capturistas'],'estado'=>'Sin captura'];
                } elseif($type==='jornada_asignaciones') {
                    foreach($repository->rows('SELECT cp.id,u.usuario,u.nombre,cp.activo,cp.fecha,cp.retirado_en FROM jornada_capturistas cp JOIN usuarios u ON u.id=cp.usuario WHERE cp.casilla=? ORDER BY cp.id',[$booth['id']]) as $assignment) { $id=$assignment['id'];unset($assignment['id']);yield ['source_id'=>$id.':'.$fingerprint,'seccion'=>$booth['seccion'],'casilla'=>$booth['nombre'],...$assignment]; }
                } elseif(in_array($type,['jornada_historial','jornada_validacion'],true)) {
                    $last=0;
                    do {
                        $versions=$repository->rows('SELECT v.id,v.uuid FROM jornada_versiones v JOIN jornada_resultados r ON r.id=v.resultado WHERE r.casilla=? AND v.id>?'.($type==='jornada_validacion' ? " AND v.estado='pendiente'" : '').' ORDER BY v.id LIMIT 100',[$booth['id'],$last]);
                        foreach($versions as $version) {
                            $last=(int)$version['id'];$row=$query->version(Values::uuidText($version['uuid']));
                            if($type==='jornada_validacion') yield ['source_id'=>$last.':'.$fingerprint,'seccion'=>$booth['seccion'],'casilla'=>$booth['nombre'],'numero'=>$row['numero'],'capturista'=>$row['capturista'],'fecha'=>$row['fecha'],'total'=>$row['total']];
                            else yield ['source_id'=>$last.':'.$fingerprint,'seccion'=>$booth['seccion'],'casilla'=>$booth['nombre'],'numero'=>$row['numero'],'origen'=>$row['origen'],'estado'=>$row['estado'],'vigente'=>$row['vigente'] ? 'Sí' : 'No','capturista'=>$row['capturista'],'fecha'=>$row['fecha'],'total'=>$row['total'],'motivo'=>$row['motivo'],'votos'=>Values::json(array_map(static fn($vote)=>['opcion'=>$vote['nombre'],'votos'=>$vote['votos']],$row['votos']))];
                        }
                    } while(count($versions)===100);
                } else {
                    if(!$booth['actual_version'] || ($filters['modo']==='validado' && $booth['estado']!=='validada')) continue;
                    $version=$query->version(Values::uuidText($repository->one('SELECT uuid FROM jornada_versiones WHERE id=?',[$booth['actual_version']])['uuid']));
                    if(!$version['visible']) continue;
                    foreach($version['votos'] as $vote) {
                        if($type==='jornada_casillas') yield ['source_id'=>$booth['id'].':'.$vote['uuid'].':'.$fingerprint,'seccion'=>$booth['seccion'],'casilla'=>$booth['nombre'],'estado'=>$booth['estado'],'numero'=>$booth['numero'],'opcion'=>$vote['nombre'],'votos'=>$vote['votos'],'modo'=>$filters['modo']];
                    }
                }
            }
            $offset+=100;
        } while($result['pagination']['has_more']);
    }
    private static function write(CommonRepository $repository,string $type,string $format,string $path,array $descriptor,iterable $rows,array $context): void
    {
        $repository->transaction(fn()=>self::writeSnapshot($repository,$type,$format,$path,$descriptor,$rows,$context));
    }
    private static function writeSnapshot(CommonRepository $repository,string $type,string $format,string $path,array $descriptor,iterable $rows,array $context): void
    {
        $query=self::authorize($repository,$context,$type);$ctx=$query->context;
        $election=$repository->one('SELECT proceso FROM procesos_electorales WHERE id=?',[$ctx->process])['proceso'];
        $config=$repository->one('SELECT estado,zona FROM jornada_config WHERE proceso=?',[$ctx->process]);
        $metadata=['Elección'=>$election,'Estado de Jornada'=>$config['estado'] ?? 'Sin configurar','Modo'=>$context['filters']['modo'],'Zona'=>$config['zona'] ?? 'UTC','Generado UTC'=>gmdate('Y-m-d H:i:s'),'Generador'=>$context['generator'],'Nota'=>'Resultados internos de SICRAT; cada casilla contribuye únicamente con su versión vigente. No constituyen resultados oficiales.'];
        unset($descriptor['writer']);
        if($format==='csv') {
            $mode=$context['filters']['modo']==='preliminar' ? 'Preliminar interno' : 'Validado interno';
            $descriptor['columns']=[...$descriptor['columns'],'Elección','Modo del informe ('.$mode.')','Generado UTC','Nota'];
            $annotated=(static function() use($rows,$election,$mode,$metadata) {
                foreach($rows as $row) yield [...array_values($row),$election,$mode,$metadata['Generado UTC'],$metadata['Nota']];
            })();
            (new ExportWriter())->write($format,$path,$descriptor,$annotated,$context);return;
        }
        $summary=in_array($type,['jornada_avance','jornada_candidaturas','jornada_opciones'],true) ? $query->summary($context['filters'],['resultados.exportar',$context['scope_permission'] ?? 'exportaciones.generar']) : null;
        if($format==='pdf') {
            $images=[];
            try {
                if($summary) { $image=(new \App\Services\Common\PrivateStorage())->temporary();self::chart($summary,$image);$images[]=$image; }
                (new PdfService())->table($path,$descriptor['title'],$descriptor['columns'],$rows,[...$context,'filters'=>[...$context['filters'],...$metadata]],$images);
            } finally { foreach($images as $image) if(is_file($image)) unlink($image); }
            return;
        }
        $writer=new Writer();$writer->openToFile($path);
        try {
            $writer->getCurrentSheet()->setName('Informe');$writer->addRow(Row::fromValues(['Dato','Valor']));
            foreach($metadata as $key=>$value) $writer->addRow(Row::fromValues([$key,ExportWriter::safeCell($value)]));
            $writer->addNewSheetAndMakeItCurrent()->setName('Datos');$writer->addRow(Row::fromValues($descriptor['columns']));
            foreach($rows as $row) $writer->addRow(Row::fromValues(array_map([ExportWriter::class,'safeCell'],array_values($row))));
            if($summary) foreach(['Candidaturas'=>'candidaturas','Filas del acta'=>'opciones'] as $title=>$key) {
                $writer->addNewSheetAndMakeItCurrent()->setName($title);$writer->addRow(Row::fromValues(['Nombre','Votos','Porcentaje','Denominador']));
                foreach($summary[$key] as $row) $writer->addRow(Row::fromValues([ExportWriter::safeCell($row['nombre']),$row['votos'],$row['porcentaje'],$summary['denominador']]));
            }
        } finally { $writer->close(); }
        chmod($path,0600);
    }
    private static function chart(array $summary,string $path): void
    {
        $rows=array_slice($summary['candidaturas'],0,12);$height=max(140,60+count($rows)*28);$image=imagecreatetruecolor(720,$height);$background=imagecolorallocate($image,255,255,255);$ink=imagecolorallocate($image,40,50,70);$bar=imagecolorallocate($image,37,99,235);imagefill($image,0,0,$background);
        imagestring($image,4,14,12,'SICRAT - '.$summary['modo'].' - votos por candidatura',$ink);$maximum=max(1,...array_column($rows,'votos'));
        foreach($rows as $index=>$row) { $y=40+$index*28;$label=iconv('UTF-8','ISO-8859-1//TRANSLIT',mb_substr($row['nombre'],0,28));imagestring($image,3,12,$y,$label,$ink);$width=(int)round(370*$row['votos']/$maximum);imagefilledrectangle($image,250,$y,250+$width,$y+17,$bar);imagestring($image,3,630,$y,(string)$row['votos'],$ink); }
        imagepng($image,$path);unset($image);chmod($path,0600);
    }
}
