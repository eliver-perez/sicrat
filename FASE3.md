# Fase 3 — Estructuras, roles, permisos y módulos

Fecha: 12 de octubre de 2026. Proyecto local: `/opt/homebrew/var/www/sicrat`.

## 1. Resumen de implementación

**IMPLEMENTADO.** Se amplió el proyecto existente conservando Controlador → Servicio → Repositorio, PDO, las sesiones y el contexto multielección. Se implementaron estructuras de personas, niveles electorales, consultas de coordinación, transferencias, aprobaciones específicas, clonación completa, historial, autorización con alcances y control de módulos.

Antes de editar se leyeron `Diagnostico.md`, `FASE1.md`, `FASE2.md` y las migraciones, se inspeccionaron los componentes relacionados, `git status` y `git diff`, y se ejecutó la línea base: 29 comprobaciones PHP, 25 JavaScript de Fase 1, 16 JavaScript de Fase 2 y 294 HTTP/BD.

No se incorporaron frameworks ni dependencias. No se modificaron los SQL originales, los informes anteriores ni las migraciones de Fase 2. No se intervino en Adariel o el VPS. No se inició Fase 4 ni se realizaron commits.

Las pantallas nuevas son `/structure/` y `/administration/`. Para comenzar a utilizar estructura, el Super Administrador debe autorizar el módulo para la organización y el administrador debe seleccionar su uso. La configuración de niveles y las operaciones se realizan en una elección de trabajo seleccionada.

## 2. Migraciones creadas

**IMPLEMENTADO Y APLICADO LOCALMENTE.**

| Archivo | Cambios principales |
|---|---|
| `database/migrations/20261012_003_autorizacion.php` | Unicidad nullable de `usuarios.persona`; identificadores de permisos de 128 caracteres y sus FK; contexto, alcance, editor y fecha; roles propios de organización; catálogo/configuración de módulos; territorios autorizados. |
| `database/migrations/20261012_004_estructuras.php` | Preflight histórico; contenedores; personas en `estructura`; asociaciones electorales; niveles; proyecciones activas e históricas; historial; configuración y solicitudes de transferencia; reutilización del cierre transitivo. |

Se utiliza el ejecutor de Fase 2, con bloqueo y registro de nombre/checksum. La reejecución no reaplica cambios y comprueba que los archivos registrados permanezcan intactos. No se añadieron migraciones automáticas a las peticiones web.

Se ensayaron primero en bases desechables, incluidas una con estructura histórica y otra restaurada desde la base local real. Después se aplicaron a `sicrat`. La comparación conservó los registros y campos originales de **65 tablas**, permitiendo únicamente las nuevas filas de catálogo/permisos y del registro de migraciones. La base pasó de 65 a 76 tablas.

Respaldo privado utilizado y restaurado durante el ensayo:

```text
/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat-phase3-backup-20261012-142246-327110/sicrat-before-phase3.sql
SHA-256: 291d4c5fd1a2afbc71d43ccd022fdc204fc5da5df4d489257fad3c8977265333
```

Ese archivo es una medida de conservación para esta intervención local; no se desarrolló un servicio de respaldos. El archivo temporal de credenciales se eliminó y la base de ensayo se descartó.

## 3. Corrección Persona ↔ Usuario

**CORREGIDO.** `uq_usuarios_persona` hace única cada persona no nula, conservando múltiples `NULL`: una persona tiene cero o una cuenta y una cuenta cero o una persona.

El preflight real encontró **0 personas con varias cuentas**. La migración rechaza un conflicto antes de crear la restricción; no elimina, fusiona ni reasigna cuentas. Las pruebas también verificaron ese rechazo con datos preparados.

El vínculo y la creación de cuenta comprueban organización, alcance, accesos afectados y cuenta existente. El índice protege también las carreras concurrentes. Una segunda vinculación devuelve 409 y revierte la operación. Se puede crear una cuenta desde una persona existente, incluso sin apellidos, sin inventar datos ni otra ficha.

`usuarios_procesos` continúa resolviendo la multielección de una sola cuenta. La creación o vinculación de cuenta no modifica niveles ni relaciones de responsabilidad.

## 4. Modelo final de estructuras

**IMPLEMENTADO.**

