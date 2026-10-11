**SICRAT tiene una base reutilizable y puede evolucionar mediante cambios incrementales.** Antes de ampliar sus módulos, conviene corregir la validación de sesiones en la API, la autorización de operaciones, el aislamiento entre organizaciones y varios fallos funcionales comprobados.

La revisión fue de solo lectura: examiné código, dependencias, SQL y metadatos de la base local; ejecuté comprobaciones de sintaxis y consultas de diagnóstico. **No modifiqué archivos, datos, configuraciones ni estructuras; no instalé dependencias ni generé o ejecuté migraciones.** Git permanece sin cambios y ambos archivos SQL están intactos.

**1. Estado actual del proyecto y funcionalidades identificadas**

SICRAT es una aplicación PHP propia, sin un framework principal. Su organización permite continuar desarrollando sobre las capas existentes:

| Capa | Ubicación | Estado observado |
|---|---|---|
| Entradas web | `public/index.php` y directorios por módulo | Cargan sesión, vista y layout. |
| API | `public/api.php`, `app/Routes/api.php` | Router propio con 32 rutas registradas. |
| Controladores | `app/Controllers` | Reciben solicitudes y construyen servicios/repositorios. |
| Servicios | `app/Services` | Validación, transformación de datos y transacciones. |
| Repositorios | `app/Repositories` | Consultas mediante PDO. |
| Vistas | `app/Views` | PHP, layout compartido y JavaScript por módulo. |
| Infraestructura | `app/Core`, `app/Auth`, `app/Middleware` | HTTP, cifrado, sesiones y utilidades. |
| Base de datos | `database` | SQL original y catálogo geográfico; no existe todavía `/database/migrations`. |

La separación de capas es aprovechable, aunque hay duplicación de inicialización y componentes incompletos o heredados de otros modelos.

Las funcionalidades encontradas son:

| Funcionalidad | Estado real |
|---|---|
| Autenticación | Login desarrollado: verifica contraseña y usuario activo, regenera la sesión, genera token y registra la sesión en BD. |
| Cierre de sesión | Implementado con marcado de destrucción en BD y eliminación de sesión PHP; presenta inconsistencias de validación y manejo de errores. |
| Perfil y contraseña | Consulta del perfil propio y cambio de contraseña implementados; existe un fallo al comunicar cambios rechazados. |
| Organizaciones | Listado, alta, consulta y presentación de procesos asociados. |
| Procesos electorales | Listado, catálogos y alta desde una organización. Detalle incompleto; fechas se registran como `NULL`. |
| Usuarios | Listado y alta con asignación inicial a un proceso. No existe un flujo completo para administrar varias participaciones de una cuenta existente. |
| Personas | Alta básica y listado por sección/búsqueda. No registra participación electoral ni responsabilidades estructurales. |
| Secciones electorales | Listado y alta. Detalle incompleto. |
| Catálogos geográficos | Consultas parcialmente implementadas, con fallos en localidades y colonias. |
| Casillas | Tabla y pantalla inicial; falta implementación de operaciones y API. |
| Estructuras | Tablas diseñadas, sin controladores, servicios ni interfaz operativa encontrados. |
| Dashboard | Pantalla base, sin indicadores funcionales. |
| PWA | Manifest y registro de service worker; este no implementa caché ni funcionamiento sin conexión. |
| Encuestas, interacciones, tareas, metas, mensajería, incidencias y eventos | Existen tablas; no encontré implementación funcional de los módulos. |
| Auditoría y archivos | Existen tablas y algunas utilidades; no servicios integrados que las utilicen. |

La base local consultada contiene **62 tablas, todas InnoDB**. No encontré diferencias de nombres de tablas o columnas respecto de `database/database.sql`; esto no constituye una comparación exhaustiva de todos los tipos, índices y restricciones.

Datos locales relevantes:

- 1 organización y 1 proceso electoral.
- 2 usuarios y 1 asociación usuario–proceso.
- 2 personas, sin registros en `personas_procesos`.
- Ningún nodo de estructura.
- 64 registros de estados: cada uno de los 32 nombres aparece dos veces.

