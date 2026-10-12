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
$router->post('/api/persons', [\App\Controllers\PersonIdentityController::class, 'store'], 'persons.create');

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

$router->get('/api/auth/elections', [\App\Controllers\ElectoralContextController::class, 'index'], 'authenticated');
$router->post('/api/auth/election', [\App\Controllers\ElectoralContextController::class, 'select'], 'authenticated');

$router->get('/api/persons/{id}', [\App\Controllers\PersonIdentityController::class, 'show'], 'persons.read');
$router->put('/api/persons/{id}', [\App\Controllers\PersonIdentityController::class, 'update'], 'persons.create');
$router->post('/api/persons/{id}/participations', [\App\Controllers\PersonIdentityController::class, 'participation'], 'persons.create');
$router->post('/api/persons/duplicates', [\App\Controllers\PersonIdentityController::class, 'duplicates'], 'persons.read');
$router->get('/api/organizations/{id}/identity-policy', [\App\Controllers\PersonIdentityController::class, 'policy'], 'persons.read');
$router->put('/api/organizations/{id}/identity-policy', [\App\Controllers\PersonIdentityController::class, 'policy'], 'identity.configure');

$router->get('/api/users/{id}/accesses', [\App\Controllers\UserAccessController::class, 'show'], 'users.read');
$router->post('/api/users/{id}/accesses', [\App\Controllers\UserAccessController::class, 'assign'], 'users.create');
$router->put('/api/users/{id}/person', [\App\Controllers\UserAccessController::class, 'link'], 'users.create');

$router->post('/api/person-imports', [\App\Controllers\PersonImportController::class, 'upload'], 'persons.create');
$router->put('/api/person-imports/{id}/mapping', [\App\Controllers\PersonImportController::class, 'configure'], 'persons.create');
$router->post('/api/person-imports/{id}/preview', [\App\Controllers\PersonImportController::class, 'preview'], 'persons.create');
$router->put('/api/person-imports/{id}/decisions', [\App\Controllers\PersonImportController::class, 'decisions'], 'persons.create');
$router->post('/api/person-imports/{id}/confirm', [\App\Controllers\PersonImportController::class, 'confirm'], 'persons.create');
$router->post('/api/person-imports/{id}/apply', [\App\Controllers\PersonImportController::class, 'apply'], 'persons.create');
$router->get('/api/person-imports/{id}', [\App\Controllers\PersonImportController::class, 'status'], 'persons.create');

$router->get('/api/person-imports/{id}/results', [\App\Controllers\PersonImportController::class, 'results'], 'persons.create');