| Entidad | Función |
|---|---|
| `estructuras_organizacionales` | Contenedor de una organización, nombre, estado, versión y procedencia de clonación. |
| `estructuras_procesos` | Asociación del contenedor con una o varias elecciones, con estado y fechas. |
| `estructura` | Integrante identificado por persona, contenedor, responsable directo, estado y fechas. Conserva IDs y columnas antiguas. |
| `estructura_integrantes_procesos` | Nivel y estado electoral del integrante; conserva proyecciones históricas. |
| `niveles_organizacionales` | Catálogo configurable de cada elección. |
| `estructura_relaciones` | Relaciones de coordinación derivadas y profundidad. |
| `estructura_historial` | Registro específico de cambios estructurales y configuración. |
| `estructura_transferencias` | Solicitudes y resoluciones de transferencia. |
| `procesos_estructura_config` | Promoción por primera incorporación, aprobación y aprobador configurado. |

`estructura.usuario` y `estructura.proceso` admiten `NULL` para las nuevas incorporaciones. La identidad estructural depende de `persona` y `contenedor`; las columnas históricas no gobiernan las nuevas operaciones. `estructura.nivel` antiguo se conserva, mientras el nivel vigente proviene de la proyección electoral.

`personas_responsables` conserva sus registros y FK anteriores. No se convirtió en otra fuente de escritura para el responsable directo del nuevo modelo.

## 5. Estructuras independientes

**IMPLEMENTADO.** Una organización puede administrar distintos contenedores. Una persona puede aparecer en estructuras de elecciones diferentes utilizando el mismo registro de identidad.

El responsable y el cierre de relaciones pertenecen al contenedor. Mover un integrante en un contenedor no mueve sus registros en otro. Se comprobó la reutilización de personas y el aislamiento de las estructuras independientes.

## 6. Clonación

**IMPLEMENTADO.** La clonación copia todos los integrantes, incluidos los inactivos, sus estados, fechas y relaciones internas, con nuevos IDs/UUID de integrante y el mismo ID de persona. Copia el cierre de relaciones a los nuevos IDs y conserva `clonada_de`.

Se registran la procedencia y una instantánea histórica por integrante, incluidos sus niveles originales. Para la elección destino se utiliza una correspondencia de nivel por orden o la asignación explícita recibida. Si hay que crear niveles en destino, se exige `niveles.administrar`; se conservan las propiedades del nivel de origen. En una fuente compartida se prioriza el nivel de la elección de trabajo y, si no existe, la primera proyección disponible.

No se pregunta qué personas excluir ni se realizan exclusiones automáticas. Tampoco se mantiene sincronización entre copias. Se verificó independencia en ambas direcciones.

La elección destino debe permitir una ubicación activa para cada persona y tener una configuración de niveles compatible. Un conflicto revierte **toda** la copia; no se resuelve borrando o excluyendo integrantes. Esta restricción evita dos responsables efectivos de la misma persona en una elección.

## 7. Estructuras compartidas

**IMPLEMENTADO.** Varias elecciones se asocian al mismo contenedor y utilizan los mismos integrantes y relaciones físicas. Cada elección conserva su propia proyección de nivel. No se crean copias sincronizadas por elección.

Es posible combinar contenedores compartidos para determinados equipos con otros independientes. Asociar una elección verifica las ubicaciones activas existentes y los niveles. Retirar una asociación conserva los datos y fechas; reactivar una persona no reactiva silenciosamente asociaciones electorales retiradas.

Toda escritura compartida comprueba permiso, alcance y módulo en **todas las elecciones asociadas**. No basta con el permiso de la elección seleccionada. El mismo control se repite al aprobar una transferencia.

## 8. Integrantes sin cuenta

**IMPLEMENTADO.** Se incorporan personas con o sin usuario. No se crean usuarios ficticios. La ficha muestra si existe cuenta y permite crearla/vincularla cuando está autorizado.

El alta desde estructura busca primero personas existentes y ofrece alta rápida con las reglas de duplicados de Fase 2. La creación de identidad y la incorporación estructural forman una transacción: un fallo de jerarquía, permiso o proyección revierte ambas.

En estructuras compartidas se seleccionan las participaciones que se registrarán en `personas_procesos`. La pertenencia estructural común y la participación electoral son conceptos distintos. No se duplica la ficha ni se incorporan participaciones que no fueron seleccionadas.

## 9. Niveles organizacionales

**IMPLEMENTADO.** Cada elección configura nombre, orden, estado, capacidad para reclutar y permiso de relaciones del mismo nivel. No se codificaron nombres de cargos en las reglas.

