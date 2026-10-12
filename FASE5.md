# SICRAT — Fase 5: módulos operativos

Fecha: 12 de octubre de 2026. Trabajo local sobre el proyecto existente, sin commits ni intervención en el VPS o Adariel.

## 1. Resumen

**IMPLEMENTADO.** Interacciones, encuestas, tareas, metas, eventos, peticiones, incidencias, logística de transporte y mensajería entre cuentas. Se conserva el MVC existente y se integran los servicios comunes de Fase 4. Los módulos son opcionales y mantienen autorización del Super Administrador, selección del cliente, permisos electorales y alcances de Fase 3.

Se revisaron `Diagnostico.md`, `FASE1.md` a `FASE4.md`, `UX_UI_FASES_1_4.md`, migraciones, autenticación, estructuras, permisos y servicios comunes. El punto inicial fue `dbade58`, con el árbol limpio. La regresión inicial completa pasó antes de desarrollar los módulos.

## 2. Migraciones

**IMPLEMENTADO.** Única migración nueva: `database/migrations/20261012_006_operaciones.php`. No modifica los SQL originales ni las cinco migraciones anteriores. Evoluciona las tablas operativas existentes, conserva columnas e identificadores y añade 16 tablas:

- `interacciones_procesos`.
- `tareas_asignaciones`, `tareas_subtareas`.
- `operaciones_historial`, `operaciones_personas`, `operaciones_folios`.
- `encuestas_correcciones`.
- `eventos_tipos`, `eventos_participantes`, `eventos_responsables`.
- `peticiones`, `transporte`.
- `mensajes_lecturas`, `mensajes_destinatarios`, `mensajes_avisos`, `mensajeria_politicas`.

Añade UUID, contexto organizacional, estados y versiones; índices para cronología, frecuencia, asignaciones, asistentes, mensajes, lecturas y logística; y claves foráneas para las nuevas referencias concretas. Las referencias polimórficas se validan mediante servicios y ResourceRegistry.

Las columnas históricas `tareas.asignado_a` y `metas.estructura` admiten NULL para las nuevas modalidades. Esto amplía sus posibilidades sin eliminar valores anteriores. Los módulos existentes pasan a estar implementados y se añade `interacciones`; no se autoriza ni selecciona automáticamente ningún módulo del cliente.

El preflight rechaza cruces de organización/elección, respuestas incompatibles, membresías/capturistas huérfanos y actores históricos incompatibles antes del DDL. Los backfills se procesan por lotes: preservan estados, capturistas, asignados históricos sin persona y asistencia anterior. No inventan fechas de lectura ni de asistencia efectiva. `requiere_transporte=1` pasa a «requiere» y el cero histórico a «no definido».

La migración se aplicó a la base local después de restaurar y ensayar un respaldo privado; conservación e idempotencia verificadas. Detalles en la sección 31.

## 3. Corrección visual del margen superior

**CORREGIDO.** `public/assets/css/platform.css` da a `main` una separación superior uniforme de 1rem, incluyendo móvil. El título y breadcrumb recuperan espacio bajo el header. El selector electoral permanece en el encabezado. La regresión de Chrome comprueba el padding en las pantallas anteriores y en los módulos nuevos.

## 4. Interacciones

**IMPLEMENTADO.** Registro de una acción realmente realizada: persona, fecha, tipo, resultado, notas y capturista. Una interacción puede asociarse con varias elecciones autorizadas de la misma organización sin duplicarse. El selector de elecciones está acotado y filtra permisos y activación.

Relacionar una tarea no modifica su estado. Sólo la acción explícita de completar una asignación/subtarea lo hace, respetando su validación. Se rechazan fechas futuras, personas ajenas y elecciones sin acceso. Los intentos sin contacto conservan la tarea pendiente.

## 5. Ficha cronológica

**IMPLEMENTADO.** La ficha de Personas incorpora actividad de la elección actual: participación, interacciones, tareas, eventos, peticiones e incidencias. Cada fuente comprueba módulo, permiso y alcance antes de paginar. La consulta usa UNION y límites; no reúne todo el historial en memoria. Se integra también la necesidad de transporte de la participación actual.

