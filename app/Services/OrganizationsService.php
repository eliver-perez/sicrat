<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
use App\Repositories\OrganizationsRepository;
use App\Repositories\SettingsRepository;
use App\Services\SettingsService;
use InvalidArgumentException;
use RuntimeException;

class OrganizationsService extends Service
{
    public function __construct(
        private OrganizationsRepository $organizationsRepository,
        private SettingsRepository $settingsRepository
    ) {
    }

    public function getAll(array $data): array {
        try {
            $organizations_data = $this->organizationsRepository->getAll([
                'search'                        => $data['search'] !== '' ? $data['search'] : null,
                'limit'                         => $data['limit'],
                'offset'                        => $data['offset']
            ]);
            $organizations = array();

            if(count($organizations_data) > 0) {
                foreach($organizations_data as $d) {
                    array_push($organizations, array(
                        'id'                        => $this->uuidBinaryToString($d['uuid']),
                        'organization'              => $d['organizacion'],
                        'contact'                   => $d['contacto'],
                        'phone'                     => $d['telefono'] ?? '',
                        'email'                     => $d['email'] ?? '',
                        'active'                    => $d['activo'],
                        'registered_by'             => $d['registro'],
                        'registered_date'           => $d['f_registro'],
                    ));
                }
            }

            return $organizations;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function create(array $data): ?array {
        $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

        $organization = $this->normalizeRequiredText($data['organization'] ?? null, 'El nombre de la organizacion es obligatorio.');
        $contact = $this->normalizeRequiredText($data['contact'] ?? null, 'El nombre del contacto es obligatorio.');

        $phone = $this->normalizeOptionalText($data['phone'] ?? 1);
        $email = $this->normalizeOptionalText($data['email'] ?? 1);

        $active = $this->normalizeOptionalInt($data['active'] ?? 1);

        $conn = $this->organizationsRepository->getConnection();
        $conn->beginTransaction();
        try {
            $organizationUuid = $this->generateUuidBinary();
            $organizationId = $this->organizationsRepository->insertOrganization([
                    'uuid'                          => $organizationUuid,
                    'organization'                  => $organization,
                    'contact'                       => $contact,
                    'phone'                         => $phone,
                    'email'                         => $email,
                    'active'                        => $active,
                    'uid'                           => $uid
                ]);

            $conn->commit();

            return [
                'uuid' => $this->uuidBinaryToString($organizationUuid),
            ];
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }
    
    public function getOrganizationData(array $data): ?array {
        try {
            $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

            $organizationData = $this->organizationsRepository->getOrganizationData([
                'uuid'                          => $this->uuidStringToBinary($data['uuid'])
            ]);

            $processes_data = $this->organizationsRepository->getOrganizationProcesses([
                'uuid'                          => $this->uuidStringToBinary($data['uuid'])
            ]);
            
            $processes = array();
            foreach($processes_data as $d) {
                array_push($processes, array(
                    'id'                        => $this->uuidBinaryToString($d['uuid']),
                    'organization'              => $d['organizacion'],
                    'process'                   => $d['proceso'],
                    'type'                      => $d['tipo'] ?? '',
                    'character'                 => $d['caracter'] ?? '',
                    'election_date'             => $d['f_eleccion'] ?? '',
                    'start_date'                => $d['f_inicio'] ?? '',
                    'end_date'                  => $d['f_fin'] ?? '',
                    'status'                    => $d['estatus'] ?? '',
                    'registered_by'             => $d['registro'],
                    'registered_date'           => $d['f_registro'],
                ));
            }

            return [
                'id'                        => $this->uuidBinaryToString($organizationData['uuid']),
                'organization'              => $organizationData['organizacion'],
                'contact'                   => $organizationData['contacto'] ?? '',
                'phone'                     => $organizationData['telefono'] ?? '',
                'email'                     => $organizationData['email'] ?? '',
                'active'                    => $organizationData['activo'] ?? '',
                'registered_by'             => $organizationData['registro'],
                'registered_date'           => $organizationData['f_registro'],
                'processes'                 => $processes
            ];
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    
    public function getElectoralProcesses(array $data): ?array {
        try {
            $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');
            $uuid = $this->normalizeRequiredText($data['uuid'] ?? null, 'No se recibio identificador de organization.');

            $processes_data = $this->organizationsRepository->getOrganizationProcesses([
                'uuid'                          => $this->uuidStringToBinary($data['uuid'])
            ]);
            
            $processes = array();
            foreach($processes_data as $d) {
                array_push($processes, array(
                    'id'                        => $this->uuidBinaryToString($d['uuid']),
                    'organization'              => $d['organizacion'],
                    'process'                   => $d['proceso'],
                    'type'                      => $d['tipo'] ?? '',
                    'character'                 => $d['caracter'] ?? '',
                    'election_date'             => $d['f_eleccion'] ?? '',
                    'start_date'                => $d['f_inicio'] ?? '',
                    'end_date'                  => $d['f_fin'] ?? '',
                    'status'                    => $d['estatus'] ?? '',
                    'registered_by'             => $d['registro'],
                    'registered_date'           => $d['f_registro'],
                ));
            }

            return $processes;
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}