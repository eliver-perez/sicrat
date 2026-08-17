<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

$router->post('/api/auth/login', [AuthController::class, 'login']);                                                                                         // ✅
$router->post('/api/auth/logout', [AuthController::class, 'logout']);                                                                                       // ✅
$router->get('/api/auth/me', [AuthController::class, 'me']);                                                                                                // ✅

/**
 * ORGANIZATION ROUTES
 */

$router->get('/api/organizations', [OrganizationsController::class, 'index']);                                                                              // ✅
$router->get('/api/organizations/{id}', [OrganizationsController::class, 'show']);                                                                          // ✅
$router->post('/api/organizations', [OrganizationsController::class, 'store']);                                                                             // ✅

$router->get('/api/organizations/{id}/electoral-process', [OrganizationsController::class, 'getElectoralProcesses']);                                       // ✅
$router->post('/api/organizations/{id}/electoral-process', [ElectoralProcessController::class, 'store']);                                                   // ✅

/**
 * ELECTORAL PROCESS ROUTES ROUTES
 */

$router->get('/api/electoral-process', [ElectoralProcessController::class, 'index']);                                                                       // ✅
$router->get('/api/electoral-process-type', [ElectoralProcessController::class, 'types']);                                                                  // ✅
$router->get('/api/electoral-process-character', [ElectoralProcessController::class, 'characters']);                                                        // ✅
$router->get('/api/electoral-process/{id}', [ElectoralProcessController::class, 'show']);                                                                   

/**
 * LOCATION ROUTES
 */
$router->get('/api/states', [LocationController::class, 'states']);                                                                                         // ✅
$router->get('/api/states/{id}/municipalities', [LocationController::class, 'municipalities']);                                                             // ✅
$router->get('/api/municipalities/{id}localities', [LocationController::class, 'localities']);                                                              // ✅
$router->get('/api/localities/{id}/neighborhoods', [LocationController::class, 'neighborhoods']);                                                           // ✅

$router->post('/api/states', [LocationController::class, 'storeState']);                                                                          
$router->post('/api/states/{id}/municipalities', [LocationController::class, 'storeMunicipality']);                                                           
$router->post('/api/municipalities/{id}/localities', [LocationController::class, 'storeLocality']);                                                                   
$router->post('/api/localities/{id}/neighborhoods', [LocationController::class, 'storeNeighborhood']);                                                            

/**
 * GENDER ROUTES
 */
$router->get('/api/genders', [GenderController::class, 'index']);                                                                                           // ✅

/**
 * SECTIONS ROUTES ROUTES
 */

$router->get('/api/sections', [SectionsController::class, 'index']);                                                                                        // ✅
$router->get('/api/sections/{id}', [SectionsController::class, 'show']);                                                                   
$router->post('/api/sections', [SectionsController::class, 'store']);                                                                                       // ✅

/**
 * PERSONS ROUTES ROUTES
 */

$router->get('/api/persons', [PersonsController::class, 'index']);                                                                                          // ✅
$router->get('/api/persons/{id}', [PersonsController::class, 'show']);                                                                                      
$router->post('/api/persons', [PersonsController::class, 'store']);                                                                                         // ✅

/**
 * USERS ROUTES ROUTES
 */

$router->get('/api/users', [UsersController::class, 'index']);                                                                                              // ✅
$router->post('/api/users', [UsersController::class, 'store']);                                                                                             // ✅
$router->get('/api/users-types', [UsersTypesController::class, 'index']);                                                                                   // ✅

/**
 * PROFILE ROUTES ROUTES
 */

$router->get('/api/profiles/{id}', [ProfilesController::class, 'show']);                                                                                      // ✅
$router->put('/api/profiles/{id}/change-password', [ProfilesController::class, 'updatePassword']);                                                            // ✅