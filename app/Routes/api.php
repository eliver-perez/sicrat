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
$router->put('/api/persons/{id}', [\App\Controllers\PersonIdentityController::class, 'update'], 'persons.update');
$router->post('/api/persons/{id}/participations', [\App\Controllers\PersonIdentityController::class, 'participation'], 'persons.participate');
$router->post('/api/persons/duplicates', [\App\Controllers\PersonIdentityController::class, 'duplicates'], 'persons.read');
$router->get('/api/organizations/{id}/identity-policy', [\App\Controllers\PersonIdentityController::class, 'policy'], 'persons.read');
$router->put('/api/organizations/{id}/identity-policy', [\App\Controllers\PersonIdentityController::class, 'policy'], 'identity.configure');

$router->get('/api/users/{id}/accesses', [\App\Controllers\UserAccessController::class, 'show'], 'users.read');
$router->post('/api/users/{id}/accesses', [\App\Controllers\UserAccessController::class, 'assign'], 'users.create');
$router->put('/api/users/{id}/person', [\App\Controllers\UserAccessController::class, 'link'], 'users.create');
$router->put('/api/users/{id}/state', [\App\Controllers\UserAccessController::class, 'state'], 'users.create');

$router->post('/api/person-imports', [\App\Controllers\PersonImportController::class, 'upload'], 'persons.import');
$router->put('/api/person-imports/{id}/mapping', [\App\Controllers\PersonImportController::class, 'configure'], 'persons.import');
$router->post('/api/person-imports/{id}/preview', [\App\Controllers\PersonImportController::class, 'preview'], 'persons.import');
$router->put('/api/person-imports/{id}/decisions', [\App\Controllers\PersonImportController::class, 'decisions'], 'persons.import');
$router->post('/api/person-imports/{id}/confirm', [\App\Controllers\PersonImportController::class, 'confirm'], 'persons.import');
$router->post('/api/person-imports/{id}/apply', [\App\Controllers\PersonImportController::class, 'apply'], 'persons.import');
$router->get('/api/person-imports/{id}', [\App\Controllers\PersonImportController::class, 'status'], 'persons.import');

$router->get('/api/person-imports/{id}/results', [\App\Controllers\PersonImportController::class, 'results'], 'persons.import');

// Fase 3: los servicios validan además permisos y alcance en cada contexto afectado.
$router->get('/api/structures', [\App\Controllers\StructureController::class,'index'], 'structure.read');
$router->post('/api/structures', [\App\Controllers\StructureController::class,'store'], 'structure.admin');
$router->get('/api/structures/{id}', [\App\Controllers\StructureController::class,'show'], 'structure.read');
$router->post('/api/structures/{id}/elections', [\App\Controllers\StructureController::class,'associate'], 'structure.admin');
$router->post('/api/structures/{id}/clone', [\App\Controllers\StructureController::class,'clone'], 'structure.admin');
$router->post('/api/structures/{id}/members', [\App\Controllers\StructureController::class,'add'], 'structure.form');
$router->get('/api/structures/{id}/members/{member}', [\App\Controllers\StructureController::class,'detail'], 'structure.read');
$router->put('/api/structures/{id}/members/{member}/level', [\App\Controllers\StructureController::class,'level'], 'structure.admin');
$router->put('/api/structures/{id}/members/{member}/state', [\App\Controllers\StructureController::class,'state'], 'structure.admin');
$router->post('/api/structures/{id}/members/{member}/transfers', [\App\Controllers\StructureController::class,'transfer'], 'structure.transfer');
$router->get('/api/structures/{id}/transfers', [\App\Controllers\StructureController::class,'requests'], 'structure.read');
$router->put('/api/structures/{id}/transfers/{transfer}', [\App\Controllers\StructureController::class,'resolve'], 'structure.approve');
$router->get('/api/structures/{id}/history', [\App\Controllers\StructureController::class,'history'], 'structure.admin');
$router->get('/api/structure-levels', [\App\Controllers\AdministrationController::class,'levels'], 'structure.read');
$router->post('/api/structure-levels', [\App\Controllers\AdministrationController::class,'level'], 'levels.admin');
$router->put('/api/structure-levels/{level}', [\App\Controllers\AdministrationController::class,'level'], 'levels.admin');
$router->get('/api/structure-config', [\App\Controllers\AdministrationController::class,'configuration'], 'levels.admin');
$router->put('/api/structure-config', [\App\Controllers\AdministrationController::class,'configuration'], 'levels.admin');
$router->get('/api/permissions', [\App\Controllers\AdministrationController::class,'permissions'], 'permissions.admin');
$router->get('/api/roles', [\App\Controllers\AdministrationController::class,'roles'], 'permissions.admin');
$router->post('/api/roles', [\App\Controllers\AdministrationController::class,'role'], 'permissions.admin');
$router->put('/api/roles/{role}', [\App\Controllers\AdministrationController::class,'role'], 'permissions.admin');
$router->get('/api/roles/{role}/permissions', [\App\Controllers\AdministrationController::class,'rolePermission'], 'permissions.admin');
$router->put('/api/roles/{role}/permissions', [\App\Controllers\AdministrationController::class,'rolePermission'], 'permissions.admin');
$router->get('/api/users/{id}/permissions', [\App\Controllers\AdministrationController::class,'individual'], 'permissions.admin');
$router->put('/api/users/{id}/permissions', [\App\Controllers\AdministrationController::class,'individual'], 'permissions.admin');
$router->get('/api/users/{id}/territories', [\App\Controllers\AdministrationController::class,'territories'], 'permissions.admin');
$router->put('/api/users/{id}/territories', [\App\Controllers\AdministrationController::class,'territories'], 'permissions.admin');
$router->get('/api/organizations/{id}/modules', [\App\Controllers\AdministrationController::class,'modules'], 'modules.select');
$router->put('/api/organizations/{id}/modules/{module}', [\App\Controllers\AdministrationController::class,'module'], 'modules.select');


