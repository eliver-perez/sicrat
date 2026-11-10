# Dashboard operativo de SICRAT

## Arquitectura y contexto

`GET /api/dashboard?days=7|15|30` usa `DashboardController` y `Services/Dashboard/Summary`. Devuelve únicamente indicadores, series agregadas, actividad resumida y pendientes autorizados. No acepta una cuenta, organización ni estructura seleccionada por el cliente. Usa la identidad autenticada y la elección activa del servidor.

No se añadieron permisos. `PermissionRepository`, `ScopeRepository`, `Operations/Context`, `Jornada/Context` y `Common/ResourceRegistry` siguen siendo las autoridades de acceso. Los módulos deshabilitados se excluyen. Cada agregado aplica el alcance **antes** de contar o agrupar. Se comprueba tanto el permiso de lectura como el de la acción para pendientes.

Sin elección, una cuenta normal recibe un mensaje y sus notas personales; no recibe datos operativos. El superadministrador puede consultar tres cifras administrativas globales: organizaciones, elecciones activas y cuentas activas. Sus datos operativos requieren una elección concreta.

La vista usa el layout, encabezado y menú existentes. Se retiraron las tarjetas cuyo único propósito era abrir módulos. `InitializeValues` y los callbacks históricos del layout siguen disponibles.

## Indicadores y fuentes

Se muestran como máximo seis, ordenados por relevancia operativa y filtrados por permisos/módulos. Son cifras de todo el ámbito autorizado; la lista de pendientes, en cambio, reúne las asignaciones y solicitudes que debe atender la cuenta.

| Indicador | Fuente y condición |
| --- | --- |
| Personas en mi ámbito | `personas`, participación activa en `personas_procesos` o proyección activa en `estructura_integrantes_procesos`, elección actual y `personas.ver` |
| Mi estructura | Proyecciones, integrantes, contenedores y asociación electoral activos; `estructura.ver`. Dato secundario: incorporaciones activas del periodo |
| Tareas pendientes | `tareas`, alcance de `tareas.ver`, excluye `completada` y `cancelada` |
| Incidencias abiertas | `incidencias`, alcance de `incidencias.ver`, excluye `cerrada` y `cancelada` |
| Peticiones abiertas | `peticiones`, alcance de `peticiones.ver`, excluye `cerrada` y `cancelada` |
| Visitas realizadas | `interacciones` de tipo `VISITA`, asociación en `interacciones_procesos`, alcance de `interacciones.ver` y fecha del periodo |
| Casillas capturadas | `jornada_casillas`, `jornada_resultados` y versión actual; casillas activas del alcance autorizado por `Jornada/Queries::permission`. Dato secundario: total esperado |

Cuando hay más de seis candidatos, se priorizan Jornada, tareas, incidencias, personas, estructura y peticiones; visitas aparece cuando hay espacio. No se codifican combinaciones por nombre de rol.

## Gráficas

Se utiliza ApexCharts 4.7.0, ya incluido en `public/template/assets`. No hay nueva dependencia ni descarga CDN. Se seleccionan hasta tres series con datos, en este orden: avance de Jornada, actividad por día, incorporaciones a estructura, tareas por estado, incidencias por estado, visitas y peticiones por estado.

Las series diarias cubren 7, 15 o 30 días naturales, incluido hoy, en la zona de la aplicación. Se rellenan con cero los días sin registros y se excluyen fechas fuera del intervalo. Las incorporaciones corresponden a integrantes actualmente activos y visibles: no representan una reconstrucción histórica del tamaño total. No se calculan promedios de resolución con timestamps incompletos.

Jornada muestra categorías excluyentes: sin captura, capturadas por validar y validadas. No devuelve votos ni fotografías. Cada gráfica ofrece una lista desplegable con sus cifras; si la librería no carga, esa lista queda abierta como alternativa accesible.

## Actividad reciente

Se reutiliza `auditoria`, sin tablas ni eventos paralelos. Se muestran hasta 12 eventos. Cada evento debe cumplir simultáneamente:

- Organización y elección autorizadas.
- Registro fuente legible con el permiso y alcance de su módulo.
- Autor igual a la cuenta actual o integrante visible de la estructura electoral con `estructura.ver`.

Se incluyen fuentes de Personas, Estructura, Tareas, Incidencias, Peticiones, Interacciones y Jornada. Las acciones se traducen a frases españolas y los datos anteriores/nuevos, motivos privados, IDs, UUID y nombres técnicos nunca se devuelven a la tarjeta.

