<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
use App\Repositories\ElectoralProcessRepository;
use App\Repositories\OrganizationsRepository;
use App\Repositories\SettingsRepository;
use App\Services\SettingsService;
use InvalidArgumentException;
use RuntimeException;

class ElectoralProcessService extends Service
{
    public function __construct(
        private ElectoralProcessRepository $electoralProcessRepository,
        private OrganizationsRepository $organizationsRepository,
        private SettingsRepository $settingsRepository
    ) {
    }

    public function getTypes(array $data): array {
        try {
            $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

            $types_data = $this->electoralProcessRepository->getTypes();
            $types = array();

            foreach($types_data as $d) {
                array_push($types, array(
                    'id'                        => $d['id'],
                    'code'                      => $d['clave'],
                    'type'                      => $d['tipo'],
                    'scope'                     => $d['ambito'] ?? '',
                    'active'                    => $d['activo']
                ));
            }

            return $types;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function getCharacters(array $data): array {
        try {
            $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

            $characters_data = $this->electoralProcessRepository->getCharacters();
            $characters = array();

            foreach($characters_data as $d) {
                array_push($characters, array(
                    'id'                        => $d['id'],
                    'code'                      => $d['clave'],
                    'character'                 => $d['caracter'],
                    'active'                    => $d['activo']
                ));
            }

            return $characters;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function getAll(array $data): array {
        try {
            $data = $this->electoralProcessRepository->getAll([
                'search'                        => $data['search'] !== '' ? $data['search'] : null,
                'limit'                         => $data['limit'],
                'offset'                        => $data['offset']
            ]);
            $processes = array();

            foreach($data as $d) {
                array_push($processes, array(
                    'id'                        => $this->uuidBinaryToString($d['uuid']),
                    'organization'              => $d['organizacion'],
                    'process'                   => $d['proceso'],
                    'type'                      => $d['tipo'] ?? '',
                    'character'                 => $d['caracter'] ?? '',
                    'status'                    => $d['estatus'],
                    'registered_by'             => $d['registro'],
                    'registered_date'           => $d['f_registro'],
                ));
            }

            return $processes;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function create(array $data): ?array {
        $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

        $organization = $this->normalizeRequiredText($data['organization'] ?? null, 'No se recibio identificador de organizacion.');
        $process = $this->normalizeRequiredText($data['process'] ?? null, 'El nombre del proceso es obligatorio.');
        $type = $this->normalizeRequiredInt($data['type'] ?? null, 'El tipo del proceso es obligatorio.');
        $character = $this->normalizeRequiredInt($data['character'] ?? null, 'El caracter del proceso es obligatorio.');

        $organizationId = $this->organizationsRepository->getOrganizationId([
            'uuid'                                  => $this->uuidStringToBinary($organization)
        ]);

        $status = $this->normalizeOptionalInt($data['status'] ?? 1);

        $conn = $this->electoralProcessRepository->getConnection();
        $conn->beginTransaction();
        try {
            $processUuid = $this->generateUuidBinary();
            $processId = $this->electoralProcessRepository->insertProcess([
                    'uuid'                          => $processUuid,
                    'organization'                  => $organizationId,
                    'process'                       => $process,
                    'type'                          => $type,
                    'election_date'                 => null,
                    'start_date'                    => null,
                    'end_date'                      => null,
                    'character'                     => $character,
                    'status'                        => $status,
                    'uid'                           => $uid
                ]);

            $conn->commit();

            return [
                'id'                        => $this->uuidBinaryToString($processUuid),
                'organization'              => $organization
            ];
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }
    
    public function getElectoralProcessData(array $data): ?array {
        try {
            $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

            $electoralProcessData = $this->electoralProcessRepository->getElectoralProcessData([
                'uuid'                          => $this->uuidStringToBinary($data['uuid'])
            ]);

            return [
                'id'                        => $this->uuidBinaryToString($electoralProcessData['uuid']),
                'organization'              => $electoralProcessData['organizacion'],
                'contact'                   => $electoralProcessData['contacto'] ?? '',
                'phone'                     => $electoralProcessData['telefono'] ?? '',
                'email'                     => $electoralProcessData['email'] ?? '',
                'active'                    => $electoralProcessData['activo'] ?? '',
                'registered_by'             => $electoralProcessData['registro'],
                'registered_date'           => $electoralProcessData['f_registro']
            ];
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}