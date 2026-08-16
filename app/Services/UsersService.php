<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
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

                // 'username'                  => $request->input('username'),
                // 'password'                  => $request->input('password'),
                // 'user_type'                 => $request->input('type'),
                // 'organization'              => $request->input('organization'),
                // 'process'                   => $request->input('process'),
                // 'email'                     => $request->input('email'),
                // 'status'                    => $request->input('status'),
                // 'name'                      => $request->input('name'),
                // 'last_name'                 => $request->input('last_name'),
                // 'second_last_name'          => $request->input('second_last_name'),
    public function create(array $data): ?array {
        $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');

        $username = $this->normalizeRequiredText($data['username'] ?? null, 'Es necesario capturar un usuario.');
        $password = $this->normalizeRequiredText($data['password'] ?? null, 'Es necesario capturar la contraseña.');
        $user_type = $this->normalizeRequiredText($data['user_type'] ?? null, 'Es neceserio seleccionar el tipo de usuario.');
        $organization = $this->normalizeRequiredText($data['organization'] ?? null, 'Es necesario seleccionar la organizacion');

        $process = $this->normalizeOptionalText($data['process'] ?? null, 'Es necesario seleccionar el proceso');

        $email = $this->normalizeOptionalText($data['email'] ?? null);
        $status = $this->normalizeOptionalInt($data['status'] ?? null);

        $name = $this->normalizeRequiredText($data['name'] ?? null, 'Es necesario capturar el nombre.');
        $last_name = $this->normalizeRequiredText($data['last_name'] ?? null, 'Es necesario capturar el apellido.');
        $second_last_name = $this->normalizeOptionalText($data['second_last_name'] ?? null);

        $user_exists = $this->usersRepository->userExists($username);
        if($user_exists)
            throw new RuntimeException("El nombre de usuario ya existe.");

        if($organization != null && $process != null)
            $registerProcess = true;
        else
            $registerProcess = false;
        
        if ($email !== null) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('El correo electrónico no es válido.');
            }
        }

        $password_hash = $this->encrypt_hash($password);

        if($registerProcess) {
            $organizationId = $this->organizationsRepository->getOrganizationId([
                'uuid'                          => $this->uuidStringToBinary($organization)
            ]);

            $processId = $this->electoralProcessRepository->getElectoralProcessId([
                'uuid'                          => $this->uuidStringToBinary($process)
            ]);

            if($organizationId == null || $processId == null)
                $registerProcess = false;
        }
        
        $conn = $this->usersRepository->getConnection();
        $conn->beginTransaction();
        try {
            $userUuid = $this->generateUuidBinary();
            $userId = $this->usersRepository->insertUser([
                    'uuid'                          => $userUuid,
                    'organization'                  => $registerProcess ? $organizationId : null,
                    'username'                      => $username,
                    'email'                         => $email,
                    'name'                          => $name,
                    'last_name'                     => $last_name,
                    'second_last_name'              => $second_last_name,
                    'password'                      => $password_hash,
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