## 6. Encuestas

**IMPLEMENTADO.** Editor de borradores con preguntas de texto breve/largo, número, sí/no, selección única/múltiple, escala y fecha. Soporta obligatoriedad, límites numéricos y condiciones basadas en preguntas anteriores. Valida claves únicas, opciones válidas y respuestas a preguntas visibles.

La publicación puede ser directa con `encuestas.publicar` o solicitar aprobación común con `encuestas.crear`. Se mantienen permisos separados para aplicar, resultados, administrar y corregir. La administración puede archivar/reabrir y ajustar el periodo sin cambiar la definición publicada.

## 7. Versionado

**IMPLEMENTADO.** Cada versión conserva preguntas y respuestas propias y comparte una serie. Las definiciones publicadas o con capturas no se reescriben: se crea una versión nueva en borrador. La numeración se bloquea sobre la serie y tiene restricción única. Las solicitudes pendientes tampoco admiten edición de la definición.

## 8. Respuestas y frecuencia

**IMPLEMENTADO.** Persona encuestada, cuenta capturista y modalidad son datos separados. Autocaptura exige que la cuenta corresponda a la persona; captura asistida y observación se distinguen expresamente. Los borradores no cuentan como respuestas enviadas.

Frecuencia única o múltiple con horas, días, semanas o meses, comprobada por persona a través de la serie. El servicio común de bloqueo protege la combinación serie/persona; el bloqueo compartido de la encuesta permite capturas de personas distintas. Las correcciones requieren permiso, motivo y revisión, conservan antes/después en historial privado y auditan la referencia sin volcar respuestas en la auditoría universal.

El adaptador interpreta también selecciones históricas guardadas como varias filas con `opcion`; no pierde las opciones anteriores al corregir. Los resultados y exportaciones son agregados y distinguen modalidades. No generan listas de persuasión ni microsegmentación política.

## 9. Tareas

**IMPLEMENTADO.** Individuales con varios asignados y estados independientes, o colaborativas con subtareas. Las personas asignadas pueden no tener cuenta. Incluye prioridad, inicio, vencimiento, relación opcional con otros registros, comentarios, evidencias, cancelación y progreso real.

La recurrencia diaria/semanal/mensual usa scheduler y cola comunes; crea ocurrencias independientes con clave única. Los reintentos no duplican tareas y los meses cortos conservan una fecha válida. Editar una tarea compartida comprueba el alcance sobre todos sus asignados. Se preserva el acceso propio del asignado histórico que todavía no tiene persona vinculada.

## 10. Validaciones

**IMPLEMENTADO.** Tareas, publicación de encuestas y cierres de peticiones/incidencias reutilizan ApprovalService. Una tarea pendiente de validación no cuenta como completada. Rechazar devuelve el registro a corrección; aprobar aplica la transición dentro de la misma transacción.

El aprobador se obtiene de la responsabilidad o se selecciona explícitamente, debe ser distinto del solicitante y conservar permiso y alcance. Se valida el snapshot y la versión antes de aplicar. Cancelación, expiración y reasignación invalidan la solicitud correspondiente. ResourceRegistry permite callbacks de decisión y bloqueo: el orden es recurso, solicitud y pasos.

## 11. Metas

**IMPLEMENTADO.** Avance manual con motivo e historial, o cálculo automático sobre datos reales: incorporaciones, interacciones, tareas completadas, encuestas y asistencia. Los indicadores conservan su unidad; no se crea una puntuación artificial de personas.

Responsabilidad individual, equipo o general con distribuciones padre/hijas. Se controla elección, periodo, indicador, origen, responsables y cantidad distribuida. El padre manual suma hojas sin sumar otro avance propio. El cálculo automático usa registros distintos, permisos de la fuente y alcance actual. Se puede editar/cancelar; una meta cancelada no admite nuevos avances ni distribuciones.

## 12. Eventos

**IMPLEMENTADO.** Tipos configurables por elección; nombre, descripción, lugar, periodo, responsables, notas y estados programado/en curso/finalizado/cancelado. Los responsables pueden existir sin cuenta. Los eventos admiten evidencias de imagen privadas.