No encontré una suite propia de pruebas, configuración de PHPUnit/Pest ni dependencias de desarrollo instaladas para pruebas. Las verificaciones realizadas fueron:

- Sintaxis correcta en **81 archivos PHP**, usando **PHP 8.5.7 local**.
- Sintaxis correcta en **15 archivos JavaScript**.
- Comprobación de métodos asociados a las rutas.
- Consultas de repositorios y comprobaciones de integridad mediante lectura.

**No ejecuté login, logout, altas ni cambios de contraseña**, porque modifican datos o sesiones. Por tanto, no certifico esos flujos mediante pruebas de integración.

**2. Componentes que podemos conservar y reutilizar**

Recomiendo conservar:

- **La organización por controladores, servicios y repositorios.** Permite adaptar los módulos sin sustituir toda la aplicación.
- **Las cuentas y el mecanismo principal de login:** `password_hash`/`password_verify`, regeneración del identificador de sesión, tokens aleatorios y almacenamiento del HMAC del token.
- **`usuarios_sesiones`**, que ya contempla expiración, actividad, destrucción y motivo de cierre.
- **Organizaciones, procesos y catálogos electorales.** La relación organización–procesos ya admite varias elecciones.
- **`usuarios_procesos` y `personas_procesos`.** Son las relaciones necesarias para compartir una cuenta o una persona entre elecciones.
- **Personas, domicilios, canales de contacto y consentimientos**, como base de identidad dentro de cada organización.
- **Permisos, tipos de usuario y sus asociaciones**, ampliando su semántica y aplicación.
- **`estructura_relaciones`**, como base para consultar ancestros y descendientes, una vez que exista mantenimiento transaccional de la jerarquía.
- **Las tablas de módulos futuros**, sujetas a completar sus reglas e integridad.
- **El layout, componentes visuales y utilidades compartidas**, corrigiendo el tratamiento de datos al renderizarlos.

También son reutilizables el cliente HTTP y el servicio de cifrado con Sodium. Este último valida la llave y ofrece cifrado autenticado; encontré su inicialización, pero no su uso para proteger campos o archivos.

Las dependencias instaladas coinciden con el archivo de bloqueo:

| Dependencia | Versión instalada |
|---|---|
| `tecnickcom/tcpdf` | 6.11.3 |
| `chillerlan/php-qrcode` | 6.0.1 |
| `chillerlan/php-settings-container` | 3.3.0 |

No encontré una incompatibilidad que justifique reemplazarlas en esta etapa.

**3. Problemas técnicos comprobados**

Los siguientes hallazgos distinguen comprobación por lectura del código y reproducción mediante consultas o ejecución aislada.

**Autenticación, autorización y aislamiento**