Un integrante compartido puede tener niveles distintos por elección. El responsable puede tener un orden superior o el mismo orden cuando su configuración lo permite; una relación ascendente se rechaza. Se validan altas, transferencias, reasignaciones, cambios de nivel y cambios del catálogo.

La capacidad de reclutar y el permiso `estructura.formar` se comprueban por separado. La promoción automática está desactivada por defecto. Sólo puede ocurrir si la persona realiza su primera incorporación válida, tiene autorización, está en el último nivel y la elección la permite. Se elige el primer nivel habilitado para reclutar desde el final del catálogo. Una incorporación fallida revierte la promoción. No hay promociones por crear cuentas ni promociones adicionales posteriores.

## 10. Responsable directo y jerarquía

**IMPLEMENTADO.** Cada integrante tiene una sola relación directa. El índice único sobre `persona_activa` generada y elección impide que una persona tenga dos ubicaciones activas —y por tanto dos responsables efectivos— en ese contexto. Varias proyecciones inactivas permanecen disponibles como historia.

Se consultan responsable, superiores, equipo directo y descendientes. La línea y el equipo están paginados; la ficha muestra los totales. Los alcances se aplican antes de paginar.

El cierre transitivo contiene la relación propia con profundidad cero. Los ciclos se rechazan antes del movimiento. La profundidad describe distancia de coordinación y no determina el nivel organizacional.

## 11. Transferencias e historial

**IMPLEMENTADO.** Se puede transferir un integrante o su equipo completo. Mover sólo a alguien con subordinados requiere indicar quién asumirá el equipo directo. Mover el equipo conserva todas las relaciones internas y cambia únicamente su conexión externa; el cierre se actualiza por esa frontera, sin reconstruir toda la estructura.

Las solicitudes conservan solicitante, origen, destino, integrante, modalidad, reemplazo, aprobador, motivo, versión y fechas. La regla considera primero el aprobador configurado, después un superior común autorizado y finalmente un Super Administrador activo. En una estructura compartida, configuraciones de aprobador incompatibles deben resolverse explícitamente.

Se implementaron aprobación y rechazo. Se revalidan los permisos del solicitante y del aprobador y todos los alcances afectados. Una solicitud obsoleta se rechaza con 409 y debe volver a solicitarse. Un superior con permiso específico puede aprobar sin recibir el rol de administrador de estructura.

Las bajas son lógicas y conservan el último responsable y su línea histórica. La reactivación mantiene ese responsable; cambiarlo exige una transferencia posterior, con sus permisos y aprobación. Si el responsable anterior está inactivo, debe reactivarse primero. Si hay equipo directo, debe resolverse primero; su reasignación requiere también permiso para transferir equipos. Cuando las transferencias requieren aprobación, la baja no permite saltar ese flujo mediante un reemplazo directo. Cambiar el estado de alguien ya activo tampoco permite cambiar su responsable: debe utilizarse transferencia.

Retirar acceso, rol, permiso para formar estructura, vínculo de persona o estado de cuenta exige resolver antes los subordinados directos. No se eliminan equipos ni se producen bajas en cascada.

El historial registra altas, bajas, reactivaciones, cambios de responsable/nivel/configuración, asociaciones, clonaciones, solicitudes y resoluciones. Las escrituras son transaccionales y comparten bloqueo de organización para evitar carreras. No es un motor general de auditoría o aprobaciones.

## 12. Roles

**IMPLEMENTADO.** Se mantiene un solo rol base por `usuarios_procesos`. Se reutiliza `usuarios_tipos`, con nombres más amplios y roles propios de una organización. Existen API para crear/renombrar roles y configurar sus permisos; la pantalla permite crear roles y editar permisos del rol elegido.

Los roles base anteriores y sus identificadores permanecen. La base del administrador electoral concede las capacidades de esta fase; sus permisos de personas y cuentas tienen alcance de organización y los de estructura/configuración alcance de elección. Los demás roles anteriores conservan sus capacidades; el coordinador general conserva la gestión multielección de participaciones sin recibir captura de fichas.

El administrador autorizado puede configurar excepciones y roles dentro de sus permisos y alcances. No puede conceder capacidades mayores ni ampliar alcance cambiando a un rol que contiene los mismos identificadores de permiso. Los roles propios de otra organización no se pueden asignar a sus cuentas.

El nivel no se deriva del rol y el rol no cambia al modificar/promover el nivel. Super Administrador sigue siendo un privilegio global de cuenta; un rol electoral no lo concede.

## 13. Permisos globales

