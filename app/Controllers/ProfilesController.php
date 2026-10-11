<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Repositories\ProfilesRepository;
use App\Repositories\UsersRepository;
use App\Repositories\OrganizationsRepository;
use App\Repositories\ElectoralProcessRepository;
use App\Services\ProfilesService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ProfilesController extends Controller
{
    private ?ProfilesRepository $repository = null;

    private function getService(): ProfilesService
    {
        $database = new Database();
        $conn = $database->getConnection();

        $profilesRepository = new ProfilesRepository($conn);
        $usersRepository = new UsersRepository($conn);
        $organizationsRepository = new OrganizationsRepository($conn);
        $electoralProcessRepository = new ElectoralProcessRepository($conn);

        return new ProfilesService($profilesRepository,
                                    $usersRepository,
                                    $organizationsRepository,
                                    $electoralProcessRepository);
    }

    private function getRepository(): ProfilesRepository {
        if ($this->repository === null) {
            $database = new Database();
            $conn = $database->getConnection();

            $this->repository = new ProfilesRepository($conn);
        }

        return $this->repository;
    }
    
    public function show(Request $request, Response $response, string $id) {
        try {
            $currentUserId = Auth::id();

            if($currentUserId === null) {
                throw new \App\Core\HttpError("No autenticado.", 401);
            }
            $service = $this->getService();

            $profile = $service->getUserProfile([
                'uuid'                      => $id,
                'uid'                       => $currentUserId,
            ]);

            return $response->json([
                'success' => true,
                'message' => 'Datos de Perfil.',
                'data' => $profile
            ], 200);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }
    
    public function updatePassword(Request $request, Response $response, string $id) {
        try {
            $currentUserId = Auth::id();

            if($currentUserId === null) {
                throw new \App\Core\HttpError("No autenticado.", 401);
            }
            $service = $this->getService();

            $updated = $service->updatePassword([
                'uuid'                      => $id,
                'old_password'              => $request->input('old_password'),
                'new_password'              => $request->input('new_password'),
                'uid'                       => $currentUserId,
            ]);

            return $response->json([
                'success' => $updated['changed'],
                'message' => $updated['message'],
                'data' => $updated
            ], $updated['changed'] ? 200 : 422);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }
}