## 13. Brigadas y caminatas

**IMPLEMENTADO.** Son tipos configurables del mismo módulo de Eventos. El administrador crea las categorías necesarias desde Configuración. No se mantienen módulos ni modelos paralelos para brigadas o caminatas.

## 14. Asistencia

**IMPLEMENTADO.** Tres modalidades: personas/invitados individuales, conteo agregado o sin control. Estados explícitos de invitado, confirmado, asistió, no asistió y canceló. Invitados externos requieren habilitación del evento y datos mínimos; no se convierten en personas/cuentas ficticias.

QR opcional generado con la biblioteca instalada; contiene referencias opacas y lleva a una pantalla autenticada. Conocer el QR no autoriza el acceso. Los listados y resúmenes individuales filtran asistentes por alcance; se conserva la tabla histórica de asistentes.

## 15. Peticiones

**IMPLEMENTADO.** Solicitudes de personas registradas o externas, con capturista autenticado independiente del solicitante. Categoría, prioridad, descripción, responsable, seguimiento, evidencias, folio y resolución. Cierre directo o con validación, según configuración del registro.

## 16. Incidencias

**IMPLEMENTADO.** Evolución de la tabla existente: tipo, categoría, prioridad, responsable, persona/evento relacionado, seguimiento, evidencias y resolución. Permite relacionar tareas mediante la referencia operativa compartida. Conserva registros y estados históricos.

## 17. Reasignaciones y cierres

**IMPLEMENTADO.** Transferencia explícita con motivo y permisos que conserva el capturista: ver, comentar, editar, transferir o cancelar. No puede conservar permisos que actualmente no posee. El responsable nuevo debe estar dentro de alcance y participación.

Una reasignación retira el cierre pendiente antes de cambiar responsable; la aprobación anterior ya no puede cerrar el registro. Los folios usan contador por organización, entidad y año, protegido mediante escritura exclusiva y transacción. No incorporan información personal.

## 18. Transporte y logística

**IMPLEMENTADO.** Tablero de necesidades por persona y elección: no definido, requiere transporte o cuenta propia; coordinación, pendientes, proveedor/persona sin cuenta o proveedor externo y observaciones. Filtros de persona, estado, sección y estructura; resúmenes según alcance.

Confirmar un traslado es una operación estrictamente opcional con actor, fecha y observación. Nunca se confirma automáticamente y la falta de confirmación no se utiliza para penalización o desempeño. No establece intención ni voto. No se implementaron vehículos, flotillas, combustible, GPS ni movimientos financieros.

## 19. Mensajería

**IMPLEMENTADO.** Conversaciones directas entre cuentas activas autorizadas de la elección, grupos manuales, grupos automáticos y anuncios. Contenido mediante texto seguro, búsqueda acotada y paginación. Los envíos llevan idempotencia y no bloquean esperando proveedores externos.

Política electoral configurable: línea de coordinación, equipo/territorio o todas las cuentas autorizadas. Las excepciones individuales conservan su alcance. El permiso global sólo funciona con alcance amplio; un permiso global con alcance propio no permite inspeccionar conversaciones ajenas. Las conversaciones directas conservan sus integrantes fijos, también cuando son históricas.

## 20. Grupos

**IMPLEMENTADO.** Grupos manuales con creación, administración y cambio de miembros autorizados. Automáticos basados en estructura, nivel o equipo a cargo, calculados desde la estructura y los permisos vigentes.

Las proyecciones se sincronizan con cambios de estructura, cuentas, accesos y permisos. Salidas y reingresos actualizan el punto de incorporación; una cuenta creada posteriormente tampoco recibe historia anterior. La administración del creador autorizado no inventa una membresía estructural. En anuncios sólo publican administradores autorizados.

## 21. Lecturas

**IMPLEMENTADO.** Destinatarios registrados al enviar; lectura por mensaje/cuenta con primera fecha preservada. La reapertura no reescribe fechas ni inserta escrituras repetidas para todos los mensajes. Consulta de leído/no leído sólo para remitente o administración autorizada, paginada.

