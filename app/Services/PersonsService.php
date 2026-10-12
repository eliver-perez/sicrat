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

    public function create(array $data): ?array {
        return (new PersonIdentityService(new \App\Repositories\PersonIdentityRepository($this->personsRepository->getConnection())))->create($data);
    }
}
