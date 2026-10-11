# Informe de Fase 1 — Saneamiento y seguridad del núcleo

Fecha: 11 de octubre de 2026.

## Estado inicial y alcance

Se revisaron nuevamente código, rutas, esquema local y Git antes de editar. El único archivo ajeno nuevo era `Diagnostico.md`, no rastreado; se preservó íntegramente. Los hallazgos principales continuaban presentes. La base local tenía 62 tablas, 1 organización, 1 proceso, 2 usuarios y 2 personas, en MariaDB 10.4.21.

Objetivo de compatibilidad confirmado por el usuario: Ubuntu 24.04, Nginx 1.24.0, PHP 8.3.6, MySQL 8.0.46 y utf8mb4. Se conserva PHP propio, PDO y Controlador → Servicio → Repositorio. No se introdujo un framework ni se trabajó en fases posteriores.

## CORREGIDO

- Sesión validada desde BD en páginas y API: token HMAC, usuario activo, expiración, revocación y pertenencia del rol activo. Se conserva la política de invalidar sesiones anteriores al iniciar sesión y las duraciones existentes de 8 horas/30 días.
- `WebSession` reutiliza `Session` y el validador común. Se eliminaron las referencias incompatibles del middleware y las redirecciones de denegación ahora terminan la ejecución.
- Cookie propia `SICRATSESSID`, HttpOnly, SameSite=Lax y Secure cuando la URL/configuración utiliza HTTPS. El cambio de nombre exige volver a iniciar sesión una vez; las cuentas y hashes se conservan.
- Autorización central por operación, basada en los accesos de las pantallas existentes. La API aplica esos controles antes de ejecutar controladores; el Super Administrador conserva su capacidad global. No se habilitó administración de usuarios para roles que no tenían ese acceso en la interfaz.
- Los permisos individuales se filtran por la organización autorizada. Sus revocaciones prevalecen sobre concesiones individuales/heredadas para los usuarios ordinarios. El Super Administrador conserva acceso global explícito.
- Consultas de personas, usuarios, organizaciones y procesos restringidas por el contexto autorizado. Para procesos se respeta la asignación activa existente; no se implementó un cambio de contexto electoral. Se validan organización/proceso y estado/municipio antes de insertar relaciones.
- CSRF común con token de sesión y cabecera AJAX del mismo origen. Se protege también el login. GET legítimos permanecen disponibles para sesiones autorizadas. El mecanismo sigue el patrón de token sincronizado descrito por [OWASP](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html).
- Escape HTML/JSON en vistas y valores GET; escape en tablas y presentación mediante texto en perfil y avisos. Los datos se conservan sin sanitizarlos al almacenarlos.
- Respuestas centralizadas sin SQL, rutas, líneas ni trazas. Los detalles se registran mediante el mecanismo PHP existente, incluso cuando APP_DEBUG está activo.
- Conexión utiliza DB_PORT y DB_CHARSET, con utf8mb4 por defecto. No se convirtieron tablas ni se alteraron servicios.
- Búsqueda de usuarios corregida; comprobación de duplicados por `usuario`; estado solicitado respetado en altas; organización conservada aunque no se asigne un proceso.
- Localidades usa `codigo_postal`; colonias utiliza el parámetro correcto; rutas geográficas corregidas y sus IDs utilizados como padres de las operaciones.
- Listados y procesos asociados tienen LIMIT/OFFSET, orden estable, máximo de 100 filas y controles de página. Los selectores recorren páginas acotadas para no perder opciones. El área de tabla permite desplazamiento sin ocultar los controles.
- Cero preservado en estados y desplazamiento. Sección omitida admitida en consultas; UUID/IDs inválidos se rechazan como validación.
- Cambio de contraseña rechazado devuelve fracaso real; nuevas contraseñas conservan espacios y se evita el truncamiento de bcrypt. Verificación exacta primero y compatibilidad con hashes antiguos creados recortando espacios; no se regeneraron hashes existentes.
- Cuatro rutas sin implementación y sin consumidor actual fueron retiradas: GET de detalle de proceso/sección/persona y POST de colonia. No se construyeron CRUD adicionales. Los accesos web ya no intentan incluir 18 vistas inexistentes. La creación electoral se dirige al flujo existente en Organizaciones y Casillas muestra su estado pendiente.
- Ajustes heredados no consultan tablas inexistentes: el adaptador devuelve ausencia y el servicio conserva sus valores por defecto. No se crearon tablas de configuración.
- Se corrigieron métodos auxiliares inconsistentes: tipo de sesión, conexión del Model y consulta heredada de usuarios sobre `personal`.

