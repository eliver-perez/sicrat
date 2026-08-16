<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
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
            
            if($section == 'N/A') {
                $sectionId = null;
            } else {
                $sectionId = $this->uuidStringToBinary($section);
            }

            $limit = $this->normalizeOptionalInt($data['limit'] ?? 500000);
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
                        'section'                   => str_pad((string)$d['seccion'], 4, '0', STR_PAD_LEFT),
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

    public function create(array $data): ?array {
        $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

        $organization = $this->normalizeRequiredText($data['organization'] ?? null, 'Es necesario seleccionar una organización.');
        $section = $this->normalizeRequiredText($data['section'] ?? null, 'Es necesario seleccionar una sección.');
        $gender = $this->normalizeRequiredText($data['gender'] ?? null, 'Es necesario selección un genero.');
        
        $name = $this->normalizeRequiredText($data['name'] ?? null, 'Es necesario capturar el nombre.');
        $last_name = $this->normalizeRequiredText($data['last_name'] ?? null, 'Es necesario capturar capturar el apellido paterno.');

        $last_name_2 = $this->normalizeOptionalText($data['last_name_2'] ?? null);

        $organizationId = $this->organizationsRepository->getOrganizationId([
            'uuid'                      => $this->uuidStringToBinary($organization)
        ]);
        $sectionId = $this->sectionsRepository->getSectionId([
            'uuid'                      => $this->uuidStringToBinary($section)
        ]);

        $conn = $this->personsRepository->getConnection();
        $conn->beginTransaction();
        try {
            $personUuid = $this->generateUuidBinary();
            $sectionId = $this->personsRepository->insertPerson([
                    'uuid'                          => $personUuid,
                    'name'                          => $name,
                    'last_name'                     => $last_name,
                    'last_name_2'                   => $last_name_2,
                    'organization'                  => $organizationId,
                    'section'                       => $sectionId,
                    'gender'                        => $gender,
                    'uid'                           => $uid
                ]);

            $conn->commit();

            return [
                'uuid' => $this->uuidBinaryToString($personUuid),
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

            $sectionsData = $this->personsRepository->getSectionsData([
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