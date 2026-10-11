<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

require_once __DIR__ . '/../app/Core/bootstrap.php';

$request = new Request();
$response = new Response();
$router = new Router($request, $response);
require APP_PATH . '/Routes/api.php';
$router->dispatch($request->method(), $request->uri());
