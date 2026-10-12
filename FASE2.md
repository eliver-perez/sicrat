# Fase 2 — Multielección e identidad

Fecha: 11 de octubre de 2026, hora de Monterrey.

## 1. Resumen de lo implementado — IMPLEMENTADO

SICRAT conserva PHP/PDO y sus capas Controlador → Servicio → Repositorio. Se completaron accesos de una cuenta a varias elecciones, selección/cambio de contexto, identidad de personas por organización, código permanente, vínculo opcional con cuentas, participación electoral, reglas de identidad configurables e importación CSV/XLSX con revisión y confirmación.

Se leyeron `Diagnostico.md`, `FASE1.md`, código, pruebas y estado/diff de Git antes de editar. El repositorio estaba limpio en `1f1f5ce` (Fase 1); no había archivos no rastreados previos. La línea base pasó con **29 comprobaciones PHP, 25 JavaScript y 83 HTTP/BD**.

Las protecciones de Fase 1 continúan: sesión comprobada en BD, autorización central, organización, CSRF, escape de salida, paginación, revocaciones y errores seguros. No se modificaron hashes de contraseñas ni la política de sesión única. No se hicieron commits ni se intervino en Adariel/VPS.

## 2. Migraciones creadas y aplicadas — IMPLEMENTADO

| Archivo | Cambio |
|---|---|
| `database/migrations/20261011_001_identidad_personas.php` | Código permanente, backfill, sección nullable, banderas de identidad e índices por organización, vínculo nullable cuenta/persona. |
| `database/migrations/20261011_002_importaciones_personas.php` | Revisiones de importación y filas temporales, relaciones e índices específicos. |
| `database/migrate.php`, `app/Core/Migrations.php` | Ejecutor CLI explícito, orden de archivos, bloqueo, registro de aplicación y checksum. |
| `database/preflight.php` | Diagnóstico de lectura mediante contadores, sin exponer datos personales. |
| `database/migrations/README.md` | Procedimiento, commits implícitos del DDL, reanudación y límites de reversión. |

Antes de migrar, la base local tenía 2 personas y 2 usuarios. No se encontraron grupos de CURP/clave repetidos ni accesos/participaciones entre organizaciones distintas. Los campos nuevos de vínculo empiezan en NULL; no fue necesario conciliar registros históricos.

Se creó un respaldo privado de la base local completa, se restauró en una base desechable y se ejecutaron allí ambas migraciones. Se compararon **todos los campos originales** de personas, usuarios y organizaciones antes/después. Se repitió el ejecutor para comprobar que no reaplica cambios. Después se aplicaron las migraciones a la base local `sicrat` y se repitió la comparación, sin diferencias en los campos originales. Las 2 personas recibieron códigos; quedaron 0 códigos pendientes y 0 repetidos. La copia de ensayo se eliminó.

Respaldo local temporal, con acceso privado:

`/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat-phase2-backup-20261012-035551-4bc6a3/sicrat-before-phase2.sql`

SHA-256: `7de4a1a790da43a08c73412f498a4e1bf1359574c1f75c58bcc696a4bb50f6cc`.

El respaldo contiene información de la base y debe conservarse de forma privada. Está en una ubicación temporal del equipo, no dentro del repositorio.

Las migraciones conservan datos y admiten reanudar sus pasos parciales. No incluyen un `down` destructivo que borre códigos permanentes o vínculos ya usados. Restaurar una versión anterior exige considerar sus altas incompatibles con el código obligatorio y las personas nuevas sin sección; se documentó la reversión mediante respaldo o migración compensatoria según los datos reales.

**Ambos SQL originales siguen intactos.**

## 3. Cambios en usuarios multielección — IMPLEMENTADO

Se reutilizan `usuarios`, `procesos_electorales` y `usuarios_procesos`, incluyendo su restricción única por usuario/elección. Desde la tabla de Usuarios se abre un diálogo para consultar accesos, añadir otra elección, cambiar el tipo base o desactivar/reactivar un acceso existente. La baja conserva la relación y utiliza `activo`/`f_baja`.

