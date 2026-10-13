# FASE 7 — Jornada Electoral y resultados

Implementación y validación local: 13 de octubre de 2026. Base: commit `1b0c637`, Fase 6. Clasificación utilizada: **IMPLEMENTADO**, **CORREGIDO**, **NUEVO HALLAZGO**, **NO REPRODUCIDO** y **PENDIENTE PARA OTRA FASE**.

## 1. Resumen

**IMPLEMENTADO.** Jornada Electoral utiliza autenticación, contexto multielección, autorización, alcances y servicios comunes existentes. Incluye configuración por elección, catálogos, filas exactas del acta, asignaciones explícitas, fotografías privadas, versiones, validación, correcciones, concentrados, progreso, cierre/reapertura y nueve informes en tres formatos.

Se revisaron `git status`, `git diff`, informes Fases 1–6, diagnóstico, revisión UX/UI, migraciones y servicios existentes. El repositorio inicial estaba limpio, sin archivos parciales de Fase 7; se conservó íntegramente la implementación anterior. La línea base pasó **2,091 comprobaciones HTTP/BD y 1,110 en Chrome**, sin excepciones JavaScript. No se reconstruyeron autenticación ni sesiones.

También se implementaron los ajustes solicitados de módulos por administrador, SweetAlert centralizado, toasts, confirmaciones y prevención de doble envío. No se inició Fase 8, no se desplegó, no se hicieron commits y no se intervino en Adariel ni servicios del VPS.

## 2. Migraciones

**IMPLEMENTADO Y APLICADO LOCALMENTE.** [20261013_008_jornada_resultados.php](database/migrations/20261013_008_jornada_resultados.php) añade quince tablas:

| Grupo | Tablas |
| --- | --- |
| Configuración y catálogo | `jornada_config`, `jornada_partidos`, `jornada_candidaturas`, `jornada_coaliciones`, `jornada_coalicion_partidos`, `jornada_opciones` |
| Casillas y acceso | `jornada_casillas`, `jornada_capturistas`, `jornada_borradores` |
| Resultados y evidencia | `jornada_resultados`, `jornada_versiones`, `jornada_votos`, `jornada_decisiones` |
| Avisos administrativos | `jornada_avisos`, `jornada_envios` |

Checksum SHA-256 de 008: `2ea69526592bc9743df1815a6b7198951dbb565480223c181e826ee601fba2ae`. Queda congelada después de aplicarse: futuras modificaciones deberán ser incrementales.

Reutiliza `procesos_electorales`, `secciones_electorales`, `casillas`, usuarios y servicios de Fases 1–6; no altera sus columnas. Añade doce permisos y sus concesiones electorales a la plantilla del administrador. Marca `jornada_electoral` como implementado, conservando autorizaciones y selecciones existentes. No crea candidatos, capturistas, actas ni resultados ficticios en la base local.

Comprueba antes del DDL tablas coincidentes, InnoDB, columnas, tipos, longitudes, nulabilidad, claves únicas/FKs, contextos, referencias y votos históricos. Los índices atienden elección/casilla, usuario/asignación, versión, estado y fecha. Las FKs compuestas impiden referencias entre elecciones o entre resultados incompatibles; el puntero vigente pertenece al mismo resultado. La reejecución mediante el ejecutor existente deja cero pendientes. No hay `down` destructivo; el DDL requiere respaldo porque tiene commits implícitos.

Antes de aplicar 008 se creó un respaldo nativo, se restauró en una base desechable y se ensayó la migración. Se compararon contenido y cantidades de registros de las **131 tablas anteriores**, permitiendo únicamente nuevos permisos/concesiones/registro de migración y la bandera de implementación de Jornada. Se conservó todo lo anterior; la base local pasó de **131 a 146 tablas**, y de **7 a 8 migraciones**. La copia desechable y las credenciales temporales se eliminaron.

