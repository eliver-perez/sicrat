<?php

use App\Services\Common\Values;

$transportPosition=['latitude'=>23.6345,'longitude'=>-102.5528];
$transportSection=$phase5Repository->one('SELECT uuid,seccion FROM secciones_electorales ORDER BY id LIMIT 1');
foreach (array_slice($phase5People,0,2) as $person) {
    phase5Api('/api/persons/'.$person,'PUT',$transportPosition+['section'=>Values::uuidText($transportSection['uuid'])]);
}
$transportPaddedSection=str_pad((string) $transportSection['seccion'],4,'0',STR_PAD_LEFT);
$transportMap='transport-map?south=23&north=24&west=-103&east=-102';
$initial=phase5Api('transport-map?view=initial');
verify(isset($initial['longitude'],$initial['latitude'],$initial['zoom']),'Transporte reutiliza la vista inicial de la organización.');
$outsideElection=phase5Api('/api/persons','POST',$transportPosition+['organization'=>$organizationIds[0],'name'=>'Ubicación en otra elección'])['id'];
phase5Api('/api/persons/'.$outsideElection.'/participations','POST',['processes'=>[$processIds[0]]]);
phase5Api('/api/persons','POST',$transportPosition+['organization'=>$organizationIds[1],'name'=>'Ubicación en otra organización']);
$groups=phase5Api($transportMap)['points'];
verify(count($groups)===1 && $groups[0]['count']===2 && !isset($groups[0]['id']),'Mapa sólo agrupa participantes activos de la elección con ubicación registrada.');
$groupArea=http_build_query($groups[0]['bounds']);
$people=phase5Api('transport-map?view=people&'.$groupArea);
verify(array_column($people['points'],'id')===array_slice($phase5People,0,2) && !$people['has_more'],'Domicilios coincidentes se consultan sin omisiones ni duplicados.');
$ana=$people['points'][0];
verify($ana['need']==='requiere' && $ana['coordination']==='coordinada' && $ana['coordinator']==='Bruno Operación'
    && $ana['provider']==='Elena Operación' && (int) $ana['can_open']===1 && (int) $ana['can_coordinate']===1,'El punto devuelve sección, coordinación, proveedor registrado y acciones autorizadas.');
$combined='state=requiere&coordination=coordinada&coordinator='.$phase5People[1].'&section_number='.$transportPaddedSection.'&search=Ana';
verify(array_column(phase5Api('transporte?'.$combined)['rows'],'uuid')===[$phase5People[0]]
    && array_column(phase5Api($transportMap.'&'.$combined)['points'],'id')===[$phase5People[0]]
    && (int) phase5Api('transport-summary?'.$combined)['totals'][0]['cantidad']===1,'Lista, mapa y conteos aplican los mismos filtros combinados.');
verify(array_column(phase5Api($transportMap.'&state=propio&coordinator=none')['points'],'id')===[$phase5People[1]],'Necesidad propia y sin responsable se filtran independientemente.');
$undefined='state=no_definido&coordinator=none';
verify(phase5Api($transportMap.'&'.$undefined)['points']===[]
    && in_array($phase5People[4],array_column(phase5Api('transporte?'.$undefined)['rows'],'uuid'),true)
    && (int) phase5Api('transport-summary?'.$undefined)['totals'][0]['cantidad']>=3,'Las personas sin coordenadas permanecen en lista y conteos.');
verify(array_column(phase5Api('transport-coordinators?search=Bruno')['rows'],'uuid')===[$phase5People[1]],'El selector ofrece responsables asignados de la elección.');
phase5Api('transport-map?south=-91&north=24&west=-103&east=-102','GET',null,'admin',422);
phase5Api('transport-map','GET',null,'admin',422);
verify(request('transport-guest','GET','/api/operations/'.$transportMap)['status']===401,'Mapa requiere sesión.');

phase5Api('/api/persons/'.$outsideElection.'/participations','POST',['processes'=>[$phase5ElectionUuid]]);
$transportReader=phase5Api('/api/users','POST',['organization'=>$organizationIds[0],'username'=>'solo-transporte',
    'password'=>'Alcance de transporte seguro','type'=>8,'name'=>'Lector de logística','person'=>$outsideElection,'process'=>$phase5ElectionUuid])['id'];
phase3Grant($transportReader,'transporte.ver',$phase5ElectionUuid,'propio');
phase3Grant($transportReader,'personas.ver',$phase5ElectionUuid,'propio',0);
phase3Grant($transportReader,'transporte.editar',$phase5ElectionUuid,'propio',0);
$phase3Tokens['transport-reader']=login('transport-reader','solo-transporte','Alcance de transporte seguro');
phase5Api('/api/auth/election','POST',['process'=>$phase5ElectionUuid],'transport-reader');
$readerPoints=phase5Api($transportMap.'&view=people','GET',null,'transport-reader')['points'];
verify(array_column($readerPoints,'id')===[$outsideElection] && !(int) $readerPoints[0]['can_open']
    && !(int) $readerPoints[0]['can_coordinate'],'Alcance propio antes del agrupamiento y permisos independientes para acciones.');
verify((int) phase5Api('transport-summary','GET',null,'transport-reader')['totals'][0]['cantidad']===1,'Resumen restringido al mismo alcance.');
phase5Api('transporte/'.$phase5People[1],'GET',null,'transport-reader',404);
phase5Api('transporte/'.$outsideElection,'PUT',['need'=>'propio'],'transport-reader',403);
phase3Grant($transportReader,'transporte.ver',$phase5ElectionUuid,'propio',0);
phase5Api($transportMap,'GET',null,'transport-reader',403);
phase5Api('/api/persons/'.$outsideElection.'/participations','POST',['processes'=>[$phase5ElectionUuid],'active'=>0]);
