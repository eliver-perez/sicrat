<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
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
                throw new RuntimeException("No autenticado.");
            }

            $service = $this->getService();

            $search = trim((string)$this->request->query('search', ''));
            
            $limit = (int)$this->request->query('limit', 10);
            $offset = (int)$this->request->query('offset', 0);

            $limit = max(1, min($limit, 5000000));
            $offset = max(0, $offset);

            $data = $service->getAll([
                'search'                        => $search !== '' ? $search : null,
                'limit'                         => $limit,
                'offset'                        => $offset
            ]);

            return $response->json([
                    'success' => true,
                    'data' => [
                        'organizations' => $data
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

    public function getElectoralProcesses(Request $request, Response $response, string $id) {
        try {
            $currentUserId = Auth::id();

            if($currentUserId === null) {
                throw new RuntimeException("No autenticado.");
            }

            $service = $this->getService();

            $electoral_processes = $service->getElectoralProcesses([
                'uuid'                          => $id,
                'uid'                           => $currentUserId
            ]);

            return $response->json([
                    'success' => true,
                    'data' => [
                        'electoral_processes' => $electoral_processes
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

    public function store(Request $request, Response $response) {
        try {
            $currentUserId = Auth::id();

            if($currentUserId === null) {
                throw new RuntimeException("No autenticado.");
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

    public function show(Request $request, Response $response, string $id) {
        try {
            $currentUserId = Auth::id();

            if($currentUserId === null) {
                throw new RuntimeException("No autenticado.");
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