Respaldo privado: `/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat-phase7-backup-20261013-181556-073225/sicrat-before-phase7.sql`. SHA-256: `0086cb1a40e1761fae0df30669c3f3dbe353031e9d2a1563cec82d2830e4abca`. Su ubicación es temporal; requiere conservación en almacenamiento durable autorizado antes de una intervención posterior.

## 3. Configuración de Jornada

**IMPLEMENTADO.** [Configuration.php](app/Services/Jornada/Configuration.php) administra zona IANA, inicio local convertido a UTC, política inicial, fotografía obligatoria, denominador y estado. Reutiliza `procesos_electorales.f_eleccion`, sin duplicar la fecha. La creación deja la Jornada en preparación.

El checklist exige opciones activas con referencias válidas, casillas, cuentas/asignaciones activas y permisos efectivos suficientes. La apertura incompleta devuelve conflicto controlado. Una vez existe cualquier captura, se bloquean cambios críticos de configuración, catálogos y asociación de casillas para conservar el significado histórico de los votos.

## 4. Partidos

**IMPLEMENTADO.** Catálogo por organización/elección, nombre, siglas, color opcional, estado e imagen privada opcional. Se comprueban contexto, nombres repetidos y color hexadecimal. Desactivar antes de capturar conserva el registro; no se elimina historia.

## 5. Candidatos

**IMPLEMENTADO.** `jornada_candidaturas` conserva nombre, cargo, estado y fotografía privada opcional. Es independiente de personas y cuentas del padrón operativo. Las asociaciones con filas del acta se configuran explícitamente; no se infieren preferencias individuales.

## 6. Coaliciones

**IMPLEMENTADO.** Relación de una coalición con al menos dos partidos distintos de la misma elección y candidatura opcional. No genera combinaciones de marcas ni opciones del acta automáticamente. Se rechazan referencias ajenas y asociaciones incoherentes de candidatura.

## 7. Opciones del acta

**IMPLEMENTADO.** Cada fila tiene etiqueta exacta, orden único por elección, tipo, referencias opcionales, indicador de suma a candidatura, indicador de denominador y clasificación. Admite partido, coalición, combinación exacta, independiente, nulos, no registrados y otro.

La vista previa presenta únicamente las filas configuradas, ordenadas y con sus asociaciones; no permite capturar desde ella. Nombres u órdenes ocupados producen mensajes de conflicto legibles, sin errores SQL expuestos.

## 8. Modelo de voto crudo/derivado

**IMPLEMENTADO.** `jornada_votos` guarda enteros por versión y opción exacta. `Votes.php` exige todas las filas activas y rechaza filas adicionales, negativos, decimales, flotantes JSON, booleanos, notación científica, separadores y texto. Límite por fila: 2,147,483,647; el total utiliza BIGINT.

Los totales por candidatura derivan exclusivamente de las filas marcadas para sumar a ella. **PAN 100 + PRI 80 + PAN+PRI 20 = 200**, tanto como total crudo como para la candidatura asociada, sin sumar otra vez la combinación. No se distribuyen votos legalmente entre partidos ni se alteran cifras automáticamente.

## 9. Asignación de capturistas

**IMPLEMENTADO.** Relación cuenta–elección–casilla: una cuenta puede tener varias casillas, una casilla varios capturistas y la misma cuenta trabajar en varias elecciones. Exige cuenta activa, acceso electoral activo, permiso efectivo y alcance sobre la casilla. La jerarquía organizacional no concede automáticamente captura.

La administración admite selección múltiple paginada por nombres y cuentas. Retirar una asignación conserva capturas y evidencia. Capturar fuera de asignaciones requiere `resultados.administrar`, además del permiso de captura y alcance; no basta ser superior organizacional.

## 10. Apertura/hora de captura

**IMPLEMENTADO.** Se requiere módulo habilitado, Jornada abierta, horario iniciado y autorización actual. El servidor valida la hora UTC; el navegador sólo presenta la hora en la zona elegida. Se rechazan horas IANA inexistentes o ambiguas durante cambios de horario, evitando conversiones silenciosas.

## 11. Captura

