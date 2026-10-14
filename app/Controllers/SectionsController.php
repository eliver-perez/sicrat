<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Pagination;
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
    public function detail(Request $request,Response $response): void
    {
        $service=new \App\Services\GeographyService(new \App\Repositories\CommonRepository((new Database())->getConnection()));
        $response->success($request->method()==='PUT' ? $service->saveSection($request->route('id'),$request->input()) : $service->section($request->route('id')));
    }
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
                'offset'                        => $offset,
                'uid'                           => $currentUserId
            ]);

            return $response->json([
                    'success' => true,
                    'data' => [
                        'sections' => array_slice($data, 0, $limit)
                    ],
                    'pagination' => Pagination::response($data, $limit, $offset)
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
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }
}