| Hallazgo | Evidencia y ubicación | Consecuencia |
|---|---|---|
| La API no valida el token de BD, su expiración ni su revocación de manera general. | [Router.php](/opt/homebrew/var/www/sicrat/app/Core/Router.php:109), [Auth.php](/opt/homebrew/var/www/sicrat/app/Core/Auth.php:9) y [rutas](/opt/homebrew/var/www/sicrat/app/Routes/api.php:21). Los controladores protegidos suelen comprobar únicamente `SICRAT_ID`. | Una sesión PHP existente puede continuar siendo aceptada por la API aunque el registro de sesión haya expirado o sido revocado. |
| Falta autorización por operación en la API. | [UsersController.php](/opt/homebrew/var/www/sicrat/app/Controllers/UsersController.php:94), [UsersService.php](/opt/homebrew/var/www/sicrat/app/Services/UsersService.php:64). El tipo solicitado se transmite al alta sin comprobar qué tipos puede asignar el actor. | Ocultar “Super Administrador” en el selector no impide solicitar ese tipo directamente a la API. |
| Los listados de usuarios y personas no están restringidos por organización, elección o responsabilidad. | [UsersRepository.php](/opt/homebrew/var/www/sicrat/app/Repositories/UsersRepository.php:19), [PersonsRepository.php](/opt/homebrew/var/www/sicrat/app/Repositories/PersonsRepository.php:18). | El código permite consultas sin aislamiento entre clientes. No fue posible reproducir un cruce real porque la base local solo contiene una organización. |
| No se comprueba que la organización seleccionada corresponda al proceso ni que el actor tenga acceso a ellos. | [UsersService.php](/opt/homebrew/var/www/sicrat/app/Services/UsersService.php:98), [PersonsService.php](/opt/homebrew/var/www/sicrat/app/Services/PersonsService.php:79). | Las claves foráneas garantizan existencia, pero no pertenencia ni autorización. |
| La validación web ignora la expiración. | [WebSession.php](/opt/homebrew/var/www/sicrat/app/Auth/WebSession.php:109). Consulta `expira_en`, pero no lo evalúa. [Session.php](/opt/homebrew/var/www/sicrat/app/Auth/Session.php:93) tampoco evalúa expiración ni destrucción. | Las clases de sesión aplican políticas diferentes. |
| El middleware disponible no está integrado y tiene referencias incompatibles con este proyecto. | [AuthMiddleware.php](/opt/homebrew/var/www/sicrat/app/Middleware/AuthMiddleware.php:13): utiliza `$CFG` y `DatabaseConnection`; esta clase no está disponible. | No basta con activar el archivo: necesita adaptación a la infraestructura existente. |
| Las revocaciones individuales no prevalecen sobre permisos heredados. | [WebSession.php](/opt/homebrew/var/www/sicrat/app/Auth/WebSession.php:159). Une únicamente permisos con `valor = 1`; además, no filtra `permisos_usuarios.organizacion`. | Un permiso heredado sigue concedido aunque exista una revocación individual. |
| La selección entre varias elecciones/roles está incompleta. | [AuthController.php](/opt/homebrew/var/www/sicrat/app/Controllers/AuthController.php:193), [WebSession.php](/opt/homebrew/var/www/sicrat/app/Auth/WebSession.php:49). Se preparan roles disponibles y se redirige a `/select-role/`, pero no encontré esa pantalla ni su operación de selección. | La cuenta está preparada parcialmente para varias elecciones, pero el flujo no está terminado. |
| Las redirecciones de denegación no detienen la ejecución. | [WebSession.php](/opt/homebrew/var/www/sicrat/app/Auth/WebSession.php:200), utilizado por las entradas web. | Después de emitir `Location`, continúa la construcción de la respuesta. |

**Tratamiento de entradas y respuestas**

- **Hay inserción de datos sin escape en HTML y JavaScript.** Ejemplos: [listado de usuarios](/opt/homebrew/var/www/sicrat/public/assets/js/users/index.js:170), [listado de personas](/opt/homebrew/var/www/sicrat/public/assets/js/persons/index.js:194), [perfil](/opt/homebrew/var/www/sicrat/public/assets/js/profile/index.js:25) y [header](/opt/homebrew/var/www/sicrat/app/Views/layout/header.php:177). El dashboard también inserta `$_GET['callBack']` directamente dentro de JavaScript en [index.php](/opt/homebrew/var/www/sicrat/app/Views/dashboard/index.php:13). Son puntos de exposición a XSS; no ejecuté cargas de explotación.

- **No encontré validación CSRF ni comprobación de origen para operaciones que modifican datos.** Es una ausencia comprobada en la aplicación. No verifiqué posibles controles externos del VPS ni la configuración efectiva de cookies.

- **Se devuelven detalles internos de excepciones al cliente**, incluyendo SQL y, en algunos casos, archivo y línea. Ejemplo: [LocationController.php](/opt/homebrew/var/www/sicrat/app/Controllers/LocationController.php:51). Desactivar `APP_DEBUG` no elimina mensajes incluidos expresamente en el JSON.

**Fallos funcionales y de persistencia**

