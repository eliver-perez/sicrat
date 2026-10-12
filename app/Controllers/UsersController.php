<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Pagination;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Repositories\UsersRepository;
use App\Repositories\OrganizationsRepository;
use App\Repositories\ElectoralProcessRepository;
use App\Services\UsersService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class UsersController extends Controller
{
    private ?UsersRepository $repository = null;

    private function getService(): UsersService
    {
        $database = new Database();
        $conn = $database->getConnection();

        $usersRepository = new UsersRepository($conn);
        $organizationsRepository = new OrganizationsRepository($conn);
        $electoralProcessRepository = new ElectoralProcessRepository($conn);

        return new UsersService($usersRepository,
                                $organizationsRepository,
                                $electoralProcessRepository);
    }

    private function getRepository(): UsersRepository {
        if ($this->repository === null) {
            $database = new Database();
            $conn = $database->getConnection();

            $this->repository = new UsersRepository($conn);
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

            $users = $service->getAll([
                'search'                                => $search !== '' ? $search : null,
                'limit'                                 => $limit + 1,
                'offset'                                => $offset,
                'status'                                => '',
                'uid'                                   => $currentUserId
            ]);

            return $response->json([
                    'success' => true,
                    'data' => [
                        'users' => array_slice($users, 0, $limit)
                    ],
                    'pagination' => Pagination::response($users, $limit, $offset)
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

            $user = $service->create([
                'username'                  => $request->input('username'),
                'password'                  => $request->input('password'),
                'user_type'                 => $request->input('type'),
                'organization'              => $request->input('organization'),
                'process'                   => $request->input('process'),
                'email'                     => $request->input('email'),
                'status'                    => $request->input('status'),
                'name'                      => $request->input('name'),
                'last_name'                 => $request->input('last_name'),
                'second_last_name'          => $request->input('second_last_name'),
                'person'                    => $request->input('person'),

                'uid'                       => $currentUserId,
            ]);

            return $response->json([
                'success' => true,
                'message' => 'Usuario registrado.',
                'data' => $user
            ], 201);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }
}