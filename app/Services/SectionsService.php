<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
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
        $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

        $state = $this->normalizeRequiredInt($data['state'] ?? null, 'Es necesario seleccionar un estado.');
        $municipality = $this->normalizeRequiredInt($data['municipality'] ?? null, 'Es necesario seleccionar un municipio.');
        $section = $this->normalizeRequiredText($data['section'] ?? null, 'Es necesario capturar un numero de sección.');

        $conn = $this->sectionsRepository->getConnection();
        $conn->beginTransaction();
        try {
            if($this->sectionsRepository->sectionExists([
                'section'                               => $section,
                'state'                                 => $state,
                'municipality'                          => $municipality
            ]))
                throw new RuntimeException("La Sección $section ya se encuentra registrada");

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
    
    public function getSectionsData(array $data): ?array {
        try {
            $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

            $sectionsData = $this->sectionsRepository->getSectionsData([
                'uuid'                          => $this->uuidStringToBinary($data['uuid'])
            ]);

            return [
                'id'                        => $this->uuidBinaryToString($sectionsData['uuid']),
                'organization'              => $sectionsData['organizacion'],
                'contact'                   => $sectionsData['contacto'] ?? '',
                'phone'                     => $sectionsData['telefono'] ?? '',
                'email'                     => $sectionsData['email'] ?? '',
                'active'                    => $sectionsData['activo'] ?? '',
                'registered_by'             => $sectionsData['registro'],
                'registered_date'           => $sectionsData['f_registro']
            ];
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}