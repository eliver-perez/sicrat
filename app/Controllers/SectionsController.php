<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Repositories\SectionsRepository;
use App\Repositories\OrganizationsRepository;
use App\Repositories\SettingsRepository;
use App\Services\SectionsService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SectionsController extends Controller
{
    private ?SectionsRepository $repository = null;

    private function getService(): SectionsService
    {
        $database = new Database();
        $conn = $database->getConnection();

        $sectionsRepository = new SectionsRepository($conn);
        $organizationsRepository = new OrganizationsRepository($conn);
        $settingsRepository = new SettingsRepository($conn);

        return new SectionsService($sectionsRepository,
                                        $organizationsRepository,
                                        $settingsRepository);
    }

    private function getRepository(): SectionsRepository {
        if ($this->repository === null) {
            $database = new Database();
            $conn = $database->getConnection();

            $this->repository = new SectionsRepository($conn);
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
                'offset'                        => $offset,
                'uid'                           => $currentUserId
            ]);

            return $response->json([
                    'success' => true,
                    'data' => [
                        'sections' => $data
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

            $section = $service->create([
                'section'                   => $request->input('section'),
                'state'                     => $request->input('state'),
                'municipality'              => $request->input('municipality'),

                'uid'                       => $currentUserId,
            ]);

            return $response->json([
                'success' => true,
                'message' => 'Sección registrada.',
                'data' => [
                    'id' => $section['uuid'],
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
}