**IMPLEMENTADO.** El catálogo distingue ámbito global, electoral o ambos. Una concesión global individual aplica cuando el catálogo lo permite. Los permisos globales heredados se calculan desde el tipo global de cuenta, separados de las plantillas electorales.

La edición de permisos globales está reservada al Super Administrador. Los alcances de organización continúan sujetos a la organización de la cuenta; el Super Administrador conserva alcance global. Una revocación individual de la elección prevalece sobre una concesión global aplicable.

## 14. Permisos por elección

**IMPLEMENTADO.** El cálculo utiliza el acceso y rol reales de la elección indicada, exige cuenta/acceso/proceso activos y organización compatible, y considera únicamente su plantilla y sus excepciones electorales. Los permisos de una elección no se trasladan a otra al cambiar contexto.

Las comprobaciones de operaciones compartidas consultan los contextos afectados directamente en BD. La selección de trabajo facilita la interfaz; no sustituye la autorización de esos contextos.

## 15. Herencia, concesiones y revocaciones

**IMPLEMENTADO.** `PermissionRepository` centraliza el cálculo: herencia de rol, plantilla electoral, configuración específica de la elección, concesiones individuales y revocaciones individuales. Las revocaciones individuales prevalecen sobre cualquier concesión aplicable.

Las concesiones individuales determinan sus alcances, sustituyendo los heredados de ese permiso. Si hay concesiones globales y electorales aplicables, se consideran sus alcances; una denegación explícita sigue prevaleciendo. El valor cero de una entrada de rol retira esa concesión; no equivale a una revocación individual universal.

`captura-personas` se conserva como identificador histórico y se interpreta mediante permisos granulares de ver, crear, editar, participar e importar. Una configuración granular electoral explícita gobierna ese permiso y una revocación individual no se evade por el identificador antiguo. El identificador canónico del catálogo se utiliza también ante variaciones de mayúsculas.

Se registran editor y fecha de las configuraciones nuevas. Las sesiones refrescan derechos, alcances y persona vinculada desde BD sin cambiar tokens, expiración, revocación, contraseñas o CSRF.

## 16. Alcances

**IMPLEMENTADO.**

| Alcance | Información autorizada |
|---|---|
| Propio | Persona vinculada a la cuenta. |
| Directos | Subordinados directos activos en esa elección. |
| Descendientes | Equipo a cargo activo, con profundidad mayor que cero; excluye superiores, hermanos y la propia persona. |
| Territorio | Personas de secciones explícitamente autorizadas a usuario y elección. |
| Elección | Personas con participación o proyección de esa elección, incluido su historial. |
| Organización | Personas/cuentas de la organización autorizada. |

Los predicados se aplican en SQL a listados, búsquedas y coincidencias, y se comprueban en detalles y escrituras. Se protegen también los IDs de integrantes y cuentas. Las transferencias verifican todos los integrantes afectados, incluido el equipo que se reasigna y el reemplazo.

La importación exige alcance de organización para revisar identidades y coincidencias del padrón completo, además de los permisos necesarios para aplicar altas/ediciones. Se conserva el acceso anterior del capturador y toda la regresión CSV/XLSX.

## 17. Habilitación de módulos

**IMPLEMENTADO.** `modulos` es la fuente común del catálogo. Organización, usuarios/autenticación, personas, contexto y seguridad son esenciales y no se pueden apagar.

La disponibilidad de un módulo opcional exige autorización de organización y autorización electoral aplicable. La selección electoral puede concretar la selección de organización. Sólo el Super Administrador autoriza disponibilidad; el cliente selecciona dentro de esos límites. Seleccionar para toda la organización exige alcance y autorización suficiente en sus elecciones.

Apagar estructura oculta su navegación y bloquea operaciones API, incluso para un cambio compartido solicitado desde otra elección habilitada. Conserva integrantes, relaciones e historial. Los componentes esenciales siguen funcionando.

El catálogo prepara mensajería, encuestas, tareas/metas, eventos, peticiones/incidencias, transporte, finanzas, inventario y Jornada Electoral. Se identifican como funcionalidades de fases posteriores. No se implementaron sus operaciones ni dependencias artificiales entre ellos.

## 18. Archivos modificados y añadidos

**Añadidos:**