**IMPLEMENTADO.** [Captures.php](app/Services/Jornada/Captures.php) registra valores exactos, actor, fecha UTC, notas, fotografía opcional/obligatoria y clave de idempotencia. La UI confirma elección, casilla, total y presencia de fotografía antes de enviar.

La operación revalida contexto, permisos, módulo, estado, horario y asignación dentro de la transacción. JSON manipulado no puede seleccionar otra organización o elección. Repetir la misma clave y contenido devuelve la versión existente; cambiar su contenido produce conflicto. Los errores de autorización o estado son respuestas controladas.

## 12. Fotografías de acta

**IMPLEMENTADO.** FileService procesa JPEG/PNG/WebP, normaliza a WebP, genera thumbnail y elimina metadatos EXIF/GPS mediante el flujo existente. Actas no aceptan PDF, SVG, vídeo ni ejecutables. Se utilizan borradores privados para cargar antes de enviar la versión definitiva.

La fotografía definitiva queda asociada a una versión inmutable. Se comprueban MIME real, dueño, elección, alcance y permisos tanto al subir como al consultar `/media`; conocer un UUID no concede acceso. Las respuestas privadas mantienen `no-store`. Una corrección puede reutilizar explícitamente la fotografía de la vigente.

**CORREGIDO.** FileService vuelve a bloquear propietario/archivo y reautoriza antes de la baja. La carrera entre borrar una foto de borrador y enviar una captura no puede producir una versión con fotografía retirada.

## 13. Versiones

**IMPLEMENTADO.** `jornada_resultados` identifica la casilla electoral y conserva un único `actual_version`. `jornada_versiones` almacena secuencia, origen, actor, fecha, base, votos, fotografía, notas, motivo y firma de idempotencia. Las decisiones pueden cambiar estado/puntero, pero no reescriben votos, fotografía ni contenido original de una versión enviada.

Se distinguen pendiente, preliminar, validada, rechazada y sustituida. El historial permanece aunque cambie la vigente o se retire una asignación.

## 14. Concurrencia

**IMPLEMENTADO Y PROBADO.** Se bloquea primero configuración electoral y después las filas necesarias. Captura, corrección, decisión, cierre y retiro de asignación comparten ese orden. Las decisiones sensibles requieren la versión vigente esperada; una referencia obsoleta devuelve 409.

`jornada-writer.php` usa procesos PHP/PDO independientes y barrera READY/GO. Se probaron dos primeras capturas, dos validaciones de la misma propuesta, dos propuestas que reemplazan la misma vigente, captura/corrección, dos correcciones validadas, dos correcciones pendientes, cierre/captura, retiro/captura, borrado de foto/captura e idempotencia simultánea. Se comprobó conservación, único puntero vigente y ausencia de doble conteo.

## 15. Política inicial de validación

**IMPLEMENTADO.** Política A publica la primera captura como preliminar interna, todavía sin entrar al concentrado validado. Política B deja la primera captura pendiente, sin contribuir a ninguno de los dos concentrados. Es una decisión administrativa por elección; no equivale a validación legal.

## 16. Segunda captura

**IMPLEMENTADO.** La segunda y posteriores capturas siempre son propuestas pendientes. La vigente se conserva hasta una decisión explícita. Si llegan simultáneamente dos primeras capturas, ambas se conservan y sólo la primera recibe la política inicial; la otra queda pendiente. La advertencia permanece inline junto al formulario.

## 17. Correcciones

**IMPLEMENTADO.** Corregir crea otra versión, requiere motivo, referencia vigente esperada y `resultados.corregir`. No edita una versión histórica. Validar la corrección requiere selección explícita y permiso adicional `resultados.validar`; tener permiso para corregir no concede validación.

Una corrección pendiente conserva la vigente. Dos correcciones pendientes pueden conservarse como propuestas; dos correcciones que pretenden validarse contra la misma referencia no se sobrescriben: una gana y la otra recibe conflicto.

## 18. Validaciones

