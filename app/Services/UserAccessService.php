<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Authorization;
use App\Core\Service;
use App\Repositories\UserAccessRepository;
use App\Repositories\PersonIdentityRepository;
use App\Repositories\ElectoralContextRepository;
use InvalidArgumentException;

class UserAccessService extends Service
{
    public function __construct(private UserAccessRepository $repository) {}
    public function detail(string $uuid): array
    {
        Authorization::requireOperation('users.read');
        $user = $this->repository->user($this->uuidStringToBinary($uuid));
        return ['person' => $user['persona_uuid'] ? $this->uuidBinaryToString($user['persona_uuid']) : null, 'person_code' => $user['codigo_persona'],
            'accesses' => array_map(fn ($row) => ['process' => $this->uuidBinaryToString($row['uuid']), 'name' => $row['proceso'], 'type' => (int) $row['tipo_usuario'], 'type_name' => $row['tipo'], 'active' => (int) $row['activo']], $this->repository->accesses((int) $user['id']))];
    }
    public function assign(string $uuid, array $data): void
    {
        Authorization::requireOperation('users.create');
        $user = $this->repository->user($this->uuidStringToBinary($uuid));
        if ($user['organizacion'] === null) throw new InvalidArgumentException('La cuenta necesita una organización para recibir accesos electorales.');
        $processUuid = $data['process'] ?? null;
        if (!is_string($processUuid)) throw new InvalidArgumentException('Selecciona una elección.');
        $active = $this->normalizeStatus($data['active'] ?? 1);
        $process = (new ElectoralContextRepository($this->repository->connection()))->authorized($this->uuidStringToBinary($processUuid), (int) $user['organizacion'], $active === 1);
        $type = $this->normalizeRequiredInt($data['type'] ?? null, 'Selecciona un tipo de usuario.');
        $typeCode = $this->repository->typeCode($type);
        if ($typeCode === 'superadmin' && $user['tipo_codigo'] !== 'superadmin') throw new InvalidArgumentException('Super Administrador es un tipo global de cuenta, no un acceso electoral.');
        if ($typeCode === null) throw new InvalidArgumentException('Tipo de usuario inválido.');
        $this->repository->assign((int) $user['id'], (int) $process['id'], $type, $active);
    }
    public function link(string $uuid, ?string $personUuid): void
    {
        Authorization::requireOperation('users.create');
        $user = $this->repository->user($this->uuidStringToBinary($uuid));
        $personId = null;
        if ($personUuid !== null && $personUuid !== '') {
            $person = (new PersonIdentityRepository($this->repository->connection()))->person($this->uuidStringToBinary($personUuid));
            if ($user['organizacion'] === null || (int) $person['organizacion'] !== (int) $user['organizacion']) throw new InvalidArgumentException('La persona y la cuenta deben pertenecer a la misma organización.');
            $personId = (int) $person['id'];
        }
        $this->repository->link((int) $user['id'], $personId);
    }
}