- Controladores: `app/Controllers/AdministrationController.php`, `StructureController.php`.
- Repositorios: `app/Repositories/AccountScopeRepository.php`, `AdministrationRepository.php`, `ModuleRepository.php`, `PermissionRepository.php`, `ScopeRepository.php`, `StructureRepository.php`.
- Servicios: `app/Services/AdministrationService.php`, `StructureService.php`.
- Vistas: `app/Views/administration/index.php`, `app/Views/structure/index.php`.
- Entradas web: `public/administration/index.php`, `public/structure/index.php`.
- Interfaz: `public/assets/css/platform.css`, `public/assets/js/phase3.js`, `public/assets/js/administration/index.js`, `public/assets/js/structure/index.js`.
- Las dos migraciones de Fase 3 indicadas en la sección 2.
- Pruebas: `tests/phase3.php`, `phase3-migrations.php`, `structure-writer.php`, `phase3-frontend.js`, `phase3-browser.js`.
- Este informe: `FASE3.md`.

**Adaptados:**

- `app/Auth/Authorization.php`, `SessionValidator.php`.
- `app/Controllers/AuthController.php`, `UserAccessController.php`, `UsersController.php`.
- `app/Repositories/PersonIdentityRepository.php`, `PersonImportRepository.php`, `PersonsRepository.php`, `UserAccessRepository.php`, `UsersRepository.php`, `UsersTypesRepository.php`.
- `app/Services/PersonIdentityService.php`, `PersonImportService.php`, `UserAccessService.php`, `UsersService.php`.
- `app/Routes/api.php`, `app/Views/layout/header.php`, `sidebar-menu.php`.
- `public/persons/index.php`, `public/users/index.php`.
- `database/preflight.php`, `database/migrations/README.md`.
- `tests/integration.php`, `router.php`, `README.md`.

No cambiaron `composer.json`, `composer.lock`, las migraciones de Fase 2, los SQL originales ni los informes anteriores.

## 19. Pruebas ejecutadas

| Comando o comprobación | Resultado final |
|---|---|
| `php tests/run.php` | 29 comprobaciones PHP. |
| `node tests/frontend.js` | 25 comprobaciones JavaScript. |
| `node tests/phase2-frontend.js` | 16 comprobaciones JavaScript de Fase 2. |
| `node tests/phase3-frontend.js` | 16 comprobaciones JavaScript de Fase 3. |
| `php tests/phase3-migrations.php` | 14 comprobaciones de migraciones históricas y conflictos. |
| `php tests/integration.php --phase3 --browser` | 809 comprobaciones HTTP/BD: toda la regresión anterior, 514 adicionales de Fase 3 y validación del navegador. |
| Chrome local, con perfil/servidor/BD desechables | 14 comprobaciones de pantallas, modal, búsqueda y configuración; cero excepciones JavaScript. |
| Sintaxis PHP | 132 archivos, sin errores. |
| Sintaxis JavaScript de aplicación y pruebas | 26 archivos, sin errores. |
| `git -c core.whitespace=cr-at-eol diff --check` | Sin errores, respetando los CRLF existentes en las entradas web. |
| `php database/preflight.php` antes/después | 2 personas, 2 cuentas; cero conflictos y cero registros estructurales reales. |
| `php database/migrate.php` después de aplicar | Sin migraciones pendientes y checksums válidos. |
| Restauración y comparación de la base local | Registros originales de 65 tablas conservados. |

La excepción intencional en stderr de `tests/run.php` verifica registro interno sin exposición en JSON; no es un fallo de la suite. Las pruebas funcionales y concurrentes eliminaron exclusivamente sus bases desechables. Las capturas de navegador quedaron en su directorio temporal privado.

## 20. Resultados

**IMPLEMENTADO / CORREGIDO.** Todas las comprobaciones finales pasaron. Se verificaron casos permitidos y rechazos por sesión, CSRF, organización, elección, permiso, alcance, ciclo, nivel, duplicidad de ubicación, segunda cuenta, solicitud obsoleta y módulo no habilitado.

Se comprobó consistencia exacta entre responsables directos y `estructura_relaciones` después de altas, movimientos, reasignaciones, bajas y copias. Dos escritores concurrentes produjeron una sola incorporación y una sola vinculación de cuenta, con el otro intento rechazado con 409.

Los SQL originales conservaron estos SHA-256:

```text
database/database.sql
48339f405bccc7a8bd14cee5f16e283aa4a6f5a95c896c6d84fdc925f45e1dcc
database/colonias mysql.sql
b109dee64af11335304554c5babe26abd7a3c9a3f9f6da6935d31aced471e28a
```

También se compararon con la instantánea inicial los hashes de `Diagnostico.md`, `FASE1.md`, `FASE2.md`, ambas migraciones anteriores y ambos archivos de Composer: permanecen intactos.