**IMPLEMENTADO.** El validador consulta crudos, vigente, diferencias, autor, fecha, fotografía y motivo. Aprobar cambia atómicamente la vigente; rechazar conserva la propuesta sin contabilizarla. Una preliminar vigente puede validarse; para cambiar sus cifras debe crearse otra versión. La revisión de propuestas puede continuar tras cerrar la Jornada.

Se evaluó ApprovalService. Su asignación de aprobadores y secuencia genérica no encaja limpiamente con la elección explícita entre versiones rivales y el puntero vigente esperado. Se utiliza una decisión de dominio en `jornada_decisiones`, sin modificar el motor común, y se reutilizan autorización, auditoría y notificaciones comunes. No se creó un segundo sistema genérico de aprobaciones.

## 19. Historial

**IMPLEMENTADO.** Listado paginado por casilla, origen, estado, número, actor, fecha y total autorizado. La ficha presenta decisiones y comparación fila por fila. Los usuarios sin acceso a los crudos de una versión no reciben votos, fotografía, notas, motivo original ni diferencias sensibles; las comprobaciones se hacen en servidor.

## 20. Resultado preliminar

**IMPLEMENTADO.** El concentrado preliminar interno suma las versiones vigentes **preliminares y validadas**, dentro del alcance y permiso `resultados.ver_preliminares`. No suma propuestas ni versiones sustituidas. La leyenda explica expresamente este criterio.

## 21. Resultado validado

**IMPLEMENTADO.** Suma exclusivamente vigentes validadas. Una primera pendiente o preliminar no contribuye. Al sustituir una vigente, la casilla contribuye una sola vez con sus nuevos crudos. Se muestran denominador y casillas contabilizadas.

## 22. Concentrados

**IMPLEMENTADO.** [Queries.php](app/Services/Jornada/Queries.php) calcula desde el puntero vigente en una transacción de lectura consistente, sin caché de acumulados. Ofrece candidatura, opción exacta, elección y filtros por municipio, distritos existentes, sección y casilla mediante detalle/informes.

Porcentajes usan denominador configurable: todas las filas, filas que suman a candidaturas o filas seleccionadas. Nulos/otras categorías se presentan según la clasificación configurada. El resultado agregado no se enlaza con apoyo, encuestas, transporte o preferencias individuales.

## 23. Avance

**IMPLEMENTADO.** Casillas esperadas, con captura, sin captura, vigentes preliminares/validadas, primeras pendientes sin vigente y casillas con propuestas. Porcentajes de captura/validación y última captura UTC. La primera captura pendiente cuenta como capturada para progreso, pero no como resultado publicado.

## 24. Casillas pendientes

**IMPLEMENTADO.** Listados explícitos de casillas sin captura, pendientes iniciales y casillas con propuestas. Búsqueda, estado, Mis casillas y selectores paginados de municipio, sección y capturista asignado. El selector de capturistas sólo ofrece cuentas con asignaciones en casillas visibles. Los filtros no amplían el alcance del servidor.

## 25. Dashboard

**IMPLEMENTADO.** Tabs de casillas/capturas, avance/resultados, configuración/asignaciones y reportes. Indicadores, tablas de candidaturas y filas crudas, denominador, número de casillas contabilizadas y leyenda del estado de datos. El capturista entra a Mis casillas; las pestañas y formularios requieren permisos correspondientes.

## 26. Gráficas

**IMPLEMENTADO.** Barras de votos por candidatura y avance, mediante ApexCharts incluido en Tailwick. No se instaló otra biblioteca. Las tablas permanecen disponibles junto a las gráficas. Los tooltips HTML están deshabilitados; las tablas usan texto seguro. El PDF usa una representación raster controlada con GD.

## 27. Cierre/reapertura

**IMPLEMENTADO.** Apertura, cierre y reapertura tienen permisos independientes, confirmación SweetAlert, motivo y versión de configuración esperada. Cerrar bloquea nuevas capturas y correcciones; conserva evidencia, propuestas y lectura autorizada. Reabrir requiere autorización y checklist válido, sin resetear resultados.