La cuenta mantiene su usuario, contraseña y sesión. Cada acceso puede tener otro tipo base. El backend verifica que elección y cuenta pertenezcan a la misma organización; una cuenta sin organización necesita revisión/asignación explícita antes de recibir estos accesos. No se deduce su organización de una relación electoral histórica.

La administración de accesos conserva el permiso de administración de usuarios de Fase 1: **Super Administrador**. No se añadió un modelo completo de administración electoral por cliente.

## 4. Selección y cambio de elección — IMPLEMENTADO / CORREGIDO

Todas las páginas autenticadas incorporan un selector de elección de trabajo. La API entrega opciones activas y autorizadas con paginación. Un único acceso se selecciona al iniciar sesión; con varios se espera una selección explícita. Puede cambiarse sin iniciar sesión de nuevo.

El cambio requiere sesión y CSRF. Cada petición protegida comprueba en BD el token, cuenta, acceso activo, ausencia de baja, organización y estado activo del proceso. Se recalculan tipo y permisos heredados de ese acceso, conservando las revocaciones individuales. Un rol electoral cuyo nombre sea privilegiado no convierte la cuenta en Super Administrador: ese privilegio procede del tipo global de la cuenta comprobado en BD.

Si la elección o asignación seleccionada deja de ser válida, se conserva el rechazo de sesión de Fase 1: la petición devuelve 401 y exige autenticación de nuevo. La selección del Super Administrador facilita el trabajo y conserva su alcance global. Sus procesos disponibles no requieren una asignación individual.

No se desarrollaron permisos electorales completos ni alcances jerárquicos. Las operaciones nuevas aplican los permisos existentes y verifican acceso al proceso solicitado.

## 5. Cambios en personas — IMPLEMENTADO

La identidad permanece en `personas`, asociada a su organización. No depende de una elección. El alta rápida requiere únicamente nombre; organización es contexto administrativo, no otro dato personal obligatorio. Apellidos, género y sección pueden quedar vacíos.

El listado incluye personas sin sección, código y datos de contacto. Se puede buscar por código o nombre y abrir la ficha para completar apellidos, fecha de nacimiento, género, teléfonos, correo, CURP, clave de elector, OCR, sección y domicilio. Se reutiliza `personas_domicilios` para calle, números, colonia, código postal, referencias y coordenadas. La selección de colonia utiliza los catálogos geográficos actuales.

Solo se escriben campos permitidos y validados. No se permite cambiar organización o código a través de la edición. La API de alta/detalle/edición mantiene el aislamiento entre organizaciones.

## 6. Identificador permanente — IMPLEMENTADO

`personas.codigo_persona` almacena diez caracteres alfanuméricos legibles, generados mediante `random_int`, sin datos personales. Se omiten caracteres ambiguos y se conserva el ID interno y UUID. El índice único cubre todo el padrón; las colisiones reintentan hasta veinte veces.

El backfill asigna códigos solamente a personas que no los tienen, en grupos de cien. La edición ignora intentos de sustituir el código. Se verificó una colisión real del índice con un generador simulado que devuelve primero un código usado y luego otro válido. Repetir migraciones no cambia códigos.

## 7. Uso de personas_procesos — IMPLEMENTADO

La ficha muestra participaciones visibles para el actor y permite incorporar a una o varias elecciones autorizadas de la misma organización. Se reutiliza la unicidad `(proceso, persona)` y se actualiza el registro existente al reactivar, sin duplicar filas. Retirar cambia su estado y fecha; conserva la identidad y la relación.

Una persona puede existir sin participación. Alta e importación no la asignan automáticamente a la elección activa. No se desarrollaron estructuras ni responsables.

## 8. Relación persona ↔ usuario — IMPLEMENTADO