## NUEVO HALLAZGO

- El refresco de personas tras un alta no ocurría si no cambiaban los filtros; ahora permite refresco explícito y reinicia la página.
- El estado del formulario de usuarios se restablecía usando un ID de checkbox inexistente; se corrigió el ID.
- Las altas geográficas existentes dependían de un catálogo de países inexistente y transmitían claves incompatibles al repositorio; se adaptaron al esquema actual sin añadir entidades.
- El perfil propio estaba restringido innecesariamente a dos permisos en la página; ahora es accesible para el usuario autenticado y el servicio sigue rechazando perfiles ajenos.

## PENDIENTE PARA OTRA FASE

- Selector multielección definitivo, administración de participaciones, personas_procesos e identidad/conciliación configurable: Fase 2. Una cuenta con varios accesos no recibe uno elegido automáticamente; no se redirige a una pantalla inexistente.
- Estructuras, niveles, permisos completos por elección, alcances jerárquicos y módulos: Fase 3.
- Ajustes/configuración funcional, auditoría, archivos, aprobaciones, notificaciones y trabajos: según las fases aprobadas correspondientes. Los adaptadores actuales no equivalen a esos módulos.
- Duplicados geográficos e integridad adicional del esquema para módulos no operativos: no se tocaron durante esta fase.
- Administración de casillas y módulos operativos, finanzas, inventario y Jornada Electoral: permanecen sin desarrollar.
- Pruebas integrales de rendimiento, validación visual completa y ejecución en el entorno exacto de producción: Fase 8.

## NO REPRODUCIDO / límites de comprobación

No había dos organizaciones en la base de trabajo para reproducir allí un cruce real. La prevención se verificó con dos organizaciones y procesos en una base aislada. No se detectaron diferencias de nombres de tablas/columnas frente al esquema original.

No se ejecutó en PHP 8.3.6, MySQL 8.0.46 o Nginx del VPS: el PHP local disponible es 8.5.7 y la BD local es MariaDB 10.4.21. El SQL utilizado evita construcciones exclusivas de MariaDB, pero esto no sustituye la prueba con el motor objetivo. No se certifica despliegue ni rendimiento en producción.

## Migraciones y dependencias

No se creó `/database/migrations`, no se generaron ni ejecutaron migraciones y no se añadieron dependencias. Ambos SQL, composer.json, composer.lock y Diagnostico.md conservaron sus hashes iniciales. No se intervino en Adariel ni en el VPS.

## Pruebas ejecutadas

| Comprobación | Resultado |
|---|---|
| Sintaxis PHP, aplicación y pruebas | 91 archivos, sin errores |
| Sintaxis JavaScript propio y pruebas | 17 archivos, sin errores |
| `php tests/run.php` | 29 comprobaciones correctas |
| `node tests/frontend.js` | 25 comprobaciones correctas |
| `php tests/integration.php` | 83 comprobaciones HTTP/BD correctas |
| Revisión de whitespace del diff respetando CRLF | Sin errores |
| Hashes de archivos protegidos | Sin cambios |

HTTP/BD incluyó login y política de sesión única; sesión válida, expirada, revocada y cuenta desactivada; logout; cambio de contraseña correcto/rechazado; permisos permitidos/denegados; revocaciones y organización; aislamiento de lecturas y altas; búsquedas y paginación; localidades y colonias; altas existentes; relaciones padre/hijo; perfil propio/ajeno; CSRF correcto/incorrecto; XSS en presentación; y un error técnico provocado únicamente en la base desechable con APP_DEBUG activo.

Las bases `sicrat_phase1_test_*` generadas por cada ejecución se eliminaron al terminar. Logs/cookies/sesiones ficticias quedaron en directorios temporales privados para revisión. La prueba JavaScript utiliza adaptadores DOM/jQuery, no un navegador real. Instrucciones en [tests/README.md](tests/README.md).