Los eventos de estructura compartida con `proceso=NULL` sólo se incluyen para acciones de integrante conocidas, con el integrante visible y la elección presente en la instantánea `informacion.elecciones` del propio evento. No se atribuyen altas o ediciones globales de Personas a una elección sin evidencia: sus eventos electorales de participación sí pueden aparecer. Los eventos de módulos no contemplados o sin contexto verificable se omiten.

## Pendientes

La lista reúne hasta 12 entradas, priorizando urgentes o vencidas, después registros con fecha límite y finalmente los demás.

- Tareas: asignación/subtarea propia pendiente, en proceso o corrección; tarea visible, permiso `tareas.completar` y alcance sobre la persona asignada. Se conserva la compatibilidad con asignaciones históricas de cuenta.
- Incidencias y peticiones: responsable vinculado a la cuenta (también asignación histórica de cuenta en incidencias), estado editable, lectura y edición autorizadas. No se inventan fechas límite.
- Aprobaciones: fuentes de Personas y módulos operativos disponibles; requiere `aprobaciones.ver`, `aprobaciones.resolver`, permisos de validación del recurso, alcances de lectura/resolución y ser el aprobador del primer paso pendiente. Se excluyen solicitudes expiradas y pasos posteriores. Las asignaciones de tareas verifican también el alcance de la persona del ítem.
- Jornada: casillas asignadas sin captura durante jornada abierta; casillas con propuestas pendientes o versión preliminar vigente para quien puede validar.

Las tareas y casos enlazan al detalle existente mediante `operations/?module=…&record=…`. Jornada enlaza a su pantalla. Aprobaciones enlaza a Servicios; esa pantalla no dispone de enlace directo a una solicitud. No se alteraron sus flujos de resolución. Los traslados de estructura y flujos financieros conservan sus bandejas actuales; no se incorporan como pendientes en esta versión.

## Notas privadas y recordatorios

Rutas autenticadas:

- `GET/POST /api/dashboard/notes`.
- `GET/PUT/DELETE /api/dashboard/notes/{uuid}`.

`Services/Dashboard/Notes` obtiene siempre al propietario de la sesión. Todas las consultas y modificaciones incluyen `usuario = cuenta autenticada`; el superadministrador **no** tiene excepción. Un UUID ajeno recibe 404. No se acepta propietario desde el formulario.

Se eligieron notas generales de cuenta, independientes de la elección, porque son recordatorios personales y no registros operativos. Texto de hasta 1.000 caracteres, recordatorio opcional, realizada/pendiente, fijada, creación y modificación. La lista pagina de 50 en 50, primero pendientes/fijadas y después fecha de recordatorio. La UI permite crear, editar, eliminar con confirmación, completar/reabrir y fijar/desfijar. Renderiza texto, nunca HTML del usuario.

El recordatorio se introduce en hora local del navegador y se guarda en UTC; las fechas devueltas tienen sufijo `Z`. Las notas no generan avisos push, correo ni una cola programada: la fecha se muestra y se destaca cuando está vencida. No se copia el contenido a auditoría general. Ediciones simultáneas siguen la política sencilla de última escritura; no hay historial de versiones.

## Migración e índices

Nueva migración formal: `database/migrations/20261109_014_dashboard.php`. No se modifica `database/database.sql`.

- Nueva tabla `dashboard_notas`, FK a `usuarios`, UUID único e índice `(usuario, realizada, fijada, recordatorio, id)` para aislar y localizar notas. Las expresiones de orden pueden requerir ordenar el conjunto de una cuenta.
- `auditoria`: índice `(organizacion, proceso, f_registro, id)` para reducir candidatos por contexto/fecha. Los permisos y JSON del contexto compartido se verifican después mediante predicados, por lo que no se promete evitar todo ordenamiento.
- `estructura_integrantes_procesos`: índice `(proceso, activo, fecha, persona)` para agregación electoral y periodo.

Se conserva el resto de índices de claves y relaciones existentes. Todas las cifras y series se calculan en SQL; las listas tienen límites. No se consultan servicios por cada fila. Los ítems de aprobación usan búsquedas correlacionadas por ID, sin materializar una unión de todas las asignaciones.

`PermissionRepository::duringRead` reutiliza el resultado efectivo por conexión/cuenta/elección únicamente dentro del resumen. Un `finally` descarta la instantánea; no usa sesión, disco ni una caché entre peticiones. No cambia reglas ni prioridades de permisos. `ResourceRegistry::listingPredicate` admite una lista opcional de fuentes internas; sus consumidores anteriores mantienen el comportamiento original.