| Hallazgo | Comprobación y ubicación |
|---|---|
| La búsqueda de usuarios no filtra. | **Reproducido:** una búsqueda sin coincidencias devolvió ambos usuarios. El `AND usuario LIKE` se agrega al `ON` del `LEFT JOIN`, en [UsersRepository.php](/opt/homebrew/var/www/sicrat/app/Repositories/UsersRepository.php:43). Además, el repositorio ignora `limit` y `offset`. |
| La validación de usuario duplicado consulta el correo. | [UsersService.php](/opt/homebrew/var/www/sicrat/app/Services/UsersService.php:81) pasa el nombre de usuario a [userExists()](/opt/homebrew/var/www/sicrat/app/Repositories/UsersRepository.php:173), que consulta `email`. La restricción única de BD evita duplicados de `usuario`, pero la validación previa es incorrecta. |
| Localidades consulta una columna inexistente. | **Reproducido:** error por `cp`; la columna real es `codigo_postal`. [LocationRepository.php](/opt/homebrew/var/www/sicrat/app/Repositories/LocationRepository.php:152). |
| El filtro de colonias tiene un parámetro incorrecto. | **Reproducido:** error `HY093`. La consulta usa `:localidad`, pero los parámetros contienen `municipio`. [LocationRepository.php](/opt/homebrew/var/www/sicrat/app/Repositories/LocationRepository.php:221). |
| Hay una ruta geográfica mal formada y controladores que ignoran el identificador de ruta. | Falta `/` en `/api/municipalities/{id}localities`, en [api.php](/opt/homebrew/var/www/sicrat/app/Routes/api.php:50). Localidades y colonias leen `id` de query en [LocationController.php](/opt/homebrew/var/www/sicrat/app/Controllers/LocationController.php:90). |
| Cuatro rutas apuntan a métodos inexistentes. | `ElectoralProcessController::show`, `SectionsController::show`, `PersonsController::show` y `LocationController::storeNeighborhood`. Comprobado mediante inspección de los métodos registrados. |
| Ajustes consulta tablas inexistentes. | **Reproducido:** no existe `ajustes`; tampoco están `ajustes_tipo` ni `ajustes_empresas` en la base revisada. [SettingsRepository.php](/opt/homebrew/var/www/sicrat/app/Repositories/SettingsRepository.php:13). No encontré uso operativo de este servicio. |
| El cambio de contraseña puede anunciar éxito aunque se rechace. | El servicio devuelve `changed = false` si la contraseña actual no coincide, pero [ProfilesController.php](/opt/homebrew/var/www/sicrat/app/Controllers/ProfilesController.php:100) responde `success = true`; [el cliente](/opt/homebrew/var/www/sicrat/public/assets/js/profile/index.js:65) muestra éxito. |
| El estado `0` se normaliza como `NULL`. | **Reproducido en memoria:** [normalizeOptionalInt()](/opt/homebrew/var/www/sicrat/app/Core/Service.php:58). Se usa para organización y proceso, cuyos estados no admiten `NULL`. En usuarios, el alta ignora el estado solicitado e inserta siempre `1`. |
| La consulta de personas sin sección produce `TypeError`. | **Reproducido en memoria:** [PersonsService.php](/opt/homebrew/var/www/sicrat/app/Services/PersonsService.php:30) convierte `NULL` mediante una función que exige string. También transforma el desplazamiento `0` en `NULL`. |
| Faltan vistas referenciadas. | Encontré 18 referencias a `view.php`, `add.php` o `edit.php` inexistentes en seis módulos. Ejemplo: [users/index.php](/opt/homebrew/var/www/sicrat/public/users/index.php:20). |
| La captura de personas no enlaza con una elección. | [PersonsService.php](/opt/homebrew/var/www/sicrat/app/Services/PersonsService.php:89) inserta únicamente en `personas`. La base local confirma personas sin participaciones. |
| El catálogo de estados contiene duplicados. | **Reproducido mediante `SELECT`:** los 32 nombres aparecen dos veces. El esquema no contiene una clave natural única ni el importador original es idempotente. |

Otros defectos localizados tienen menor impacto porque no encontré uso actual: `WebSession::getTipoId()` devuelve string pese a declarar `?int`; `Model` llama a `Database::connection()`, que no existe; `public/init.php` requiere archivos ausentes; y `UsersRepository::findById()` consulta una tabla `personal` ajena al esquema revisado.

**Integridad referencial**

La base tiene numerosas claves foráneas útiles. Sin embargo, faltan relaciones en:

- `personas_procesos.estatus_contacto_id` y `nivel_apoyo_id`.
- `conversaciones_usuarios.conversacion`, `usuario` y `ultimo_mensaje_leido`.
- `campanas_mensajes.creado_por`.
- `eventos_asistentes.registrado_por`.

Estas ausencias están presentes tanto en el SQL original como en los metadatos locales.

Además, las relaciones existentes no garantizan que persona, proceso, estructura, encuesta, evento o campaña pertenezcan al mismo contexto. Tampoco impiden ciclos en estructuras ni aseguran coherencia de su tabla de ancestros.

Las consultas realizadas no detectaron cruces entre organizaciones o procesos, pero **el conjunto local es demasiado pequeño para validar esas reglas**.

**4. Diferencias entre la arquitectura actual y los requerimientos**

| Requerimiento | Base existente | Diferencia pendiente |
|---|---|---|
| Varias elecciones por organización y cuenta | `procesos_electorales`, `usuarios_procesos` | Completar asignación de cuentas existentes, selección/cambio de contexto y validación de membresía activa. |
| Personas únicas por organización | `personas.organizacion`, UUID único | El UUID identifica registros, pero no impide registrar dos veces a la misma persona. Falta identificación, normalización y conciliación de duplicados. |
| Participación en varias elecciones | `personas_procesos`, único por proceso/persona | Falta utilizarla desde los servicios y las pantallas. |
| Estructuras independientes, clonadas o compartidas | `estructura` pertenece directamente a un proceso | Falta una entidad que represente la estructura y su asociación con elecciones; tampoco hay clonación o historial. |
| Integrantes sin cuenta | Personas independientes de usuarios | `estructura.usuario` es obligatorio: el integrante del nodo exige una cuenta. |
| Niveles configurables e independientes de roles | `estructura.nivel` es numérico y separado de `tipo_usuario` | Falta catálogo/configuración de niveles y reglas jerárquicas. |
| Permisos globales o por elección, con concesiones y revocaciones | Tipos, permisos y asociaciones | Falta ámbito electoral explícito, precedencia de revocaciones y evaluación centralizada. |
| Alcance según responsabilidades | `personas_responsables`, `estructura_relaciones` | No existe aplicación del alcance a las consultas. |
| Módulos autorizados y seleccionables | Helper `moduleEnabled()` | No existe catálogo ni persistencia de habilitación, límites o administración. |
| Servicios compartidos | Tablas de archivos/auditoría y utilidades | Falta integrar auditoría, aprobaciones, archivos privados, notificaciones, exportaciones y trabajos. |

Para identidad, conviene definir reglas por organización sobre CURP, clave de elector u otros identificadores disponibles. **No recomiendo considerar el nombre como identificador único ni fusionar automáticamente coincidencias ambiguas.**

**5. Cambios y migraciones considerados necesarios, sin implementarlos**

Las siguientes son propuestas de evolución; no se han generado archivos ni SQL de migración.

| Tablas actuales | Tratamiento propuesto |
|---|---|
| `organizaciones`, `procesos_electorales`, catálogos electorales | Conservar; completar validación, fechas, administración y coherencia del contexto. |
| `usuarios`, `usuarios_procesos` | Conservar cuentas y hashes; completar administración de participaciones y distinguir privilegio global de rol electoral. |
| `usuarios_sesiones` | Conservar; unificar validación de expiración/revocación y revisar índices para las consultas reales. |
| `personas`, domicilios, contactos, consentimientos | Conservar; añadir reglas de identidad por organización y revisar qué información es personal o electoral. |
| `personas_procesos`, apoyo e interacciones | Reutilizar; completar claves foráneas, validaciones y servicios. |
| `usuarios_tipos`, `permisos` y asociaciones | Adaptar para acciones concretas, ámbitos y revocaciones; no requiere sustituir inicialmente todo el modelo de roles. |
| `estructura`, relaciones y responsables | Adaptar progresivamente para personas, estructuras identificables y participación en elecciones. |
| Estados, municipios, localidades, colonias y códigos postales | Conservar; conciliar duplicados y establecer importaciones repetibles y claves naturales apropiadas. |
| Secciones y casillas | Conservar; revisar su vigencia y asignación electoral antes de Jornada Electoral. |
| Encuestas, tareas, metas, mensajes, eventos e incidencias | Reutilizar el esquema existente; completar reglas, contexto, trazabilidad e implementación. |
| `archivos`, `auditoria` | Reutilizar para los servicios compartidos. El vínculo genérico entidad–identificador necesita validación en aplicación. |