## 28. Consulta histórica

**IMPLEMENTADO.** Tras cierre o deshabilitación del módulo permanecen consultas, actas, versiones y resultados dentro del alcance. Deshabilitar impide escrituras, sin borrar relaciones. La navegación histórica sólo aparece si existe evidencia y el usuario tiene autorización para ella.

## 29. Auditoría

**IMPLEMENTADO.** AuditService registra configuración, catálogos, asociaciones, asignaciones/retiro, captura, corrección, aprobación/rechazo, cierre/reapertura y exportación común. Las imágenes no se copian a JSON. Los votos completos permanecen en el modelo de versiones y las decisiones conservan diferencias.

## 30. Notificaciones

**IMPLEMENTADO.** NotificationService comunica eventos relevantes a capturistas implicados y administradores/validadores autorizados. Los enlaces abren la casilla dentro de la elección de trabajo, volviendo a comprobar acceso. No se notifica cada actualización del dashboard. La selección de destinatarios valida permisos efectivos y alcance, incluyendo revocaciones.

## 31. Notificaciones externas

**IMPLEMENTADO.** Avisos administrativos opcionales por elección, destinatario con cuenta/persona vinculada, correo/WhatsApp, modo, frecuencia de 5–1,440 minutos y selección de totales/progreso/estado. Resúmenes de elección requieren destinatario autorizado con alcance electoral u organizacional; no incluyen listas de votantes, fotos privadas ni preferencias individuales.

Scheduler encola `jornada_resumen`; WorkerService prepara el envío mediante NotificationService y sus canales, consentimiento, configuración y plantillas existentes. La entrega vuelve a comprobar actor, destinatario, persona vinculada, permisos y versión del aviso antes de tocar el proveedor. Cambiar permisos o contenido después de prepararlo bloquea el envío obsoleto.

Las pruebas usaron únicamente un proveedor falso, con idempotencia y revocación. No se envió correo ni WhatsApp real. No se configuró cron/systemd ni se iniciaron servicios del VPS.

## 32. ResourceRegistry

**IMPLEMENTADO.** [Resources.php](app/Services/Jornada/Resources.php) registra borradores, partidos y candidaturas como propietarios de archivos comunes. Aplica contexto electoral, permiso, alcance, dueño y bloqueo. Una imagen asociada a versión enviada queda protegida incluso si se intenta reutilizar su borrador. No se cambió el almacenamiento privado existente.

## 33. Reportes PDF

**IMPLEMENTADO.** Nueve exportadores registrados: avance, candidaturas, opciones, secciones, casillas, pendientes de captura, pendientes de validación, historial y asignaciones. Todos admiten PDF/XLSX/CSV y cola común.

PDF conserva PdfService/TCPDF: elección, generación/usuario, fecha, zona, modo y leyenda interna, tablas y gráfica raster cuando aporta valor. La gráfica muestra hasta doce candidaturas; las tablas mantienen todas. No se incluyen fotografías completas en concentrados ni textos de PREP/certificación oficial.

**CORREGIDO.** Descargar una exportación todavía pendiente devuelve una respuesta controlada de no disponibilidad, en lugar de un error interno. Se mantiene autorización común del archivo y exclusividad del solicitante.

## 34. XLSX

**IMPLEMENTADO.** OpenSpout existente escribe por flujo y lotes, con hojas `Informe` y `Datos`; los informes de resumen incluyen además `Candidaturas` y `Filas del acta`. No se carga el historial completo para un informe general. El reporte específico de historial sí conserva versiones autorizadas.

## 35. CSV

**IMPLEMENTADO.** Escritor común UTF-8 con BOM y neutralización de fórmulas. Incluye elección, modo interno, generación UTC y nota de resultados no oficiales; el encabezado identifica el modo incluso cuando no hay filas. Se probaron etiquetas que comienzan con `=`, manteniendo seguros los textos y exactos los números crudos. Generación y descarga revalidan permisos/alcance y la firma de datos; cambios relevantes invalidan archivos previos de forma conservadora.

