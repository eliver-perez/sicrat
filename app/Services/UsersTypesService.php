<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Service;
use App\Repositories\UsersTypesRepository;
use InvalidArgumentException;
use RuntimeException;

class UsersTypesService extends Service
{
    public function __construct(
        private UsersTypesRepository $usersTypesRepository
    ) {
    }

    public function getAll(?string $search = null, int $limit = 10, int $offset = 0, string $status = ''): array {
        try {
            $data = $this->usersTypesRepository->getAll($search !== '' ? $search : null);
            $users_types = array();

            foreach($data as $d) {
                array_push($users_types, array(
                    'id'                        => $d['id'],
                    'code'                      => $d['codigo'],
                    'type'                      => $d['tipo'],
                ));
            }

            return $users_types;
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}