<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Repositories\ElectoralProcessRepository;
use App\Repositories\OrganizationsRepository;
use App\Repositories\SettingsRepository;
use App\Services\ElectoralProcessService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ElectoralProcessController extends Controller
{
    private ?ElectoralProcessRepository $repository = null;

    private function getService(): ElectoralProcessService
    {
        $database = new Database();
        $conn = $database->getConnection();

        $electoralProcessRepository = new ElectoralProcessRepository($conn);
        $organizationsRepository = new OrganizationsRepository($conn);
        $settingsRepository = new SettingsRepository($conn);

        return new ElectoralProcessService($electoralProcessRepository,
                                        $organizationsRepository,
                                        $settingsRepository);
    }

    private function getRepository(): ElectoralProcessRepository {
        if ($this->repository === null) {
            $database = new Database();
            $conn = $database->getConnection();

            $this->repository = new ElectoralProcessRepository($conn);
        }

        return $this->repository;
    }

    public function types(Request $request, Response $response) {
        try {
            $currentUserId = Auth::id();

            if($currentUserId === null) {
                throw new RuntimeException("No autenticado.");
            }

            $service = $this->getService();
            $types = $service->getTypes([
                'uid'                           => $currentUserId
            ]);

            return $response->json([
                    'success' => true,
                    'data' => [
                        'types' => $types
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

    public function characters(Request $request, Response $response) {
        try {
            $currentUserId = Auth::id();

            if($currentUserId === null) {
                throw new RuntimeException("No autenticado.");
            }

            $service = $this->getService();
            $characters = $service->getCharacters([
                'uid'                           => $currentUserId
            ]);

            return $response->json([
                    'success' => true,
                    'data' => [
                        'characters' => $characters
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
                'offset'                        => $offset,
                'uid'                           => $currentUserId
            ]);

            return $response->json([
                    'success' => true,
                    'data' => [
                        'processes' => $data
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

    public function store(Request $request, Response $response, string $id) {
        try {
            $currentUserId = Auth::id();

            if($currentUserId === null) {
                throw new RuntimeException("No autenticado.");
            }

            $service = $this->getService();

            $organization = $service->create([
                'organization'              => $id,
                'process'                   => $request->input('process'),
                'type'                      => $request->input('type'),
                'character'                 => $request->input('character'),
                'status'                    => $request->input('status'),

                'uid'                       => $currentUserId,
            ]);

            return $response->json([
                'success' => true,
                'message' => 'Organización registrada.',
                'data' => $organization
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
}