## 36. UX/UI

**CORREGIDO.** En módulos, Super Administrador conserva todas las tarjetas y autorización. El administrador de elección sólo recibe tarjetas efectivamente disponibles para su elección, sin control/concepto “Autorizado”; decide **“Utilizar en esta elección”**. Las validaciones backend se conservan, incluyendo límites de organización y elección.

`public/assets/js/sweetalert.js` centraliza configuración mediante `SicratFeedback`, conservando `ShowSweetAlert`, los dos helpers de callbacks y `ShowToastMessage`. Toasts success/warning/error/info comunican resultados breves; confirmaciones modales resuelven acciones sensibles, incluida la captura/decisión/cierre. La selección de destino permite visualizar SweetAlert dentro de diálogos nativos.

El helper bloquea doble envío, deshabilita temporalmente controles, muestra “Guardando…” y recupera su estado aun con error. Respeta controles que deben permanecer deshabilitados tras paginar. Exportaciones y trabajos programados comunican que se puede continuar trabajando. No hay spinners indefinidos esperando la cola.

Se mantienen inline instrucciones, advertencias de segunda captura, validaciones nativas de campo, estado histórico y ausencia de permisos sobre una sección. No se presenta un formulario administrativo inútil a quien carece de acceso. Se reutilizan Tailwick, Tailwind y los patrones UX existentes, sin nuevos estilos de biblioteca.

**CORREGIDO.** La pantalla nueva inicializa el contexto global esperado por el selector electoral común; la revisión de Chrome detectó y resolvió esa integración antes de la validación final.

## 37. Archivos modificados

Archivos nuevos principales:

- `FASE7.md`, migración 008, `public/jornada/index.php`, `app/Controllers/JornadaController.php`.
- `app/Services/Jornada/{Context,Configuration,Votes,Captures,Queries,Resources,Notifications,Exports}.php`.
- `app/Views/jornada/index.php`, `public/assets/js/jornada/index.js`.
- Pruebas `phase7.php`, `phase7-unit.php`, `phase7-coverage.php`, `phase7-concurrency.php`, `jornada-writer.php`, `phase7-services.php`, `phase7-migrations.php`, `phase7-browser.js`, `feedback-frontend.js`.

Adaptaciones acotadas a archivos existentes:

- Rutas API, controlador común de archivos y navegación lateral.
- Registros comunes de recursos/exportadores, escritor/contexto de exportaciones, descarga y baja de archivos, NotificationService, SchedulerService y WorkerService.
- Vista/script de administración, SweetAlert y scripts comunes de operaciones, recursos y servicios; feedback de autenticación/global sin reconstruir su lógica.
- Integración/router/pruebas de Chrome, expectativas de migraciones/disponibilidad de fases anteriores y `tests/README.md`.

SQL originales, `.env`, Composer, migraciones 001–007, README de migraciones, diagnóstico e informes Fases 1–6/revisión UX permanecen intactos: **21 archivos protegidos comprobados por hash**.

## 38. Dependencias

**IMPLEMENTADO CON DEPENDENCIAS EXISTENTES.** PDO MySQL, GD/WebP, fileinfo, EXIF y extensiones ya exigidas por el proyecto; TCPDF, OpenSpout, PHPMailer y componentes Composer existentes. SweetAlert y ApexCharts ya estaban incluidos. No se instalaron dependencias ni se cambiaron Composer o el entorno.

Entorno ejecutado: **PHP 8.5.7 y MariaDB 10.4.21 locales**. Objetivo documentado por fases anteriores: Ubuntu 24.04, Nginx 1.24.0, PHP 8.3.6, MySQL 8.0.46, utf8mb4. No se comprobó la versión del VPS durante esta ejecución.