Las entidades adicionales previsibles son:

- **Estructuras:** contenedor de estructura por organización, vínculo estructura–elección, catálogo de niveles, integrantes basados en personas y procedencia de clonaciones. Puede conservarse `estructura` como base de nodos.
- **Vínculo opcional cuenta–persona:** permite que una persona tenga cuenta sin exigirla a todos los integrantes.
- **Permisos:** asociaciones y excepciones por ámbito, incluyendo elección. Si se requieren varios roles simultáneos, se añadirá su relación; el requerimiento actual no obliga a asumirlo.
- **Módulos:** catálogo, dependencias, autorizaciones del Super Administrador y selección del cliente limitada por esas autorizaciones.
- **Servicios compartidos:** aprobaciones e historial, notificaciones y entregas, trabajos/reintentos y solicitudes de exportación.
- **Jornada Electoral:** configuración por elección, asignaciones de representantes, reportes de jornada, resultados, actas, revisiones y versiones.
- **Transporte:** vehículos, conductores, viajes y asignaciones; el indicador existente `requiere_transporte` no constituye ese módulo.
- **Peticiones:** solicitudes, responsables, estados y seguimiento.
- **Finanzas e inventario:** cuentas/categorías, movimientos, presupuestos, artículos, almacenes, existencias y movimientos.

Para estructuras compartidas, hay que definir expresamente qué se comparte: jerarquía, integrantes, responsabilidades o todos ellos. Una clonación debe conservar procedencia y generar registros independientes; una estructura compartida necesita reglas claras sobre cómo afectan sus cambios a cada elección.

Las futuras migraciones deberían seguir esta secuencia:

1. Mantener el SQL original como referencia intacta.
2. Establecer una línea base de la base existente.
3. Añadir entidades y columnas antes de retirar dependencias antiguas.
4. Rellenar asociaciones conservando identificadores y relaciones.
5. Conciliar duplicados y revisar inconsistencias antes de imponer restricciones.
6. Añadir índices, claves foráneas y unicidad después de validar los datos.
7. Retirar elementos antiguos únicamente en una fase posterior expresamente aprobada.

La conciliación del catálogo geográfico deberá preservar referencias existentes; no conviene borrar duplicados directamente.

**6. Riesgos, dependencias e incompatibilidades**

