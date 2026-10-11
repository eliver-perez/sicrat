<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
use App\Auth\Authorization;
use App\Core\Security\PasswordPolicy;
use App\Repositories\UsersRepository;
use App\Repositories\OrganizationsRepository;
use App\Repositories\ElectoralProcessRepository;
use InvalidArgumentException;
use RuntimeException;

class UsersService extends Service
{
    public function __construct(
        private UsersRepository $usersRepository,
        private OrganizationsRepository $organizationsRepository,
        private ElectoralProcessRepository $electoralProcessRepository
    ) {
    }

    public function getAll(array $data): array {
        try {
            $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

            $data = $this->usersRepository->getAll([
                'search'                        => $data['search'] !== '' ? $data['search'] : null,
                'limit'                         => $data['limit'],
                'offset'                        => $data['offset'],
                'status'                        => $data['status']
            ]);
            $users = array();

            foreach($data as $d) {
                array_push($users, array(
                    'id'                        => $this->uuidBinaryToString($d['uuid']),
                    'name'                      => $d['nombre'] ?? '',
                    'username'                  => $d['usuario'] ?? '',
                    'email'                     => $d['email'] ?? '',
                    'type'                      => $d['tipo'] ?? '',
                    'active'                    => $d['activo'] ?? 0,
                    'registered_date'           => $d['f_registro'] ?? '',
                    'last_active_date'          => $d['f_ultima_conexion'] ?? ''
                ));
            }

            return $users;
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    public function create(array $data): ?array {
        Authorization::requireOperation('users.create');
        $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

        $username = $this->normalizeRequiredText($data['username'] ?? null, 'Es necesario capturar un usuario.');
        $password = PasswordPolicy::newPassword($data['password'] ?? null);
        $user_type = $this->normalizeRequiredInt($data['user_type'] ?? null, 'Es necesario seleccionar el tipo de usuario.');
        $organization = $this->normalizeOptionalText($data['organization'] ?? null);

        $process = $this->normalizeOptionalText($data['process'] ?? null);

        $email = $this->normalizeOptionalText($data['email'] ?? null);
        $status = $this->normalizeStatus($data['status'] ?? 1);
        if (!$this->usersRepository->userTypeExists($user_type)) {
            throw new InvalidArgumentException('El tipo de usuario no es válido.');
        }

        $name = $this->normalizeRequiredText($data['name'] ?? null, 'Es necesario capturar el nombre.');
        $last_name = $this->normalizeRequiredText($data['last_name'] ?? null, 'Es necesario capturar el apellido.');
        $second_last_name = $this->normalizeOptionalText($data['second_last_name'] ?? null);

        $user_exists = $this->usersRepository->userExists($username);
        if($user_exists)
            throw new InvalidArgumentException("El nombre de usuario ya existe.");

        if ($email !== null) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('El correo electrónico no es válido.');
            }
        }

        $password_hash = $this->encrypt_hash($password);

        $organizationId = null;
        $processId = null;
        if ($organization !== null && $organization !== 'N/A') {
            $organizationId = $this->organizationsRepository->getOrganizationId([
                'uuid' => $this->uuidStringToBinary($organization)
            ]);
            if ($organizationId === null) {
                throw new InvalidArgumentException('La organización no existe.');
            }
        }
        if ($process !== null && $process !== 'N/A') {
            $processId = $this->electoralProcessRepository->getElectoralProcessId([
                'uuid' => $this->uuidStringToBinary($process)
            ]);
            if ($organizationId === null || $processId === null
                || !$this->electoralProcessRepository->belongsToOrganization($processId, $organizationId)) {
                throw new InvalidArgumentException('El proceso no pertenece a la organización seleccionada.');
            }
        }
        $registerProcess = $processId !== null;

        $conn = $this->usersRepository->getConnection();
        $conn->beginTransaction();
        try {
            $userUuid = $this->generateUuidBinary();
            $userId = $this->usersRepository->insertUser([
                    'uuid'                          => $userUuid,
                    'organization'                  => $organizationId,
                    'username'                      => $username,
                    'email'                         => $email,
                    'name'                          => $name,
                    'last_name'                     => $last_name,
                    'second_last_name'              => $second_last_name,
                    'password'                      => $password_hash,
                    'status'                        => $status,
                    'user_type'                     => $user_type,
                    'uid'                           => $uid,
                ]);
            
            if($registerProcess) {
                $this->usersRepository->insertUserProcess([
                    'user'                          => $userId,
                    'process'                       => $processId,
                    'user_type'                     => $user_type
                ]);
            }
            $conn->commit();
            return [
                'id'                            => $this->uuidBinaryToString($userUuid)
            ];
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }
}