El código nuevo evita características posteriores a PHP 8.3 y SQL exclusivo de MariaDB; utiliza InnoDB, FKs, JSON, transacciones y bloqueos existentes. Esta revisión de compatibilidad no sustituye una ejecución sobre PHP 8.3.6/MySQL 8.0.46, pendiente del ensayo de despliegue. No se propone actualizar ni reemplazar los componentes objetivo.

## 39. Pruebas

Comandos reproducibles documentados en [tests/README.md](tests/README.md):

```sh
php tests/run.php
node tests/frontend.js
node tests/phase2-frontend.js
node tests/phase3-frontend.js
node tests/phase4-frontend.js
node tests/ux-frontend.js
node tests/phase6-frontend.js
node tests/feedback-frontend.js
php tests/phase7-unit.php
php tests/phase3-migrations.php
php tests/phase4-migrations.php
php tests/phase5-migrations.php
php tests/phase6-migrations.php
php tests/phase7-migrations.php
php tests/integration.php --phase7 --browser
php tests/integration.php --phase7
git -c core.whitespace=cr-at-eol diff --check
```

Las suites de integración/migraciones utilizan bases aleatorias desechables, cuentas ficticias, storage/sesiones/perfiles privados y cleanup en `finally`; no insertan fixtures en `sicrat`. La concurrencia usa conexiones independientes. Las pruebas incluyen ambos modos, tablas crudas/derivadas, roles y excepciones, CSRF, UUID ajenos, aislamiento electoral/organizacional, horarios, imagen/MIME, nueve informes × tres formatos, avisos fake e historial al cerrar/deshabilitar.

Chrome recorre escritorio 1600 px, tablet 820 px y móvil 390 px, además de los anchos previos. Verifica módulos para ambos administradores, autorización/selección, cuatro toasts, callbacks/confirmaciones, controles mientras se procesa y recuperación tras fallo. En Jornada comprueba preview, asignación múltiple, Mis casillas, captura real, foto privada, diferencias, dashboard/gráficas, reportes, cierre/reapertura y ausencia de desbordamiento de página.

## 40. Resultados

**VALIDACIÓN LOCAL COMPLETA.**

| Verificación | Resultado |
| --- | --- |
| Última regresión HTTP/BD Fases 1–7 | **2,527 comprobaciones**, incluidas **437 adicionales de Jornada** |
| Chrome completo Fases anteriores + UX + Jornada | **1,197 comprobaciones**, incluidas **62 de Jornada**, **cero excepciones JavaScript** |
| PHP general / Jornada | **29 / 28** |
| Frontend general / F2 / F3 / F4 / UX / F6 / feedback | **25 / 16 / 16 / 13 / 17 / 24 / 36** |
| Migraciones F3 / F4 / F5 / F6 / F7 | **14 / 16 / 199 / 294 / 168** |
| Sintaxis PHP / JS | **253 / 47 archivos**, cero errores |
| Whitespace | Diff seguido y archivos nuevos sin incidencias; CRLF original respetado |
| Conservación | Registros originales de **131 tablas** preservados; **21 archivos protegidos** con hashes intactos |
| Base local final | **146 tablas, 8 migraciones**, checksums correctos y **cero pendientes**; las quince tablas nuevas están vacías |

La ejecución completa con navegador pasó 2,517 comprobaciones HTTP/BD y las 1,197 de Chrome. Tras la última mejora de metadatos CSV se repitió toda la regresión HTTP/BD, que pasó las 2,527 indicadas, sin cambios posteriores a los scripts de la aplicación. Se mantuvieron los formatos reales, pruebas de concurrencia y proveedor externo falso; no se repitió trabajo de desarrollo ya terminado.

Logs de cierre: `/private/tmp/sicrat-phase7-final-2.log` y `/private/tmp/sicrat-phase7-final-http.log`. Artefactos de Chrome: `/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat_phase1_test_8815de580650`; última suite HTTP/BD: `/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat_phase1_test_3c5e532e870d`. Se inspeccionaron capturas de dashboard, configuración, captura, comparación y acta privada en escritorio/tablet/móvil. Las bases de las suites fueron eliminadas al terminar; sus artefactos privados quedan temporales.

