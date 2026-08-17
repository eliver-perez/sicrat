<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
use App\Repositories\ProfilesRepository;
use App\Repositories\UsersRepository;
use App\Repositories\OrganizationsRepository;
use App\Repositories\ElectoralProcessRepository;
use InvalidArgumentException;
use RuntimeException;

class ProfilesService extends Service
{
    public function __construct(
        private ProfilesRepository $profilesRepository,
        private UsersRepository $usersRepository,
        private OrganizationsRepository $organizationsRepository,
        private ElectoralProcessRepository $electoralProcessRepository
    ) {
    }
    
    public function getUserProfile(array $data): ?array {
        try {
            $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');
            $uuid = $this->normalizeRequiredText($data['uuid'] ?? null, 'No se recibio un perfil.');

            $logged_uuid_bin = $this->usersRepository->getUserUuid($uid);
            $logged_uuid = $this->uuidBinaryToString($logged_uuid_bin);

            if($uuid != $logged_uuid)
                throw new RuntimeException("Sin acceso a modificar información.");

            $profile_data = $this->usersRepository->getUserData([
                'uuid'                          => $this->uuidStringToBinary($uuid)
            ]);

            return [
                'id'                        => $this->uuidBinaryToString($profile_data['uuid']),
                'email'                     => $profile_data['email'] ?? '',
                'user'                      => $profile_data['usuario'],
                'name'                      => $profile_data['nombre'] ?? '',
                'type'                      => $profile_data['tipo'] ?? '',
                'active'                    => $profile_data['activo'] ?? '',
                'registered_by'             => $profile_data['registro'],
                'registered_date'           => $profile_data['f_registro']
            ];
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    
    public function updatePassword(array $data): ?array {
        try {
            $salt = env('HMAC_SALT');

            if(!$salt) {
                throw new \RuntimeException('HMAL_SALT no esta configurado en .env');
            }

            $uid = $this->normalizeRequiredInt($data['uid'] ?? null, 'No existe una sesion activa.');
            $uuid = $this->normalizeRequiredText($data['uuid'] ?? null, 'No se recibio un perfil.');

            $old_password = $this->normalizeRequiredText($data['old_password'] ?? null, 'No se recibio contraseña');
            $new_password = $this->normalizeRequiredText($data['new_password'] ?? null, 'No se recibio contraseña');

            $password_hash = $this->encrypt_hash($new_password);

            $logged_uuid_bin = $this->usersRepository->getUserUuid($uid);
            $logged_uuid = $this->uuidBinaryToString($logged_uuid_bin);

            if($uuid != $logged_uuid)
                throw new RuntimeException("Sin acceso a modificar información.");

            $profile_data = $this->usersRepository->getUserData([
                'uuid'                          => $this->uuidStringToBinary($uuid)
            ]);

            if (!$profile_data) {
                return [
                    'changed'                   => false,
                    'message'                   => 'No se encontro información del usuario.'
                ];
            }

            if (!password_verify($old_password, $profile_data['password_hash'])) {
                return [
                    'changed'                   => false,
                    'message'                   => 'Contraseña no coincide.'
                ];
            }

            $this->usersRepository->updatePassword([
                'password'                  => $password_hash,
                'uuid'                      => $this->uuidStringToBinary($uuid)
            ]);

            return [
                'changed'                   => true,
                'message'                   => 'Contraseña modificada.'
            ];
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}