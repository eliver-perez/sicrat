<?php

// Se ejecuta después de jornada-rules.php, exclusivamente en su base desechable.
use App\Services\Common\Values;

$mediaStart=$checks;
$mediaImage=$temporaryDirectory.'/acta-media.png';
$image=imagecreatetruecolor(2100,1100);
imagefill($image,0,0,imagecolorallocate($image,32,110,160));
imagepng($image,$mediaImage);
imagejpeg($image,$temporaryDirectory.'/acta-cambio.jpg',85);
unset($image);
file_put_contents($temporaryDirectory.'/acta-falsa.png','No es una imagen');
file_put_contents($temporaryDirectory.'/acta-grande.png',str_repeat('a',10485761));
function mediaUpload(string $draft,string $client='rules-0',string $name='acta-media.png',?string $source=null,array $extra=[],?string $csrf=null): array {
    global $baseUrl,$temporaryDirectory,$phase3Tokens,$adminToken,$mediaImage;
    $curl=curl_init($baseUrl.'/api/jornada/drafts/'.$draft.'/photo');
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
        CURLOPT_COOKIEFILE=>$temporaryDirectory.'/'.$client.'.cookies',
        CURLOPT_HTTPHEADER=>['X-CSRF-Token: '.($csrf ?? $phase3Tokens[$client] ?? $adminToken)],
        CURLOPT_POSTFIELDS=>['file'=>new CURLFile($source ?? $mediaImage,'application/octet-stream',$name),...$extra]]);
    $body=curl_exec($curl);
    return ['status'=>curl_getinfo($curl,CURLINFO_HTTP_CODE),'json'=>json_decode($body,true),'body'=>$body];
}
$mediaBooths=[];
foreach (['Acta backend','Acta navegador','Acta sin asignación'] as $name) {
    $mediaBooths[]=rulesApi('jornada/booths','POST',['seccion'=>$rulesSection,'nombre'=>$name,'tipo'=>'BASICA'])['uuid'];
}
rulesApi('jornada/assignments','PUT',['usuarios'=>[$rulesUsers[0]],'casillas'=>array_slice($mediaBooths,0,2),'activo'=>1]);
// El caso positivo no tiene permisos genéricos de archivos ni de corrección.
phase3Grant($rulesUsers[0],'resultados.corregir',$rulesElection,'propio',0);
$me=rulesApi('auth/me','GET',null,'rules-0');
verify(!in_array('archivos.subir',$me['rights'],true) && !in_array('archivos.ver',$me['rights'],true) && !in_array('resultados.corregir',$me['rights'],true), 'Capturista de prueba sin permisos adicionales de archivos o corrección');
$data=rulesCapture($mediaBooths[0]);
$draft=$data['borrador'];
verify(mediaUpload($draft,'rules-0','acta.png',null,[],'incorrecto')['status']===403,'El endpoint de acta exige CSRF');
verify(mediaUpload($draft,'rules-1')['status']===404,'Un usuario distinto no puede cargar en el borrador ajeno');
verify(mediaUpload($draft,'anonymous')['status']===401,'El endpoint no admite cargas anónimas');
verify(mediaUpload($draft,'rules-0','acta.png',null,['process'=>$rulesProcess])['status']===422,'La carga no admite sustituir la elección de sesión');
verify(mediaUpload($draft,'rules-0','acta.php')['status']===422,'Se conserva la validación segura de extensión');
verify(mediaUpload($draft,'rules-0','acta.png',$temporaryDirectory.'/acta-falsa.png')['status']===422,'Se comprueba que el contenido sea una imagen real');
verify(mediaUpload($draft,'rules-0','acta.png',$temporaryDirectory.'/acta-grande.png')['status']===422,'Se conserva el límite actual de tamaño');
rulesApi('jornada/assignments','PUT',['usuarios'=>[$rulesUsers[0]],'casillas'=>[$mediaBooths[0]],'activo'=>0]);
verify(mediaUpload($draft)['status']===403,'Retirar la asignación deniega cargar incluso sobre un borrador previo');
rulesApi('jornada/assignments','PUT',['usuarios'=>[$rulesUsers[0]],'casillas'=>[$mediaBooths[0]],'activo'=>1]);
phase3Grant($rulesUsers[0],'resultados.capturar',$rulesElection,'propio',0);
verify(mediaUpload($draft)['status']===403,'Revocar captura bloquea el endpoint de fotografía');
phase3Grant($rulesUsers[0],'resultados.capturar',$rulesElection,'propio');
rulesApi('organizations/'.$organizationIds[0].'/modules/jornada_electoral','PUT',['selected'=>0]);
verify(mediaUpload($draft)['status']===403,'El módulo deshabilitado impide subir actas');
rulesApi('organizations/'.$organizationIds[0].'/modules/jornada_electoral','PUT',['selected'=>1]);
$uploaded=mediaUpload($draft);
verify($uploaded['status']===200,'La captura autoriza el acta sin archivos.subir: '.$uploaded['body']);
$photo=$uploaded['json']['data'];
verify($photo['mime']==='image/webp' && $photo['width']===1920 && !isset($photo['path']),'Se procesa a WebP sin exponer rutas físicas');
$statement=$connection->prepare('SELECT a.ruta,a.id,b.proceso,b.usuario FROM archivos a JOIN jornada_borradores b ON b.id=a.entidad_id WHERE a.uuid=?');
$statement->execute([Values::binary($photo['uuid'])]);
$stored=$statement->fetch(PDO::FETCH_ASSOC);
verify(is_file($temporaryDirectory.'/private/'.$stored['ruta']) && (int)$stored['proceso']===$rulesProcess && (int)$stored['usuario']===$rulesUserIds[0], 'El archivo se guarda en almacenamiento privado con elección y propietario correctos');
$version=rulesApi('jornada/captures','POST',[...$data,'foto'=>$photo['uuid']],'rules-0');
$statement=$connection->prepare('SELECT foto FROM jornada_versiones WHERE uuid=?');
$statement->execute([Values::binary($version['uuid'])]);
verify((int)$statement->fetchColumn()===(int)$stored['id'],'La versión queda vinculada a la fotografía');
verify(mediaUpload($draft)['status']===403,'No se puede alterar el acta enviada');
phase3Grant($rulesUsers[0],'resultados.corregir',$rulesElection,'propio');
$correctionKey=Values::uuid();
$correctionDraft=rulesApi('jornada/drafts','POST',['casilla'=>$mediaBooths[0],'clave'=>$correctionKey,'correccion'=>1],'rules-0')['uuid'];
$correctionPhoto=mediaUpload($correctionDraft);
verify($correctionPhoto['status']===200,'Una corrección autorizada también adjunta una imagen nueva sin archivos.subir');
$correction=rulesApi('jornada/corrections','POST',[
    'casilla'=>$mediaBooths[0],'clave'=>$correctionKey,'borrador'=>$correctionDraft,
    'votos'=>[$rulesOption=>101],'foto'=>$correctionPhoto['json']['data']['uuid'],
    'motivo'=>'Acta corregida con imagen nueva','actual_esperada'=>null,
],'rules-0');
verify($correction['numero']===2,'La corrección con foto propia tampoco exige archivos.ver');
verify(request('anonymous','GET','/media/'.$photo['uuid'])['status']===401,'La fotografía no es pública');
verify(request('rules-0','GET','/media/'.$photo['uuid'])['status']===403,'La carga no amplía los permisos de lectura de media');
$media=request('admin','GET','/media/'.$photo['uuid'].'?size=thumb');
verify($media['status']===200 && str_contains($media['headers']['cache-control'] ?? '','no-store') && str_starts_with($media['headers']['content-type'] ?? '','image/webp'), 'La miniatura guardada sigue usando media privado y sin caché');
// Un borrador de la misma cuenta tampoco puede utilizarse en otra elección.
$otherDraft=rulesApi('jornada/drafts','POST',['casilla'=>$mediaBooths[2],'clave'=>Values::uuid()])['uuid'];
rulesApi('auth/election','POST',['process'=>$processIds[0]]);
verify(mediaUpload($otherDraft,'admin')['status']===404,'Un borrador propio de otra elección no se abre aunque el actor sea Superadministrador');
rulesApi('auth/election','POST',['process'=>$rulesElection]);
// La cuenta no obtiene permiso de archivos genérico como efecto lateral.
$generic=request('rules-0','POST','/api/files',['entity'=>'personas','record'=>1],$phase3Tokens['rules-0']);
verify($generic['status']===403,'La autorización global de archivos conserva archivos.subir');
// Seleccionar una imagen aún no crea una versión; se comprueba además en Chrome.
file_put_contents($temporaryDirectory.'/jornada-media.json',json_encode([
    'election'=>$rulesElection,'booth'=>$mediaBooths[1],'forbidden'=>$mediaBooths[2],
    'image'=>basename($mediaImage),'changed'=>'acta-cambio.jpg',
],JSON_THROW_ON_ERROR));
echo 'Actas Jornada: '.($checks-$mediaStart)." comprobaciones HTTP/BD de autorización y media.\n";