La prueba con cinco módulos operativos registró **62 consultas preparadas fijas**, incluidas las verificaciones de organización, elección y módulos de los servicios existentes. Añadir movimientos no aumenta ese número. No son 62 cargas de listas: la mayoría son validaciones pequeñas; las agregaciones son acotadas por contexto. El coste cambia según módulos habilitados, no según cantidad de filas presentadas. Antes de grandes volúmenes reales conviene medir planes y tiempos con la distribución de datos del despliegue; la prueba funcional no es un benchmark de producción.

## Responsive y PWA

Indicadores en columnas iguales ocupan todo el ancho: hasta seis en escritorio, tres/dos en tamaños intermedios y uno en 320 px. Los paneles se emparejan con gráficas en escritorio y se intercalan en una columna en móvil. Las listas y textos largos se adaptan sin tablas anchas. El estilo está limitado a clases/IDs del Dashboard.

Las lecturas del resumen y la lista de notas se incorporaron a la lista permitida del worker existente con `network-first` y ETag. Se mantienen cifrado, aislamiento por cuenta/organización/elección, comprobación de permisos y autorización máxima de 12 horas. Aunque las notas sean generales, cada copia local pertenece al perfil electoral vigente. No hay otro almacenamiento ni capa offline.

Una pestaña abierta puede mostrar datos guardados con fecha de copia y controles de notas deshabilitados. Al reabrir sin Internet se usa `offline.html`: lectura de las consultas cifradas previamente guardadas, no HTML autenticado almacenado. Las escrituras siguen requiriendo conexión; el worker invalida sus lecturas privadas tras una escritura exitosa. El plazo offline no se renueva por abrir el Dashboard. Detalles adicionales en `PWA_OFFLINE.md`.

## Validación y límites

Comandos reproducibles (usar la configuración de una instancia de pruebas):

```sh
php tests/integration.php --dashboard
php tests/integration.php --pwa
node tests/pwa-unit.js
php bin/build-pwa.php
```

`integration.php` crea y elimina una base desechable, aplica todas las migraciones y levanta su propio servidor. La suite Dashboard cubre elección activa/ausente/global, permisos revocados, ausencia completa de componentes, ámbitos propios/descendientes, actividad de rama ajena, pendientes mixtos y pasos de aprobación, privacidad de notas frente a otra cuenta y superadministrador, CRUD, fechas, fijado, estados sin información, Jornada, conteo fijo de consultas y ETag entre cuentas. Chrome prueba las gráficas reales, CRUD, escape de HTML, encabezado/menú, anchos 320/390/768/1440, ausencia de overflow y desconexión tanto de pestaña como de service worker.

Las capturas y logs se dejan en el directorio temporal que imprime el runner. Safari/iPhone, PWA instalada en iOS y Android físicos requieren prueba manual; emular tamaños en Chrome no equivale a probar esos navegadores. Rigen también las limitaciones de almacenamiento/expulsión de datos documentadas para la PWA.

La migración se aplicó únicamente en bases desechables de pruebas. Debe aplicarse por el procedimiento normal antes de utilizar esta versión sobre una base existente. No se hicieron commits ni despliegues.


## Archivos de esta entrega

| Área | Archivos nuevos o modificados |
| --- | --- |
| API y datos | `app/Controllers/DashboardController.php`, `app/Services/Dashboard/Summary.php`, `app/Services/Dashboard/Notes.php`, `app/Routes/api.php` |
| Reutilización de autorización | `app/Repositories/PermissionRepository.php`, `app/Services/Common/ResourceRegistry.php` |
| Interfaz | `app/Views/dashboard/index.php`, `public/assets/js/dashboard/index.js`, `public/assets/css/dashboard.css` |
| PWA | `app/Pwa/ConditionalResponse.php`, `public/assets/js/pwa/policy.js`, `public/assets/js/pwa/offline.js`, `public/offline.html`, `public/pwa-assets.js` |
| Esquema | `database/migrations/20261109_014_dashboard.php`, `database/migrations/README.md` |
| Pruebas | `tests/dashboard.php`, `tests/dashboard-browser.js`, `tests/integration.php`, `tests/phase3-browser.js`, `tests/pwa-unit.js`, `tests/README.md` |
| Documentación | `DASHBOARD.md`, `PWA_OFFLINE.md` |

Resultado de la suite combinada: **252 comprobaciones HTTP/BD**, incluidas **100 específicas de Dashboard**; Chrome ejecutó **21 de Dashboard**, **40 de persistencia PWA** y **72 de regresión PWA**. Las **16 comprobaciones unitarias** de políticas/deltas también pasaron. Sintaxis PHP/JS y `git diff --check` sin errores. Los ensayos usaron MariaDB 10.4.21 y Chrome local; no la base configurada de producción.
