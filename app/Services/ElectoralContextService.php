<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\Authorization;
use App\Auth\SessionValidator;
use App\Core\Auth;
use App\Core\Service;
use App\Repositories\ElectoralContextRepository;
use PDO;

class ElectoralContextService extends Service
{
    private ElectoralContextRepository $repository;
    public function __construct(private PDO $db) { $this->repository = new ElectoralContextRepository($db); }

    public function list(int $limit = 100, int $offset = 0): array
    {
        Authorization::requireOperation('authenticated');
        return array_map(function (array $row): array {
            return ['id' => $this->uuidBinaryToString($row['uuid']), 'process' => $row['proceso'],
                'organization' => $row['organizacion_nombre'], 'type' => $row['tipo'], 'type_code' => $row['codigo'],
                'selected' => (int) $row['id'] === Auth::processId()];
        }, $this->repository->available($limit, $offset));
    }

    public function select(string $uuid): array
    {
        Authorization::requireOperation('authenticated');
        $process = $this->repository->authorized($this->uuidStringToBinary($uuid));
        $_SESSION['SICRAT_USER_ROLE'] = $process['access_id'] ?? null;
        $_SESSION['SICRAT_ORGANIZATION_PROCESS_ID'] = (int) $process['id'];
        if (!(new SessionValidator($this->db))->validate()) throw new \App\Core\HttpError('No autenticado.', 401);
        return ['process' => $uuid, 'type' => $_SESSION['SICRAT_USER_TYPE_CODE'], 'rights' => $_SESSION['SICRAT_RIGHTS']];
    }
}
