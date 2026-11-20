<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
use App\Auth\Authorization;
use App\Repositories\PersonsRepository;
use App\Repositories\OrganizationsRepository;
use App\Repositories\SectionsRepository;
use App\Repositories\SettingsRepository;
use App\Services\SettingsService;
use InvalidArgumentException;
use RuntimeException;

class PersonsService extends Service
{
    public function __construct(
        private PersonsRepository $personsRepository,
        private OrganizationsRepository $organizationsRepository,
        private SectionsRepository $sectionsRepository,
        private SettingsRepository $settingsRepository
    ) {
    }

    public function getAll(array $data): array {
        try {
            $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');
            $search = $this->normalizeOptionalText($data['search'] ?? null);
            $section = $this->normalizeOptionalText($data['section'] ?? null);
            
            if($section === null || $section === 'N/A') {
                $sectionId = null;
            } else {
                $sectionId = $this->uuidStringToBinary($section);
            }

            $limit = $this->normalizeOptionalInt($data['limit'] ?? 25);
            $offset = $this->normalizeOptionalInt($data['offset'] ?? 0);

            $persons_data = $this->personsRepository->getAll([
                'search'                        => $search,
                'section'                       => $sectionId,
                'limit'                         => $limit,
                'offset'                        => $offset
            ]);
            $persons = array();

            if(count($persons_data) > 0) {
                foreach($persons_data as $d) {
                    array_push($persons, array(
                        'id'                        => $this->uuidBinaryToString($d['uuid']),
                        'section'                   => $d['seccion'] === null ? '' : str_pad((string)$d['seccion'], 4, '0', STR_PAD_LEFT),
                        'code'                      => $d['codigo_persona'],
                        'phone'                     => $d['telefono'],
                        'alternate_phone'           => $d['telefono_alternativo'],
                        'email'                     => $d['email'],
                        'gender'                    => $d['genero'],
                        'birth_date'                => $d['f_nacimiento'],
                        'name'                      => $d['nombre'],
                        'registered_by'             => $d['registro'],
                        'registered_date'           => $d['f_registro'],
                    ));
                }
            }

            return $persons;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function map(array $query): array
    {
        $process = \App\Core\Auth::processId();
        if (!$process) throw new InvalidArgumentException('Selecciona una elección para consultar el mapa.');
        $db = $this->personsRepository->getConnection();
        $statement = $db->prepare('SELECT organizacion FROM procesos_electorales WHERE id=? AND estatus=1');
        $statement->execute([$process]);
        $organization = (int)$statement->fetchColumn();
        Authorization::assertOrganization($organization);
        foreach (['structure','branch','extent'] as $parameter) {
            if (isset($query[$parameter]) && !is_string($query[$parameter])) throw new InvalidArgumentException('El filtro del mapa no es válido.');
        }
        $view = $query['view'] ?? '';
        $structure = $this->normalizeOptionalText($query['structure'] ?? null);
        $structureScope = $structure !== null ? (new StructureService(new \App\Repositories\StructureRepository($db)))->mapScope(
            $structure,$this->normalizeOptionalText($query['branch'] ?? null),(string)($query['extent'] ?? 'all'),$organization,$process
        ) : null;
        if ($view === 'initial') {
            (new \App\Repositories\PermissionRepository($db))->require(\App\Core\Auth::id(), 'personas.ver', $process);
            return (new \App\Repositories\PersonIdentityRepository($db))->mapSettings($organization);
        }
        if ($view === 'branches' && $structureScope) {
            $search = trim((string)($query['search'] ?? ''));
            if (mb_strlen($search)>200) throw new InvalidArgumentException('La búsqueda no puede exceder 200 caracteres.');
            $page = \App\Core\Pagination::offset($query['offset'] ?? 0);
            $rows = $this->personsRepository->mapBranches($structureScope,$organization,$search,$page);
            return [
                'rows'=>array_map(fn ($row) => ['value'=>$this->uuidBinaryToString($row['uuid']),'label'=>$row['code'].' · '.$row['name'],'name'=>$row['name']],array_slice($rows,0,25)),
                'pagination'=>\App\Core\Pagination::response($rows,25,$page),
            ];
        }
        $data = [];
        foreach (['south'=>90,'north'=>90,'west'=>180,'east'=>180] as $key=>$bound) {
            $value = filter_var($query[$key] ?? null, FILTER_VALIDATE_FLOAT);
            if ($value === false || abs($value)>$bound) throw new InvalidArgumentException('La zona del mapa no es válida.');
            $data[$key] = $value;
        }
        if ($data['south']>$data['north'] || $data['west']>$data['east']) throw new InvalidArgumentException('La zona del mapa no es válida.');
        $data['rows'] = max(1,min(16,(int)($query['rows'] ?? 8)));
        $data['columns'] = max(1,min(24,(int)($query['columns'] ?? 12)));
        $data['list'] = $view === 'people';
        $data['offset'] = max(0,(int)($query['offset'] ?? 0));
        $data['search'] = $this->normalizeOptionalText($query['search'] ?? null);
        if (mb_strlen($data['search'] ?? '')>200) throw new InvalidArgumentException('La búsqueda no puede exceder 200 caracteres.');
        $section = $this->normalizeOptionalText($query['section'] ?? null);
        $data['section'] = !$section || $section==='N/A' ? null : $this->uuidStringToBinary($section);
        $rows = $this->personsRepository->mapRows($data,$organization,$process,$structureScope);
        $more = $data['list'] && count($rows)>25;
        if ($more) array_pop($rows);
        $points = [];
        foreach ($rows as $row) {
            $point = ['latitude'=>(float)$row['latitude'],'longitude'=>(float)$row['longitude'],'count'=>(int)$row['quantity']];
            if ($point['count']===1) {
                $point += ['id'=>$this->uuidBinaryToString($row['uuid']),'name'=>$row['name'],'code'=>$row['code'],'section'=>$row['section']];
                if ($structureScope) $point['level'] = $row['level_name'];
            } else {
                $point['bounds'] = array_map('floatval',array_intersect_key($row,array_flip(['south','north','east','west'])));
            }
            $points[] = $point;
        }
        return ['points'=>$points,'has_more'=>$more];
    }

    public function create(array $data): ?array {
        return (new PersonIdentityService(new \App\Repositories\PersonIdentityRepository($this->personsRepository->getConnection())))->create($data);
    }
}