| Componente | Diagnóstico |
|---|---|
| Ubuntu 24.04 | No encontré dependencias del código que lo hagan incompatible. No se verificó el VPS. |
| PHP 8.3 | La sintaxis utilizada y los requisitos declarados de las dependencias son compatibles. **No se realizó validación de ejecución con PHP 8.3:** el binario local disponible es 8.5.7. |
| Nginx | Compatible con la arquitectura, pero requiere enrutamiento específico para `/api/*` hacia `public/api.php` y para las entradas web. Un fallback general al dashboard no resuelve ambas rutas. PHP se sirve mediante FastCGI/PHP-FPM. [Documentación de Nginx](https://nginx.org/en/docs/beginners_guide.html). |
| Base local de SICRAT | **MariaDB 10.4.21**, InnoDB, `utf8mb4_general_ci`. Versión obtenida mediante consulta al servidor. |
| Base del VPS de Adariel | **Motor y versión exacta no comprobados.** La versión local no permite inferirlos. |
| Compatibilidad del SQL con el VPS | Pendiente de identificar el motor real, versión, modo SQL, charset y collation. |

No hay evidencia que justifique actualizar o reemplazar Ubuntu, Nginx, PHP 8.3 o el motor utilizado por Adariel.

Riesgos y dependencias adicionales:

- **Charset inconsistente:** [Database.php](/opt/homebrew/var/www/sicrat/app/Core/Database.php:23) conecta con `charset=utf8`, mientras la base es `utf8mb4`. Además, no utiliza `DB_PORT` ni `DB_CHARSET` de la configuración alternativa.
- **JSON depende del motor:** auditoría usa columnas JSON. MariaDB las representa como un alias de `LONGTEXT`, mientras MySQL tiene una representación propia; las futuras migraciones y consultas deben ajustarse al motor confirmado. [Documentación de MariaDB](https://mariadb.com/docs/server/reference/data-types/string-data-types/json).
- **Extensiones necesarias:** `pdo_mysql`, `mbstring`, `sodium` y `curl`, además de las habituales de PHP. Están disponibles localmente; no comprobé su disponibilidad en el VPS.
- **Ausencia de pruebas de regresión:** especialmente relevante al corregir sesiones, permisos y asociaciones electorales.
- **Carga de consultas:** se permiten límites de hasta cinco millones de filas y el listado de usuarios carece de paginación efectiva. Esto puede consumir recursos compartidos con Adariel.
- **Frontend sin proceso de compilación documentado:** hay recursos compilados y metadatos de Vite, pero no un proyecto fuente completo ni instrucciones reproducibles de construcción.
- **Despliegue insuficientemente documentado:** faltan instrucciones de Nginx, configuración de ejemplo y verificación del entorno.
- **Política de contraseñas inconsistente:** altas y cambios recortan espacios, mientras el login no lo hace. Conviene definir un tratamiento uniforme y límites compatibles con bcrypt, que procesa hasta 72 bytes. [Manual de PHP](https://www.php.net/manual/es/function.password-hash.php).
- **Política de sesiones pendiente:** actualmente cada login revoca sesiones anteriores en BD. Debe conservarse o cambiarse según la política elegida, pero aplicarse de forma consistente en web y API.
- **Protección compartida del VPS:** el futuro despliegue deberá aislar el sitio, credenciales, almacenamiento y consumo de procesos de SICRAT. No inspeccioné ni intervine en la instalación o servicios de Adariel.

**7. Fases propuestas, ordenadas por dependencias técnicas**

| Fase | Trabajo propuesto | Dependencia / criterio de salida |
|---|---|---|
| 1. Establecer verificación | Confirmar entorno real, documentar despliegue y preparar pruebas de los flujos existentes. | Motor del VPS identificado y ejecución verificada en PHP 8.3, sin alterar Adariel. |
| 2. Corregir seguridad y fallos actuales | Unificar validación de sesiones, aplicar autorización y aislamiento en API; corregir consultas, rutas, respuestas, estados y escape de datos. | Flujos actuales con pruebas de acceso permitido/denegado, expiración y revocación. |
| 3. Completar organización, identidad y elecciones | Crear línea base de migraciones; conciliar catálogos; completar personas únicas, participaciones y selección electoral. | Una cuenta y una persona operan en varias elecciones sin duplicarse ni cruzar organizaciones. |
| 4. Implementar estructuras | Niveles configurables, integrantes sin cuenta, jerarquías, clonación y asociación compartida con elecciones. | Integridad jerárquica y reglas de compartición verificadas. |
| 5. Completar permisos, alcances y módulos | Permisos globales/electorales, revocaciones, responsabilidades y habilitación de módulos. | Las mismas políticas se aplican en consultas, operaciones y navegación. |
| 6. Integrar servicios compartidos | Auditoría, aprobaciones, archivos privados, notificaciones, exportaciones y procesamiento en segundo plano. | Servicios reutilizables con trazabilidad, autorización y reintentos controlados. |
| 7. Desarrollar módulos operativos | Interacciones, encuestas, tareas, metas, eventos, peticiones, incidencias, transporte y mensajería. | Uso de participaciones, alcances y servicios compartidos existentes. |
| 8. Jornada y recursos | Jornada Electoral, resultados y actas; finanzas e inventario como líneas de trabajo separables. | Fundamentos anteriores estables y reglas específicas de cada módulo definidas. |

La recomendación es **adaptar y completar lo existente**, empezando por los controles que protegerán todos los módulos posteriores. Las tablas futuras ya presentes deben aprovecharse como diseño inicial, sin presentarlas todavía como funcionalidades terminadas.

**La revisión queda concluida. Espero tus instrucciones antes de realizar cualquier modificación o implementación.**