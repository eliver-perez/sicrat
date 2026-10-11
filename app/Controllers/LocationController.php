<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Database;
use App\Repositories\LocationRepository;
use App\Services\LocationService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class LocationController extends Controller
{
    private function getService(): LocationService
    {
        $database = new Database();
        $conn = $database->getConnection();

        $repository = new LocationRepository($conn);

        return new LocationService($repository);
    }

    public function states(Request $request, Response $response)
    {
        try {
            $service = $this->getService();

            $countryId = null;

            $search = $request->query('search');

            $states = $service->getStates($countryId, $search);

            return $response->json([
                'success' => true,
                'data' => [
                    'states' => $states
                ]
            ]);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }

    public function municipalities(Request $request, Response $response, string $id)
    {
        try {
            $service = $this->getService();

            $search = $request->query('search');

            $municipalities = $service->getMunicipalities($this->routeId($id), $search);

            return $response->json([
                'success' => true,
                'data' => [
                    'municipalities' => $municipalities
                ]
            ]);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }

    public function localities(Request $request, Response $response, string $id)
    {
        try {
            $service = $this->getService();

            $municipalityId = $this->routeId($id);

            $search = $request->query('search');

            $localities = $service->getLocalities($municipalityId, $search);

            return $response->json([
                'success' => true,
                'data' => [
                    'localities' => $localities
                ]
            ]);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }

    public function neighborhoods(Request $request, Response $response, string $id)
    {
        try {
            $service = $this->getService();

            $localityId = $this->routeId($id);

            $search = $request->query('search');

            $neighborhoods = $service->getNeighborhoods($localityId, $search);

            return $response->json([
                'success' => true,
                'data' => [
                    'neighborhoods' => $neighborhoods
                ]
            ]);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }

    public function storeState(Request $request, Response $response)
    {
        try {
            $service = $this->getService();

            $input = $request->input();

            $stateId = $service->createState($input);

            return $response->json([
                'success' => true,
                'message' => 'Estado registrado correctamente.',
                'data' => [
                    'state_id' => $stateId
                ]
            ], 201);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }

    public function storeMunicipality(Request $request, Response $response, string $id)
    {
        try {
            $service = $this->getService();

            $input = $request->input();
            $input['state_id'] = $this->routeId($id);

            $municipalityId = $service->createMunicipality($input);

            return $response->json([
                'success' => true,
                'message' => 'Municipio registrado correctamente.',
                'data' => [
                    'municipality_id' => $municipalityId
                ]
            ], 201);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }

    public function storeLocality(Request $request, Response $response, string $id)
    {
        try {
            $service = $this->getService();

            $input = $request->input();
            $input['municipality_id'] = $this->routeId($id);

            $localityId = $service->createLocality($input);

            return $response->json([
                'success' => true,
                'message' => 'Colonia registrada correctamente.',
                'data' => [
                    'locality_id' => $localityId
                ]
            ], 201);
        } catch (Throwable $e) {
            return $response->exception($e);
        }
    }

    private function routeId(string $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new InvalidArgumentException('El identificador no es válido.');
        }
        return (int) $value;
    }
}
