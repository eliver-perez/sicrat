<?php
// Fixtures and assertions run only against integration.php's disposable database.
$uiuxStart=$checks;
$connection->prepare("UPDATE personas SET nombre='Éliver',paterno='Pérez',materno='López' WHERE id=?")->execute([$personA]);
$code=$connection->query('SELECT codigo_persona FROM personas WHERE id='.$personA)->fetchColumn();
foreach(['eliv','PEREZ','pérez lóp',strtolower($code)] as $search) {
    $people=dashboardApi('/api/persons?search='.rawurlencode($search),client:'a');
    verify(in_array($dashboardPeople['a'],array_column($people['persons'],'id'),true),'Partial names, accents, case and code find the authorized person.');
    $options=dashboardApi('/api/operations/tareas/people?action=crear&search='.rawurlencode($search));
    verify(str_contains(json_encode($options),$dashboardPeople['a']),'Operational selection supports the same person search.');
}
$hidden=dashboardApi('/api/persons?search=Dashboard%20C',client:'a');
verify(!$hidden['persons'],'Search still excludes the other branch.');
$access=dashboardApi('/api/users/'.$dashboardUsers['a'].'/accesses');
verify($access['person_name']==='Éliver Pérez López' && $access['person_code']===$code,'Linked person includes full name and code.');
$activity=dashboardApi('/api/dashboard',client:'a')['activity'];
verify((bool)array_filter($activity,fn($row)=>($row['subject']??'')==='Preparar equipo Dashboard' && str_starts_with($row['url']??'','operations/?module=tareas&record=')),'Activity includes an authorized subject and detail link.');
file_put_contents($temporaryDirectory.'/uiux.json',json_encode(['election'=>$dashboardElection,'person'=>$dashboardPeople['a'],'user'=>$dashboardUsers['a'],'structure'=>$dashboardStructure,'code'=>$code]));
echo 'UI/UX PHP: '.($checks-$uiuxStart)." comprobaciones.\n";