El cursor histórico `ultimo_mensaje_leido` permanece intacto. No se fabrican timestamps ni destinatarios históricos cuya presencia en el envío original no puede comprobarse.

## 22. Adjuntos

**IMPLEMENTADO.** FileService común con validación MIME real, normalización WebP, miniatura y almacenamiento privado. Eventos e interacciones usan imágenes; tareas, casos y conversaciones permiten PDF administrativo sólo cuando está habilitado expresamente. No videos.

Se sirve por `/media/{uuid}` con permiso, módulo, contexto, alcance y propietario actual. En mensajes se verifica membresía, punto de incorporación, remitente al subir y moderación. Se vuelve a autorizar antes de guardar el archivo procesado. Los adjuntos de mensajes quedan excluidos del ZIP genérico.

## 23. Notificaciones

**IMPLEMENTADO.** Avisos internos de tareas y mensajes mediante NotificationService y trabajos comunes. Se comprueban permisos, membresía y silencio al procesar. Avisos idempotentes, con referencia y texto genérico, sin copiar contenido privado del chat.

Silencio indefinido o hasta una fecha afecta sólo las notificaciones. La comunicación externa utiliza el motor existente: canal verificado, consentimiento vigente, configuración y plantilla válida cuando corresponde. Una persona sin cuenta puede recibir una comunicación externa autorizada, pero no participa ficticiamente en chat. No se enviaron comunicaciones externas reales durante las pruebas.

## 24. Auditoría

**IMPLEMENTADO.** Creaciones, publicaciones, versiones, correcciones, asignaciones, validaciones, avances, asistencia, transferencias, cierres, logística, grupos y moderación se registran mediante AuditService. El seguimiento operativo conserva notas autorizadas por registro. No se audita cada apertura trivial ni se vuelca el contenido de mensajes privados en auditoría universal.

## 25. ResourceRegistry

**IMPLEMENTADO.** Propietarios de interacciones, encuestas, tareas, asignaciones/subtareas, metas, eventos, peticiones, incidencias y mensajes. Validadores específicos de lectura/escritura, permisos adicionales de servicios comunes, políticas MIME y callbacks de aprobaciones/bloqueos.

Los servicios comunes conservan sus motores; no se implementaron servicios paralelos de archivos, aprobaciones, cola o notificaciones. Un UUID por sí solo no otorga acceso.

## 26. Exportaciones

**IMPLEMENTADO.** Nueve exportadores: interacciones, resultados agregados de encuestas, tareas, metas, eventos, asistencias, peticiones, incidencias y logística. Usan PDF/XLSX/CSV existentes y cola común, lotes acotados y autorización al solicitar, generar y descargar.

Se aplican los permisos del negocio y de exportación, filtros y alcance actual. Una revocación posterior impide descargar datos que ya no están autorizados. No existe exportador genérico de mensajes privados. Los nuevos exportadores no se agregan automáticamente al paquete antiguo del cliente.

## 27. UX/UI

**IMPLEMENTADO.** Siete entradas compactas en Operación; Tareas/Metas y Peticiones/Incidencias se relacionan con pestañas. Sólo se muestra navegación de módulos activos y accesibles. Nueve pantallas con cards, tablas, filtros, badges, pestañas de detalle/acciones/seguimiento/evidencias y diálogos accesibles.

Selectores por nombre/folio y UUID como valor opaco, con búsquedas y páginas acotadas. Formularios adaptados a móvil; preguntas condicionales, asignaciones múltiples y configuración comprensible. Estados en español y sin JSON técnico como contenido principal. Se conserva Tailwick/Tailwind.

## 28. Archivos modificados

**IMPLEMENTADO.** Nuevos: `OperationsController`, `OperationsRepository`, 16 servicios/clases bajo `app/Services/Operations`, vista y entrada `operations`, cuatro scripts operativos, `persons/activity.js`, migración 006 y pruebas de Fase 5 con auxiliar concurrente.