## Decisiones y estado de Git

- Los permisos de Fase 1 reproducen los accesos existentes, no anticipan nuevos derechos administrativos por cliente.
- Las respuestas de validación utilizan 422; acceso denegado 403; sesión inválida 401; recurso ajeno/no disponible 404; fallo técnico 500. Los clientes AJAX existentes fueron adaptados a las correcciones necesarias.
- Se conservaron estilos y finales de línea originales. La eliminación de duplicación de sesiones/errores fue funcional, no una reforma estética.
- No se hicieron commits. El diff contiene cambios de Fase 1 y archivos de soporte de pruebas/informe. El archivo del usuario Diagnostico.md sigue no rastreado y sin modificar.
- La Fase 2 no se inició.

## Inventario de archivos

El inventario exacto de archivos modificados y añadidos se incluye a continuación.

### Modificados (67)

- `app/Auth/Session.php`
- `app/Auth/WebSession.php`
- `app/Controllers/AuthController.php`
- `app/Controllers/ElectoralProcessController.php`
- `app/Controllers/GenderController.php`
- `app/Controllers/LocationController.php`
- `app/Controllers/OrganizationsController.php`
- `app/Controllers/PersonsController.php`
- `app/Controllers/ProfilesController.php`
- `app/Controllers/SectionsController.php`
- `app/Controllers/UsersController.php`
- `app/Controllers/UsersTypesController.php`
- `app/Core/Auth.php`
- `app/Core/Database.php`
- `app/Core/Model.php`
- `app/Core/Response.php`
- `app/Core/Router.php`
- `app/Core/Service.php`
- `app/Core/init.php`
- `app/Middleware/AuthMiddleware.php`
- `app/Repositories/ElectoralProcessRepository.php`
- `app/Repositories/LocationRepository.php`
- `app/Repositories/OrganizationsRepository.php`
- `app/Repositories/PersonsRepository.php`
- `app/Repositories/SectionsRepository.php`
- `app/Repositories/SettingsRepository.php`
- `app/Repositories/UsersRepository.php`
- `app/Routes/api.php`
- `app/Services/ElectoralProcessService.php`
- `app/Services/LocationService.php`
- `app/Services/OrganizationsService.php`
- `app/Services/PersonsService.php`
- `app/Services/ProfilesService.php`
- `app/Services/SectionsService.php`
- `app/Services/UsersService.php`
- `app/Support/helpers.php`
- `app/Views/auth/index.php`
- `app/Views/dashboard/index.php`
- `app/Views/electoral-process/index.php`
- `app/Views/layout/footer.php`
- `app/Views/layout/header.php`
- `app/Views/layout/sidebar-menu.php`
- `app/Views/organizations/index.php`
- `app/Views/persons/index.php`
- `app/Views/polling-booth/index.php`
- `app/Views/profile/index.php`
- `app/Views/sections/index.php`
- `app/Views/users/index.php`
- `public/api.php`
- `public/assets/js/autenticar.js`
- `public/assets/js/electoral-process/index.js`
- `public/assets/js/global.js`
- `public/assets/js/organizations/index.js`
- `public/assets/js/persons/index.js`
- `public/assets/js/profile/index.js`
- `public/assets/js/sections/index.js`
- `public/assets/js/sweetalert.js`
- `public/assets/js/users/index.js`
- `public/autenticacion/index.php`
- `public/electoral-process/index.php`
- `public/init.php`
- `public/organizations/index.php`
- `public/persons/index.php`
- `public/polling-booth/index.php`
- `public/profile/index.php`
- `public/sections/index.php`
- `public/users/index.php`

### Añadidos (14)

- `app/Auth/Authorization.php`
- `app/Auth/SessionValidator.php`
- `app/Core/HttpError.php`
- `app/Core/Pagination.php`
- `app/Core/Security/Csrf.php`
- `app/Core/Security/PasswordPolicy.php`
- `app/Core/bootstrap.php`
- `public/assets/js/security.js`
- `tests/frontend.js`
- `tests/integration.php`
- `tests/router.php`
- `tests/run.php`
- `tests/README.md`
- `FASE1.md`