## 21. Nuevos hallazgos

**NUEVO HALLAZGO / CORREGIDO:** el encabezado común cargaba el módulo de gráficos de ejemplo del dashboard en todas las páginas. Chrome reprodujo excepciones por elementos inexistentes. Se carga ahora el módulo común de la plantilla, conservando su inicialización y evitando esos gráficos de demostración.

**NUEVO HALLAZGO / CORREGIDO:** la clase visual `card` podía sobreescribir el estado cerrado de los diálogos nativos. Se añadió CSS específico de diálogo para respetar `open`, centrar el modal y permitir desplazamiento sin alterar la estética general. Se inspeccionaron capturas y se repitió la validación en Chrome.

**CORREGIDO DURANTE LA VALIDACIÓN:** se protegió la ampliación de alcance mediante cambios de rol con los mismos permisos, la omisión de aprobaciones mediante cambios de estado y la conservación de varias proyecciones históricas junto a una sola activa. Se añadieron casos negativos explícitos.

**NO REPRODUCIDO EN LA BASE REAL:** varias cuentas para una persona, estructura antigua sin persona válida, ciclos históricos o referencias estructurales incompatibles. Esos escenarios sí se prepararon en pruebas y fueron rechazados sin resolución destructiva.

## 22. Pendientes para Fase 4 o posteriores

**PENDIENTE PARA OTRA FASE:** auditoría universal, aprobaciones generales, archivos privados, procesamiento de imágenes, notificaciones, exportaciones, trabajos en segundo plano, mensajería operativa, encuestas, interacciones, tareas/metas, eventos/brigadas, peticiones, incidencias, transporte, finanzas, inventario, Jornada Electoral, resultados y actas.

Esta fase aporta únicamente el catálogo de módulos futuros y registros/aprobaciones específicos de estructura. No se implementaron `/media/{uuid}`, correo masivo, WhatsApp, reportes PDF generales ni servicios de respaldos.

## 23. Riesgos conocidos y límites de validación

El objetivo indicado es Ubuntu 24.04, Nginx 1.24.0, PHP 8.3.6 y MySQL 8.0.46 con utf8mb4. El código nuevo utiliza características disponibles en PHP 8.3 y el SQL utiliza InnoDB, FK, índices, columnas generadas y sintaxis compatible con MySQL 8. No se propuso cambiar esos componentes. La ejecución final en las versiones exactas de producción queda para la verificación previa al despliegue, tal como se indicó; esta revisión no accedió al VPS.

El DDL produce commits implícitos: debe desplegarse esquema/código coordinadamente, con la aplicación detenida para el cambio de esquema y respaldo restaurable. No se proporciona un `down` destructivo. Los conflictos históricos reales requieren resolución humana antes de migrar; no hay corrección automática de identidades.

El bloqueo de organización prioriza integridad de estructuras compartidas y coordinación con cambios de capacidad. Serializa escrituras de esa organización; conviene medir rendimiento con la carga real antes de los módulos operativos. Las lecturas están paginadas, pero configurar grandes catálogos de opciones en navegador requiere recorrer esas páginas.

Las restricciones de ubicación activa y compatibilidad de niveles pueden impedir una asociación/clonación concreta. Se informa el conflicto y se revierte la operación completa. No se mezclan estructuras existentes ni se excluyen personas silenciosamente.

El cierre de relaciones debe modificarse siempre mediante los servicios transaccionales. Las escrituras SQL externas que eludan esos servicios pueden romper reglas que una FK no expresa, como ciclos o alcances.

Las solicitudes se invalidan conservadoramente si cambia la versión estructural; no se aplican automáticamente sobre una estructura distinta a la revisada.

## 24. Estado de git diff y punto de detención

Al inicio, HEAD era `1f1f5ce` y había cambios de Fase 2 sin commit. Durante el trabajo apareció HEAD `0683728` (`Fase 2`); se conservó. Esta intervención no ejecutó commits, resets ni reversiones.

Estado final respecto de ese HEAD: **25 archivos existentes modificados y 26 archivos nuevos**, incluido este informe. No hay cambios staged. Se revisaron diferencias, archivos nuevos, sintaxis y whitespace, preservando los finales CRLF existentes. Los cambios de esta fase permanecen disponibles para revisión.

**Fase 3 terminada. Se detiene aquí el trabajo y se espera revisión e instrucciones expresas antes de continuar.**
