<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Pagination;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Repositories\PersonsRepository;
use App\Repositories\OrganizationsRepository;
use App\Repositories\SectionsRepository;
use App\Repositories\SettingsRepository;
use App\Services\PersonsService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PersonsController extends Controller
{
    private ?PersonsRepository $repository = null;

    private function getService(): PersonsService
    {
        $database = new Database();
        $conn = $database->getConnection();

        $personsRepository = new PersonsRepository($conn);
        $organizationsRepository = new OrganizationsRepository($conn);
        $sectionsRepository = new SectionsRepository($conn);
        $settingsRepository = new SettingsRepository($conn);

        return new PersonsService($personsRepository,
                                        $organizationsRepository,
                                        $sectionsRepository,
                                        $settingsRepository);
    }

    private function getRepository(): PersonsRepository {
        if ($this->repository === null) {
            $database = new Database();
            $conn = $database->getConnection();

            $this->repository = new PersonsRepository($conn);
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

            $section = trim((string)$this->request->query('section', null));
            $search = trim((string)$this->request->query('search', null));
            
            $limit = (int)$this->request->query('limit', 25);
            $offset = (int)$this->request->query('offset', 0);

            $limit = Pagination::limit($limit);
            $offset = Pagination::offset($offset);

            $persons = $service->getAll([
                'section'                       => $section,
                'search'                        => $search,
                'limit'                         => $limit + 1,
                'offset'                        => $offset,
                'uid'                           => $currentUserId
            ]);

            return $response->json([
                    'success' => true,
                    'data' => [
                        'persons' => array_slice($persons, 0, $limit)
                    ],
                    'pagination' => Pagination::response($persons, $limit, $offset)
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
            
            $person = $service->create([
                'organization'              => $request->input('organization'),
                'section'                   => $request->input('section'),
                'gender'                    => $request->input('gender'),
                'name'                      => $request->input('name'),
                'last_name'                 => $request->input('last_name'),
                'last_name_2'               => $request->input('last_name_2'),

                'uid'                       => $currentUserId,
            ]);

            return $response->json([
                'success' => true,
                'message' => 'Persona registrada.',
                'data' => [
                    'id' => $person['uuid'],
                ]
            ], 201);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }
}