Integraciones sobre existentes: rutas y registro de operación autenticada; AdministrationService, UsersService, UserAccessService, StructureRepository y UserAccessRepository para sincronización; ApprovalService, ResourceRegistry, FileService, ExportRegistry/Service, WorkerService y SchedulerService; sidebar, vistas/ficha de personas, CSS; router y runner de pruebas, comprobaciones de Chrome y adaptación del ensayo de migraciones de Fase 4 a migraciones posteriores.

No se reconstruyó autenticación ni sesiones. Los cambios de esos ámbitos se limitan al registro de la operación de API que posteriormente valida cada servicio de negocio.

## 29. Dependencias

**IMPLEMENTADO con dependencias existentes.** Sin instalaciones ni cambios en `composer.json`/`composer.lock`. Reutiliza PDO, GD, PDF, hojas de cálculo y QR ya disponibles. No añade Bootstrap ni una biblioteca nueva de frontend.

Entorno objetivo declarado: Ubuntu 24.04, Nginx 1.24.0, PHP 8.3.6, MySQL 8.0.46 y utf8mb4. Validación local: PHP 8.5.7 y MariaDB 10.4.21. Se evita sintaxis exclusiva de MariaDB; las migraciones usan construcciones compatibles con MySQL 8.0. No se ejecutó esta revisión en el VPS ni se afirma una prueba de runtime allí.

## 30. Pruebas

Comandos reproducibles:

```sh
php tests/run.php
node tests/frontend.js
node tests/phase2-frontend.js
node tests/phase3-frontend.js
node tests/phase4-frontend.js
node tests/ux-frontend.js
php tests/phase3-migrations.php
php tests/phase4-migrations.php
php tests/phase5-migrations.php
php tests/integration.php --phase5 --browser
```

La integración crea exclusivamente una base y servidor desechables e incluye regresión de Fases 1–4, UX y cobertura de Fase 5. Las carreras reales usan procesos/conexiones independientes: respuestas de misma/distintas personas, folios, envíos repetidos, ocurrencias y aprobación contra reasignación. Los auxiliares rechazan una base que no tenga el nombre desechable esperado.

Chrome comprueba escritorio, tablet y móvil para los nueve módulos, altas, fichas, pestañas, selector, sidebar, límites del diálogo, ausencia de desbordamiento y acciones representativas. Las capturas son privadas, fuera del proyecto.

## 31. Resultados

**IMPLEMENTADO Y VERIFICADO.** Cierre completo, sin errores en las comprobaciones:

| Validación | Resultado |
|---|---:|
| PHP unitario/regresión básica | 29 comprobaciones |
| Frontend: general/Fases 2, 3, 4/UX | 25 / 16 / 16 / 13 / 17 |
| Integración HTTP/BD Fases 1–5 y UX | 1.532 comprobaciones |
| Cobertura nueva HTTP/BD de Fase 5, incluida arriba | 481 comprobaciones |
| Chrome completo | 814 comprobaciones; cero excepciones JavaScript |
| Chrome nuevo de Fase 5, incluido arriba | 457 comprobaciones |
| Migraciones Fase 3 / Fase 4 / Fase 5 | 14 / 16 / 156 comprobaciones |
| Sintaxis PHP / JavaScript | 202 / 37 archivos, sin errores |
| Whitespace de cambios existentes y archivos nuevos | Correcto |
| Archivos protegidos contra snapshot inicial | 16, sin cambios |

La integración final dejó sus artefactos privados en `/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat_phase1_test_d7f429e87911`: 20 capturas de Fase 5, 100 capturas UX anteriores y cuatro hojas de contacto. Se inspeccionaron capturas representativas, además de las comprobaciones DOM y acciones reales en los tres tamaños. La base de pruebas se elimina al terminar.

**Migración local:** respaldo nativo antes del DDL, restauración en una base de ensayo, comparación de las columnas/filas anteriores de 87 tablas, migración 006, repetición idempotente y nueva comparación; posteriormente el mismo proceso de migración/comparación en `sicrat` local. Resultado: 87 → 103 tablas; 5 → 6 migraciones; datos anteriores conservados. El único cambio deliberado sobre metadatos anteriores es `modulos.implementado` para los seis módulos operativos existentes; las autorizaciones/selecciones originales se conservaron. Las tablas de permisos/catálogo incorporan las altas previstas.

