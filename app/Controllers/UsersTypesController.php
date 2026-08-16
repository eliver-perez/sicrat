<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Repositories\UsersTypesRepository;
use App\Services\UsersTypesService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class UsersTypesController extends Controller
{
    private ?UsersTypesRepository $repository = null;

    private function getService(): UsersTypesService
    {
        $database = new Database();
        $conn = $database->getConnection();

        $usersTypesRepository = new UsersTypesRepository($conn);

        return new UsersTypesService($usersTypesRepository);
    }

    private function getRepository(): UsersTypesRepository {
        if ($this->repository === null) {
            $database = new Database();
            $conn = $database->getConnection();

            $this->repository = new UsersTypesRepository($conn);
        }

        return $this->repository;
    }

    public function index(Request $request, Response $response) {
        try {
            $service = $this->getService();

            $search = trim((string)$this->request->query('search', ''));

            $data = $service->getAll($search !== '' ? $search : null);

            return $response->json([
                    'success' => true,
                    'data' => [
                        'users_types' => $data
                    ]
                ], 200);
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $response->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        } catch (Throwable $e) {
            return $response->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}