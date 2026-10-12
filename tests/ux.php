<?php

// Sólo se incluye desde la suite sobre su base desechable.
$uxStart=$checks;
$names=phase4Api('/api/audit?action=prueba_fase4&organization=1&process=1');
verify($names['rows'][0]['organization_name']==='Cliente A' && $names['rows'][0]['process_name']==='Proceso 1','Auditoría conserva contexto y añade nombres legibles.');
verify(!empty($names['rows'][0]['user_name']) && !empty($names['rows'][0]['username']),'Actor de auditoría identificado por nombre y cuenta.');
$options=phase4Api('/api/audit/options?kind=user&limit=1&organization=1&process=1');
verify(count($options['rows'])===1 && isset($options['rows'][0]['value'],$options['rows'][0]['label']),'Opciones paginadas transmiten ID interno y etiqueta de negocio.');
verify(array_keys($options['rows'][0])===['value','label'],'Las opciones no exponen secretos ni cuentas completas.');
$organizations=phase4Api('/api/audit/options?kind=organization&process=all');
verify(count($organizations['rows'])>=1,'Super Administrador consulta nombres de organizaciones autorizadas.');
$clientOptions=phase4Api('/api/audit/options?kind=organization','GET',null,'phase4-manager');
verify(array_reduce($clientOptions['rows'],fn ($valid,$row)=>$valid && (int) $row['value']===1,true),'Cliente sólo recibe opciones de su organización.');
phase4Api('/api/audit/options?kind=user','GET',null,'capture',403);
phase4Api('/api/audit/options?kind=user&organization=2','GET',null,'phase4-manager',404);
phase4Api('/api/audit/options?kind=tabla_invalida','GET',null,'admin',422);
$empty=phase4Api('/api/audit/options?kind=user&search='.rawurlencode("' OR 1=1 --"));
verify($empty['rows']===[],'Búsqueda del selector usa parámetros y respeta aislamiento.');
phase4Api('/api/audit/options?kind=user&search='.str_repeat('a',101),'GET',null,'admin',422);
$roleOrigins=phase4Api('/api/roles/2/permissions');
verify(count($roleOrigins)>0 && array_key_exists('contexto',$roleOrigins[0]),'Origen del rol distingue plantilla electoral y configuración global sin inferirlo del ID.');
echo 'UX: '.($checks-$uxStart)." comprobaciones HTTP/BD adicionales correctas.\n";
