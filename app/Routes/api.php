<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\OrganizationsController;
use App\Controllers\ElectoralProcessController;
use App\Controllers\LocationController;
use App\Controllers\SectionsController;
use App\Controllers\GenderController;
use App\Controllers\PersonsController;
use App\Controllers\UsersController;
use App\Controllers\UsersTypesController;
use App\Controllers\ProfilesController;
use App\Core\Response;

$router->post('/api/auth/login', [AuthController::class, 'login'], 'public');
$router->post('/api/auth/logout', [AuthController::class, 'logout'], 'authenticated');
$router->get('/api/auth/me', [AuthController::class, 'me'], 'authenticated');

/**
 * ORGANIZATION ROUTES
 */

$router->get('/api/organizations', [OrganizationsController::class, 'index'], 'organizations.read');
$router->get('/api/organizations/{id}', [OrganizationsController::class, 'show'], 'organizations.read');
$router->post('/api/organizations', [OrganizationsController::class, 'store'], 'organizations.create');

$router->get('/api/organizations/{id}/electoral-process', [OrganizationsController::class, 'getElectoralProcesses'], 'organizations.read');
$router->post('/api/organizations/{id}/electoral-process', [ElectoralProcessController::class, 'store'], 'processes.create');

/**
 * ELECTORAL PROCESS ROUTES ROUTES
 */

$router->get('/api/electoral-process', [ElectoralProcessController::class, 'index'], 'processes.read');
$router->get('/api/electoral-process-type', [ElectoralProcessController::class, 'types'], 'processes.read');
$router->get('/api/electoral-process-character', [ElectoralProcessController::class, 'characters'], 'processes.read');

/**
 * LOCATION ROUTES
 */
$router->get('/api/states', [LocationController::class, 'states'], 'catalogs.read');
$router->get('/api/states/{id}/municipalities', [LocationController::class, 'municipalities'], 'catalogs.read');
$router->get('/api/municipalities/{id}/localities', [LocationController::class, 'localities'], 'catalogs.read');
$router->get('/api/localities/{id}/neighborhoods', [LocationController::class, 'neighborhoods'], 'catalogs.read');

$router->post('/api/states', [LocationController::class, 'storeState'], 'catalogs.create');
$router->post('/api/states/{id}/municipalities', [LocationController::class, 'storeMunicipality'], 'catalogs.create');
$router->post('/api/municipalities/{id}/localities', [LocationController::class, 'storeLocality'], 'catalogs.create');

/**
 * GENDER ROUTES
 */
$router->get('/api/genders', [GenderController::class, 'index'], 'catalogs.read');

/**
 * SECTIONS ROUTES ROUTES
 */

$router->get('/api/sections', [SectionsController::class, 'index'], 'sections.read');
$router->post('/api/sections', [SectionsController::class, 'store'], 'sections.create');

/**
 * PERSONS ROUTES ROUTES
 */

$router->get('/api/persons', [PersonsController::class, 'index'], 'persons.read');
$router->post('/api/persons', [PersonsController::class, 'store'], 'persons.create');

/**
 * USERS ROUTES ROUTES
 */

$router->get('/api/users', [UsersController::class, 'index'], 'users.read');
$router->post('/api/users', [UsersController::class, 'store'], 'users.create');
$router->get('/api/users-types', [UsersTypesController::class, 'index'], 'users.read');

/**
 * PROFILE ROUTES ROUTES
 */

$router->get('/api/profiles/{id}', [ProfilesController::class, 'show'], 'profiles.self');
$router->put('/api/profiles/{id}/change-password', [ProfilesController::class, 'updatePassword'], 'profiles.self');