Respaldo privado, fuera de Git, con permisos de directorio 0700 y archivo 0600:

`/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat-phase5-backup-20261012-234926-da4450/sicrat-before-phase5.sql`

SHA-256: `cc656cdaceb37b5d4d9cf84e3668914664420f06125e775427f750d81927a8f2`.

La base de ensayo y el archivo temporal de credenciales se eliminaron; el respaldo se conserva. No se modificaron servicios, cron, configuración del VPS ni Adariel. La base local no recibió fixtures de pruebas.

## 32. Nuevos hallazgos

**CORREGIDO durante Fase 5:** interbloqueo entre capturas distintas por bloqueo de rangos; promoción de bloqueo compartido del contador de folios; consulta ambigua del historial; alias SQL reservado en MariaDB; falta de inicialización de la URL compartida del selector electoral; edición de tarea compartida sin revisar todos los asignados; resumen de asistentes sin filtro individual; pérdida de selecciones históricas múltiples al interpretar/corregir; necesidad de transporte NULL en participaciones nuevas. El nuevo campo queda con default No definido después del backfill histórico.

**NO REPRODUCIDO:** necesidad de reconstruir autenticación/sesiones. La regresión existente pasó antes del trabajo y sigue incluida en el cierre. No se encontraron incompatibilidades que justifiquen cambiar el entorno objetivo o instalar dependencias.

## 33. Pendientes para Fase 6

**PENDIENTE PARA OTRA FASE.** Finanzas, presupuestos, movimientos, anticipos, apoyos y reembolsos; inventario y almacenes. Jornada Electoral, candidatos, actas, conteo y resultados corresponden a su fase posterior. La logística funciona de manera independiente y podrá recibir referencias futuras sin depender de Finanzas.

No se inició ningún desarrollo de esas fases.

## 34. Riesgos y condiciones de operación

- El despliegue debe coordinar código y migración 006, con respaldo previo. MySQL aplica DDL fuera de una transacción única; el preflight reduce conflictos y la migración admite reanudación, pero no sustituye el respaldo.
- La disponibilidad del cron/scheduler y workers existentes es necesaria para recurrencias, avisos y exportaciones. Esta fase no instala ni modifica cron o servicios del VPS.
- Los módulos deben autorizarse/seleccionarse desde Administración; la migración no los activa para clientes.
- Las integraciones externas dependen de configuración y consentimiento reales. Las pruebas usan destinos y proveedores desechables, no acreditan la entrega real de correo/WhatsApp.
- Los ensayos locales verifican MariaDB y PHP instalados. La ejecución exacta de PHP 8.3.6/MySQL 8.0.46 debe repetirse al preparar el despliegue autorizado.
- Los índices y lotes responden a las consultas implementadas. La sincronización masiva de grupos y el volumen de recurrencias deben observarse con el volumen real de cada cliente; no se afirma una prueba de carga de millones de registros.

## 35. git diff

**VERIFICADO.** 25 archivos existentes modificados y 32 archivos nuevos, incluyendo este informe. `git diff --stat` sobre archivos existentes: 173 líneas añadidas y 18 eliminadas. Git no incluye archivos nuevos sin seguimiento en ese comando; éstos se revisaron y verificaron por separado, sin agregarlos al índice.

`git diff --check` y la comprobación de whitespace de los nuevos archivos pasan. No hay staging ni commits. Se conservaron los 16 archivos protegidos, incluidas las cinco migraciones anteriores, informes previos, `.env` y Composer. Hashes de los SQL originales:

- `database/database.sql`: `48339f405bccc7a8bd14cee5f16e283aa4a6f5a95c896c6d84fdc925f45e1dcc`.
- `database/colonias mysql.sql`: `b109dee64af11335304554c5babe26abd7a3c9a3f9f6da6935d31aced471e28a`.

Hash de la migración aplicada `20261012_006_operaciones.php`: `f2345a6b6989ec8496905b082454d6c5c426f106500c48397699fdaca4aabcd5`.

La entrega se detiene en Fase 5 y requiere revisión expresa antes de continuar con otra fase.