## 41. Nuevos hallazgos

- **CORREGIDO:** descarga de exportación pendiente causaba error interno; ahora es no disponibilidad controlada.
- **CORREGIDO:** baja de archivo necesitaba revalidación dentro del bloqueo frente al envío simultáneo del acta.
- **CORREGIDO:** selector electoral de la pantalla nueva requería el contexto global común.
- **CORREGIDO:** nombres/orden de catálogo repetidos ahora producen conflictos legibles.
- **NUEVO HALLAZGO DE PRUEBA, CORREGIDO:** CDP no podía serializar objetos SVG de ApexCharts en un predicado; el driver evalúa predicados booleanos. No era una excepción JavaScript de la aplicación.
- **NO REPRODUCIDO tras pruebas:** doble vigente, duplicación del conteo, sobrescritura silenciosa de capturas simultáneas o acceso privado concedido sólo por conocer UUID.

## 42. Pendientes para Fase 8

**PENDIENTE PARA OTRA FASE.** QA y ensayo de despliegue en el runtime objetivo, revisión administrativa de configuración real de actas/casillas/permisos, carga representativa, monitoreo y operación de workers/scheduler/proveedores en infraestructura autorizada. Conservar/restaurar respaldo durable antes de despliegue.

No se implementan cómputo legal, distribución legal de combinaciones, certificación oficial, PREP oficial ni inferencias de voto individual. Fase 8 requiere revisión e instrucciones expresas; no se ha iniciado.

## 43. Riesgos conocidos

- La ejecución realizada corresponde al entorno local; el runtime PHP 8.3.6/MySQL 8.0.46 y el VPS permanecen sin ensayar en esta ejecución.
- El bloqueo inicial por elección prioriza integridad y serializa escrituras de Jornada; su capacidad debe medirse con carga representativa antes de producción. No se inventan cifras de rendimiento.
- Las consultas calculan desde versiones vigentes; no hay caché compleja. Evaluar índices/tiempos con volumen real antes de añadir acumulados.
- Exportaciones usan lotes/streaming y autorización vigente; TCPDF conserva su coste de memoria para documentos extensos. Las gráficas PDF limitan categorías visuales, conservando tablas completas.
- Los avisos externos requieren canales, consentimiento y proveedor autorizados. Los workers/scheduler deben operarse con la infraestructura común; programar un trabajo no implica que un servicio haya sido instalado.
- El respaldo verificado está en un directorio temporal. El DDL no ofrece rollback transaccional completo y requiere procedimiento de restauración autorizado.
- La exactitud de las asociaciones y del denominador depende de la configuración administrativa del acta. El bloqueo histórico evita cambios silenciosos; una futura corrección de configuración histórica requeriría operación explícita y auditada.

## 44. git diff

Se revisaron status/diff al inicio y durante la implementación. Todos los cambios permanecen sin staging ni commit. No se revirtieron archivos válidos ni se modificaron SQL/migraciones anteriores.

Resultado final: **28 archivos existentes modificados y 23 nuevos; 51 archivos en total**, sin archivos eliminados. `git diff --stat` de archivos seguidos: **233 inserciones y 101 eliminaciones**; los 23 nuevos se revisaron por separado porque Git todavía no los incluye en ese diff. No hay cambios staged; HEAD permanece en `1b0c637`.

Se revisaron también rutas/controlador, validaciones y bloqueos de Jornada, filtros/alcances, registros de servicios comunes, writers y metadatos, compatibilidad de helpers, frontend y pruebas. `git -c core.whitespace=cr-at-eol diff --check` pasó sin modificar configuración de Git; los archivos nuevos tampoco tienen whitespace sobrante. Los 21 hashes protegidos y el checksum aplicado de 008 coinciden.

**PUNTO DE DETENCIÓN:** entrega de este informe, resultados y riesgos locales. Sin Fase 8, despliegue, cambios de Nginx, cron/systemd, Adariel o servicios del VPS. Esperar revisión expresa del usuario.