// Servicios esenciales de Fase 4: independientes de los módulos opcionales.
$router->get('/api/audit/options', [\App\Controllers\CommonServicesController::class,'auditOptions'], 'audit.read');
$router->get('/api/audit', [\App\Controllers\CommonServicesController::class,'audit'], 'audit.read');
$router->get('/api/approvals', [\App\Controllers\CommonServicesController::class,'approvals'], 'approvals.read');
$router->get('/api/approvals/{id}', [\App\Controllers\CommonServicesController::class,'approval'], 'approvals.read');
$router->put('/api/approvals/{id}', [\App\Controllers\CommonServicesController::class,'approval'], 'approvals.resolve');
$router->post('/api/approvals/{id}/cancel', [\App\Controllers\CommonServicesController::class,'cancelApproval'], 'approvals.read');
$router->post('/api/files', [\App\Controllers\CommonServicesController::class,'upload'], 'files.upload');
$router->delete('/api/files/{id}', [\App\Controllers\CommonServicesController::class,'deleteFile'], 'files.delete');
$router->get('/media/{id}', [\App\Controllers\CommonServicesController::class,'media'], 'authenticated');
$router->get('/api/notifications', [\App\Controllers\CommonServicesController::class,'notifications'], 'authenticated');
$router->post('/api/notifications/read', [\App\Controllers\CommonServicesController::class,'readNotifications'], 'authenticated');
$router->get('/api/notification-config', [\App\Controllers\CommonServicesController::class,'notificationConfiguration'], 'notifications.admin');
$router->put('/api/notification-config', [\App\Controllers\CommonServicesController::class,'notificationConfiguration'], 'notifications.admin');
$router->get('/api/notification-deliveries', [\App\Controllers\CommonServicesController::class,'deliveries'], 'notifications.admin');
$router->get('/api/exports', [\App\Controllers\CommonServicesController::class,'exports'], 'authenticated');
$router->post('/api/exports', [\App\Controllers\CommonServicesController::class,'exports'], 'exports.create');
$router->get('/api/exports/{id}/download', [\App\Controllers\CommonServicesController::class,'exportDownload'], 'exports.download');
$router->get('/api/jobs', [\App\Controllers\CommonServicesController::class,'jobs'], 'jobs.read');
$router->get('/api/backups', [\App\Controllers\CommonServicesController::class,'backups'], 'backups.admin');
$router->post('/api/backups', [\App\Controllers\CommonServicesController::class,'backups'], 'backups.admin');
$router->get('/api/backup-policy', [\App\Controllers\CommonServicesController::class,'backupPolicy'], 'backups.admin');
$router->put('/api/backup-policy', [\App\Controllers\CommonServicesController::class,'backupPolicy'], 'backups.admin');
$router->post('/api/backups/{id}/verify', [\App\Controllers\CommonServicesController::class,'verifyBackup'], 'backups.admin');
$router->put('/api/backups/{id}/protect', [\App\Controllers\CommonServicesController::class,'protectBackup'], 'backups.admin');
$router->get('/api/backups/{id}/restore', [\App\Controllers\CommonServicesController::class,'restorePreview'], 'backups.admin');
$router->post('/api/backups/{id}/restore', [\App\Controllers\CommonServicesController::class,'restore'], 'backups.admin');

$router->post('/api/jobs/{id}/{action}', [\App\Controllers\CommonServicesController::class,'administerJob'], 'jobs.admin');
