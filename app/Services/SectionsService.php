<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
use App\Auth\Authorization;
use App\Repositories\SectionsRepository;
use App\Repositories\OrganizationsRepository;
use App\Repositories\SettingsRepository;
use App\Services\SettingsService;
use InvalidArgumentException;
use RuntimeException;

class SectionsService extends Service
{
    public function __construct(
        private SectionsRepository $sectionsRepository,
        private OrganizationsRepository $organizationsRepository,
        private SettingsRepository $settingsRepository
    ) {
    }

    public function getAll(array $data): array {
        try {
            $sections_data = $this->sectionsRepository->getAll([
                'search'                        => $data['search'] !== '' ? $data['search'] : null,
                'limit'                         => $data['limit'],
                'offset'                        => $data['offset']
            ]);
            $sections = array();

            if(count($sections_data) > 0) {
                foreach($sections_data as $d) {
                    array_push($sections, array(
                        'id'                        => $this->uuidBinaryToString($d['uuid']),
                        'section'                   => str_pad((string)$d['seccion'], 4, '0', STR_PAD_LEFT),
                        'state'                     => $d['estado'],
                        'municipality'              => $d['municipio'] ?? '',
                        'local_district'            => $d['distrito_local'] ?? '',
                        'federal_district'          => $d['distrito_federal'] ?? '',
                        'registered_by'             => $d['registro'],
                        'registered_date'           => $d['f_registro'],
                    ));
                }
            }

            return $sections;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function create(array $data): ?array {
        Authorization::requireOperation('sections.create');
        $geography=new GeographyService(new \App\Repositories\CommonRepository($this->sectionsRepository->getConnection()));$geography->authorize('secciones',true);
        $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

        $state = $this->normalizeRequiredInt($data['state'] ?? null, 'Es necesario seleccionar un estado.');
        $municipality = $this->normalizeRequiredInt($data['municipality'] ?? null, 'Es necesario seleccionar un municipio.');
        $section = $this->normalizeRequiredInt($data['section'] ?? null, 'Es necesario capturar un número de sección válido.');

        if (!$this->sectionsRepository->municipalityBelongsToState($municipality, $state)) {
            throw new InvalidArgumentException('El municipio no pertenece al estado seleccionado.');
        }
        $conn = $this->sectionsRepository->getConnection();
        $conn->beginTransaction();
        try {
            $geography->lockContext();$geography->authorize('secciones',true);
            if($this->sectionsRepository->sectionExists([
                'section'                               => $section,
                'state'                                 => $state,
                'municipality'                          => $municipality
            ])) {
                $existing=$conn->prepare('SELECT id,uuid,municipio FROM secciones_electorales WHERE estado=? AND seccion=?');$existing->execute([$state,$section]);$row=$existing->fetch(\PDO::FETCH_ASSOC);
                if((int)$row['municipio']!==$municipality)throw new InvalidArgumentException('La sección registrada corresponde a otro municipio. Revisa su ubicación.');
                $assigned=$conn->prepare('SELECT 1 FROM secciones_procesos WHERE proceso=? AND seccion=?');$assigned->execute([\App\Core\Auth::processId(),$row['id']]);
                if(\App\Core\Auth::isSuperAdmin() || $assigned->fetchColumn())throw new InvalidArgumentException("La Sección $section ya se encuentra registrada");
                $geography->attachSection((int)$row['id']);$conn->commit();return ['uuid'=>$this->uuidBinaryToString($row['uuid'])];
            }

            $sectionUuid = $this->generateUuidBinary();
            $sectionId = $this->sectionsRepository->insertSection([
                    'uuid'                          => $sectionUuid,
                    'state'                         => $state,
                    'municipality'                  => $municipality,
                    'section'                       => $section,
                    'local_district'                => null,
                    'federal_district'              => null,
                    'uid'                           => $uid
                ]);

            $geography->attachSection($sectionId);
            $conn->commit();

            return [
                'uuid' => $this->uuidBinaryToString($sectionUuid),
            ];
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }
    
}