`usuarios.persona` es una FK nullable a `personas`, con índice. Una cuenta puede vincularse o desvincularse desde su diálogo de Usuarios, buscando una ficha por código/nombre. La API comprueba coincidencia de organización entre cuenta y persona.

Las cuentas y personas anteriores se conservaron sin conversión automática. No se creó una persona para cada cuenta ni una cuenta para cada persona. No se impuso una restricción adicional de una sola cuenta por persona: la cuenta referencia una única ficha opcional y los vínculos son explícitos.

## 9. Reglas de CURP y clave de elector — IMPLEMENTADO

Dos banderas independientes por organización, activadas por defecto: `curp_unica` y `clave_elector_unica`. La configuración requiere Super Administrador o el permiso administrativo `admin` existente, dentro del ámbito permitido. No se concedió ese permiso automáticamente a tipos de usuario.

Con regla activa se impide una nueva identidad con identificador repetido y se presentan coincidencias para abrir/utilizar la ficha existente. Con regla desactivada se permite continuar explícitamente tras una advertencia. CURP y clave nuevas se recortan y convierten a mayúsculas. Los identificadores no fusionan fichas.

No se añadió un UNIQUE físico para CURP/clave. Hay índices por organización e identificador, y **bloqueo transaccional de la fila de organización** compartido por alta, edición y configuración. Las comprobaciones estrictas usan lecturas actuales bajo bloqueo, incluso cuando una transacción ya realizó otra lectura. Se probó con dos conexiones/procesos simultáneos: una sola alta obtuvo la CURP y la otra fue bloqueada.

Los duplicados históricos permitidos se conservan al volver a activar una regla. Editar otros campos no obliga a destruirlos; cambiar un identificador se somete a la regla correspondiente.

## 10. Posibles duplicados — IMPLEMENTADO

Se buscan coincidencias simples de nombre completo, teléfono y correo, además de identificadores. Nombre usa comparación `utf8mb4_unicode_ci`, tolerante a mayúsculas y acentos. Nombre/contacto no son claves únicas.

La advertencia ofrece abrir la ficha o confirmar una persona nueva cuando las reglas estrictas lo permiten. Las coincidencias de identificadores se priorizan frente a homónimos. La consulta de coincidencias es acotada e informa si existen más. No hay fusión automática ni un algoritmo complejo de similitud.

## 11. Importación CSV/XLSX — IMPLEMENTADO

Flujo: cargar archivo → asignar columnas → revisar lotes y coincidencias → decidir por fila → consultar resumen previo → confirmar → aplicar lotes → consultar resultados paginados.

- CSV UTF-8: coma, punto y coma o tabulación; encabezados obligatorios. XLSX: primera hoja, con fechas convertidas a AAAA-MM-DD.
- Límites explícitos: 10 MB, 20 000 filas y 50 columnas. También se aplican los límites de carga de PHP del servidor. El lector trabaja secuencialmente y guarda filas de revisión mediante transacciones acotadas; no carga todo el padrón/archivo en memoria.
- Revisión y aplicación: hasta cien filas por petición. La revisión utiliza POST y CSRF porque persiste su resultado.
- Mapeo con lista cerrada de destinos; columnas sin asignación se ignoran. Una sección puede importarse por número cuando sea única en el catálogo; un número ambiguo debe completarse mediante la selección de la ficha.
- Detección desactivada: no se comparan nombres/teléfonos/correos; CURP/clave y sus reglas administrativas siguen aplicándose.
- Detección activada: muestra coincidencias en el padrón y en filas anteriores del archivo. Permite nueva identidad cuando se autoriza, utilizar existente, completar vacíos u omitir. Para coincidencias del propio archivo puede utilizarse una fila anterior que haya generado una persona.
- Coincidencias sin decisión impiden confirmar. Filas inválidas se muestran antes de confirmar y posteriormente cuentan como rechazadas. El resumen previo distingue crear, utilizar, completar, omitir, inválidas y decisiones pendientes.
- Completar relee/bloquea la persona y llena únicamente campos vacíos; conserva valores existentes. No hay reemplazo automático.
- La identidad y el resultado de su fila se guardan en la misma transacción. Reintentar confirmación o aplicación no duplica personas ya procesadas. La regla estricta se vuelve a comprobar al aplicar, porque otros escritores/configuración pueden cambiar desde la vista previa.
- Resultado: nuevos, vinculados a fichas existentes, completados, omitidos, rechazados y errores. “Vinculado” en este resumen significa utilizar la ficha para esa fila, **no** una participación electoral o vínculo con una cuenta.
- Se comprueba propietario, organización y caducidad en todas las acciones, incluyendo resultados. Las revisiones expiran a las 24 horas; al cargar otro archivo se limpia una importación vencida, con sus filas mediante FK. No hay infraestructura de trabajos en segundo plano.
- El archivo original no se conserva. El temporal de lectura XLSX es privado y se cierra al terminar. Se rechazan fórmulas XLSX, macros, componentes binarios/objetos incrustados, vínculos externos y archivos ZIP con dimensiones excesivas. CSV se trata como texto; no ejecuta contenido.

