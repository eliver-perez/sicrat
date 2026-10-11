<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Pagination;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Repositories\OrganizationsRepository;
use App\Repositories\SettingsRepository;
use App\Services\OrganizationsService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class OrganizationsController extends Controller
{
    private ?OrganizationsRepository $repository = null;

    private function getService(): OrganizationsService
    {
        $database = new Database();
        $conn = $database->getConnection();

        $organizationsRepository = new OrganizationsRepository($conn);
        $settingsRepository = new SettingsRepository($conn);

        return new OrganizationsService($organizationsRepository,
                                        $settingsRepository);
    }

    private function getRepository(): OrganizationsRepository {
        if ($this->repository === null) {
            $database = new Database();
            $conn = $database->getConnection();

            $this->repository = new OrganizationsRepository($conn);
        }

        return $this->repository;
    }

    public function index(Request $request, Response $response) {
        try {
            $currentUserId = Auth::id();

            if($currentUserId === null) {
                throw new \App\Core\HttpError("No autenticado.", 401);
            }

            $service = $this->getService();

            $search = trim((string)$this->request->query('search', ''));
            
            $limit = (int)$this->request->query('limit', 25);
            $offset = (int)$this->request->query('offset', 0);

            $limit = Pagination::limit($limit);
            $offset = Pagination::offset($offset);

            $data = $service->getAll([
                'search'                        => $search !== '' ? $search : null,
                'limit'                         => $limit + 1,
                'offset'                        => $offset
            ]);

            return $response->json([
                    'success' => true,
                    'data' => [
                        'organizations' => array_slice($data, 0, $limit)
                    ],
                    'pagination' => Pagination::response($data, $limit, $offset)
                ], 200);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }

    public function getElectoralProcesses(Request $request, Response $response, string $id) {
        try {
            $currentUserId = Auth::id();

            if($currentUserId === null) {
                throw new \App\Core\HttpError("No autenticado.", 401);
            }

            $service = $this->getService();
            $limit = Pagination::limit($request->query('limit', 25));
            $offset = Pagination::offset($request->query('offset', 0));

            $electoral_processes = $service->getElectoralProcesses([
                'uuid'                          => $id,
                'uid'                           => $currentUserId,
                'limit'                         => $limit + 1,
                'offset'                        => $offset
            ]);

            return $response->json([
                    'success' => true,
                    'data' => [
                        'electoral_processes' => array_slice($electoral_processes, 0, $limit)
                    ],
                    'pagination' => Pagination::response($electoral_processes, $limit, $offset)
                ], 200);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }

    public function store(Request $request, Response $response) {
        try {
            $currentUserId = Auth::id();

            if($currentUserId === null) {
                throw new \App\Core\HttpError("No autenticado.", 401);
            }

            $service = $this->getService();

            $organization = $service->create([
                'organization'              => $request->input('organization'),
                'contact'                   => $request->input('contact'),
                'phone'                     => $request->input('phone'),
                'email'                     => $request->input('email'),
                'active'                    => $request->input('active'),

                'uid'                       => $currentUserId,
            ]);

            return $response->json([
                'success' => true,
                'message' => 'Organización registrada.',
                'data' => [
                    'id' => $organization['uuid'],
                ]
            ], 201);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }

    public function show(Request $request, Response $response, string $id) {
        try {
            $currentUserId = Auth::id();

            if($currentUserId === null) {
                throw new \App\Core\HttpError("No autenticado.", 401);
            }

            $service = $this->getService();

            $organization = $service->getOrganizationData([
                'uuid'                      => $id,
                'uid'                       => $currentUserId,
            ]);

            return $response->json([
                'success' => true,
                'message' => 'Datos de Empresa.',
                'data' => $organization
            ], 200);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }
}