## 12. Dependencias añadidas — IMPLEMENTADO

Se añadió únicamente **`openspout/openspout` 4.32.0**, sin dependencias transitivas nuevas ni cambios de versión en TCPDF/QR/settings. Es un lector estándar y secuencial; evita un lector XLSX artesanal y encaja en el servicio de importación.

La [declaración oficial de requisitos](https://raw.githubusercontent.com/openspout/openspout/v4.32.0/composer.json) admite PHP 8.3, 8.4 y 8.5 y requiere DOM, fileinfo, filter, libxml, XMLReader y ZIP. Las extensiones locales están disponibles. Se fijó esa versión para mantener la compatibilidad objetivo; no se eligió la rama mayor que exige versiones posteriores de PHP.

Composer instaló una biblioteca y reportó cero actualizaciones/eliminaciones de las existentes y ninguna advertencia de vulnerabilidad durante esa operación. `composer validate` pasó con avisos de licencia no declarada (previo) y versión exacta (decisión descrita). `check-platform-reqs` pasó. Las advertencias de deprecación emitidas por Composer local pertenecen a su propio código; no modificaron la aplicación.

## 13. Archivos modificados y añadidos

### Modificados por esta fase (16)

- `app/Auth/Authorization.php`
- `app/Auth/SessionValidator.php`
- `app/Controllers/AuthController.php`
- `app/Repositories/PersonsRepository.php`
- `app/Routes/api.php`
- `app/Services/PersonsService.php`
- `app/Views/dashboard/index.php`
- `app/Views/layout/footer.php`
- `app/Views/persons/index.php`
- `app/Views/users/index.php`
- `composer.json`
- `composer.lock`
- `public/assets/js/persons/index.js`
- `public/assets/js/users/index.js`
- `tests/README.md`
- `tests/integration.php`

### Añadidos (29)

- `FASE2.md`
- `app/Controllers/ElectoralContextController.php`
- `app/Controllers/PersonIdentityController.php`
- `app/Controllers/PersonImportController.php`
- `app/Controllers/UserAccessController.php`
- `app/Core/Migrations.php`
- `app/Core/PersonCode.php`
- `app/Repositories/ElectoralContextRepository.php`
- `app/Repositories/PersonIdentityRepository.php`
- `app/Repositories/PersonImportRepository.php`
- `app/Repositories/UserAccessRepository.php`
- `app/Services/ElectoralContextService.php`
- `app/Services/PersonIdentityService.php`
- `app/Services/PersonImportService.php`
- `app/Services/UserAccessService.php`
- `app/Views/persons/phase2.php`
- `app/Views/users/accesses.php`
- `database/migrate.php`
- `database/migrations/20261011_001_identidad_personas.php`
- `database/migrations/20261011_002_importaciones_personas.php`
- `database/migrations/README.md`
- `database/preflight.php`
- `public/assets/js/electoral-context.js`
- `public/assets/js/persons/identity.js`
- `public/assets/js/persons/import.js`
- `public/assets/js/users/accesses.js`
- `tests/identity-writer.php`
- `tests/phase2-frontend.js`
- `tests/phase2.php`

## 14. Pruebas realizadas

| Comando/verificación | Resultado |
|---|---|
| `php tests/run.php` | 29 comprobaciones correctas. |
| `node tests/frontend.js` | 25 comprobaciones de Fase 1 correctas. |
| `node tests/phase2-frontend.js` | 16 comprobaciones nuevas correctas. |
| `php tests/integration.php --phase2` | 294 comprobaciones correctas: 83 de regresión original, 2 de conservación al migrar y 209 adicionales de Fase 2. |
| Sintaxis PHP de aplicación, pruebas y migraciones | 113 archivos correctos. |
| Sintaxis JavaScript propio/pruebas | 21 archivos correctos. |
| Whitespace considerando los CRLF existentes | Sin errores. |
| Composer y extensiones | Validación correcta; avisos descritos en sección 12. |
| Migraciones sobre esquema original con datos previos | Conservación e idempotencia comprobadas. |
| Restauración del respaldo local y ensayo sobre el estado real | Correctos; copia desechable eliminada. |
| Migración local y comparación de campos originales | Sin cambios en los campos originales. |
| Hashes de SQL/diagnóstico/Fase 1 | Sin cambios. |

La suite ampliada ejecuta primero todas las comprobaciones de Fase 1 y dos comprobaciones adicionales de conservación al migrar. Después cubre una/varias elecciones, selección/cambio, rol diferente, accesos ajenos/no asignados/inactivos, cuenta única, alta rápida, código/permanencia/colisión, personas sin/una/varias participaciones, retiro/reactivación, organización, vínculo con cuenta, CURP y clave activadas/desactivadas independientemente, homónimos sin fusión, edición, fechas, domicilio y concurrencia.

Importación incluye CSV con **más de mil filas**, columnas desconocidas, validación, lotes, detección activada/desactivada, regla estricta no evadible, coincidencias internas, decisiones, completar únicamente vacíos, ausencia de asignación electoral, resultados, reintentos, XLSX real, fórmulas/macros rechazadas, caducidad, limpieza, CSRF y aislamiento. También se manipulan datos de contexto en una sesión de prueba para comprobar que la BD sigue siendo la autoridad.

Todas las escrituras de prueba pertenecen a bases desechables; el bloque de limpieza las elimina. La prueba concurrente también exige el patrón de base desechable antes de escribir. Logs/cookies ficticios permanecen en temporales privados. Instrucciones en `tests/README.md`.

## 15. Resultados

**IMPLEMENTADO:** objetivos de multielección, identidad independiente, participación, código permanente, configuración y carga/revisión/importación por lotes.

**CORREGIDO:** falta de selección electoral, aceptación de procesos inactivos como contexto, alta obligada a tener sección/apellido/género y exclusión de personas sin sección del listado.

Las cuentas, datos originales y protecciones de Fase 1 se conservaron. La base local está migrada y las pantallas pueden utilizar el esquema nuevo. La validación se realizó normalmente en el entorno local autorizado.

## 16. Nuevos hallazgos — NUEVO HALLAZGO / CORREGIDO

- El permiso `superadmin` heredado de un tipo electoral podía habilitar operaciones administrativas al introducir el cambio de elección. Se excluyó de esa herencia para cuentas ordinarias y se impidió asignar ese tipo global como acceso electoral a ellas; se probó también una relación histórica alterada directamente en la base desechable. Las concesiones individuales explícitas de Fase 1 no se modificaron.
- Una transacción con una lectura anterior puede conservar una fotografía de datos antigua aunque después obtenga un bloqueo. Se usaron lecturas actuales de política, identificadores, persona y domicilio para mantener la integridad y no sobrescribir información recién completada.
- Los scripts incluidos en las vistas se cargan antes de jQuery, que está en el footer. Las funcionalidades nuevas esperan a `InitializeValues`; se añadió una prueba de carga sin jQuery presente para evitar fallos de inicialización.
- La retirada/inactivación del acceso seleccionado debe rechazar el contexto; se verificó en peticiones reales sin debilitar la política de sesiones.
- Los duplicados históricos permitidos no desaparecen al activar una regla. Se conservan y se bloquean nuevas identidades/cambios que repitan el identificador.

## 17. Pendientes para Fase 3 o posteriores — PENDIENTE PARA OTRA FASE

Estructuras, responsables, jerarquías/niveles, equipos compartidos/clonados, permisos globales/electorales completos, alcances y habilitación de módulos. Auditoría general, aprobaciones, archivos generales, notificaciones, exportaciones/trabajos y módulos operativos también permanecen fuera de esta fase. No se añadieron tablas/servicios para ellos.

Los duplicados geográficos previos no se modificaron. No se cambió el catálogo ni se inventaron secciones para altas rápidas.

## 18. Riesgos conocidos y límites — NO REPRODUCIDO / validación pendiente

- La comprobación final en PHP 8.3.6/MySQL 8.0.46/Nginx del entorno objetivo se realizará antes del despliegue, como se acordó. Código y consultas nuevos utilizan APIs/sintaxis compatibles; no se intervino en ese servidor.
- Los adaptadores DOM/jQuery verifican comportamiento/escape, pero no equivalen a una revisión visual completa en navegador. Esta sigue siendo una comprobación de aceptación útil.
- La importación necesita mantener su pestaña abierta para avanzar los lotes; no es un trabajo en segundo plano. Los resultados quedan consultables por la API durante su vigencia. Si una ficha/configuración cambia desde la revisión, una fila puede rechazarse al aplicar y debe revisarse en el resultado.
- Los límites efectivos de carga dependen también de PHP; no se modificó su configuración. No se certifica aquí rendimiento a escala de producción.
- El bloqueo por organización serializa escrituras de identidad de esa organización. La integridad configurable corresponde a los servicios; escrituras SQL externas que los omitan requieren sus propios controles.
- El respaldo local está en un directorio temporal. No se convirtió su conservación en un servicio de respaldos de la aplicación.

## 19. Estado de git diff y punto de detención

Git no contiene commits nuevos. El diff parte de Fase 1 y se limita al código/documentación/pruebas de Fase 2, salvo `.DS_Store`: apareció modificado durante la sesión, no formó parte de las ediciones de código y se preservó sin sobrescribirlo. No había archivos no rastreados previos.

Se conservaron finales de línea existentes y se revisó el diff sin una reforma estética masiva. Se comprobó whitespace con `git -c core.whitespace=trailing-space,space-before-tab,cr-at-eol diff --check`, sin cambiar configuración de Git.

Hashes protegidos:

| Archivo | SHA-256 |
|---|---|
| `database/database.sql` | `48339f405bccc7a8bd14cee5f16e283aa4a6f5a95c896c6d84fdc925f45e1dcc` |
| `database/colonias mysql.sql` | `b109dee64af11335304554c5babe26abd7a3c9a3f9f6da6935d31aced471e28a` |
| `Diagnostico.md` | `c4c218355ca90e827a5ede395c83494f1fc83cc33b0ab82da6abcdec312f6184` |
| `FASE1.md` | `b39f24f416700eea0bb430e529ae3164b77800dd3dae72b8ac75ba46858c714b` |

**La implementación se detiene aquí. Fase 3 no iniciada. Se espera revisión y aprobación expresa del usuario antes de continuar.**
