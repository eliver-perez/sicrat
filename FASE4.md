# Fase 4 — Servicios comunes

Fecha: 12 de octubre de 2026. Proyecto local: `/opt/homebrew/var/www/sicrat`.

## 1. Resumen de implementación

**IMPLEMENTADO.** Se incorporaron auditoría, aprobaciones comunes, archivos privados, imágenes, media protegido, notificaciones internas y salientes, cola, workers, scheduler, exportaciones y respaldos/restauración. Se conserva PHP/PDO y Controlador → Servicio → Repositorio, junto con las sesiones, CSRF, permisos, organizaciones, elecciones y alcances de las fases anteriores.

La pantalla es `/services/`, accesible desde «Servicios y notificaciones». Cada cuenta ve sus avisos; las secciones administrativas requieren sus permisos. Los respaldos y las restauraciones exigen el tipo global real de Super Administrador comprobado en BD. Los servicios esenciales funcionan con Mensajería deshabilitada.

Antes de editar se leyeron diagnóstico, informes anteriores, migraciones y pruebas; se inspeccionaron arquitectura, extensiones, tablas, FK y Git. La línea base pasó: 809 HTTP/BD, 14 Chrome, 29 PHP y 25/16/16 JavaScript, más 14 comprobaciones de migraciones históricas. Se conservó el cambio previo de `.DS_Store`.

No se hicieron commits, cambios en Adariel/VPS, instalaciones del sistema ni desarrollo de Fases 5–7.

## 2. Migraciones creadas

**IMPLEMENTADO Y APLICADO LOCALMENTE:** `database/migrations/20261012_005_servicios_comunes.php`.

- `auditoria`: organización nullable para operaciones globales, referencia textual, información JSON e índice por contexto/fecha. Conserva JSON antes/después e IDs originales.
- `archivos`: elección, SHA-256, estado, dimensiones y tamaño BIGINT; `archivos_variantes` mantiene normal/miniatura.
- Tablas nuevas: variantes; aprobaciones/pasos; trabajos; notificaciones/configuración/envíos; exportaciones; respaldos/política; restauraciones. Son 11 tablas adicionales.
- Permisos: auditoría, aprobaciones, archivos, notificaciones, exportaciones y consulta/administración de trabajos. Plantilla electoral del administrador, sin privilegios de backup para clientes.

El preflight rechaza contextos históricos incompatibles antes del DDL. Los archivos anteriores se conservan como `historico`, sin asumir que sus rutas son privadas o válidas. No hay conciliación destructiva ni `down` automático. Las cuatro migraciones previas permanecen intactas.

Se respaldó la base local, se restauró en una base desechable, se ensayó la migración y se compararon todos los campos originales de **76 tablas**. Luego se aplicó a `sicrat`, repitiendo la comparación y el ejecutor: sin cambios en registros anteriores ni migraciones pendientes. Quedaron 87 tablas, 2 personas y 2 cuentas; cero archivos, trabajos o respaldos técnicos creados en la base real por las pruebas.

Respaldo previo privado:

```text
/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat-phase4-backup-20261012-172617-7c33d0/sicrat-before-phase4.sql
SHA-256: 60b844ef2db986ad599c5a9f1541b99a30bfe0cd2bd1089b3099d891151548bf
```

Ese dump está fuera del repositorio y no debe compartirse públicamente. El archivo temporal de credenciales se eliminó y la base de ensayo se descartó.

## 3. Auditoría

**IMPLEMENTADO.** `AuditService` reutiliza `auditoria`: organización, elección opcional, actor, acción, entidad, registro numérico/textual, fecha, IP, user-agent, antes/después e información mínima. Se integra en altas de organización/proceso/cuenta, identidad, domicilio, política, participaciones, accesos, vínculos/estado de cuentas, estructura, transferencias, niveles, roles, permisos, territorios, módulos, aprobaciones, archivos/descargas, configuración de canales, exportaciones, trabajos y backups/restores.

Las escrituras relevantes usan la conexión/transacción de su operación. La sanitización central excluye contraseñas, hashes, tokens, cookies, sesiones, llaves, credenciales, dumps y rutas; protege contacto, identificadores personales, dirección y coordenadas. También elimina secretos identificables dentro de texto. El detalle técnico de PDO/proveedores no se persiste como error administrativo.

Consulta paginada con fechas, organización, elección, usuario, acción y entidad. `process=all` permite al Super Administrador consultar operaciones globales aun con elección seleccionada; un cliente necesita alcance de organización para todos sus contextos. No existen operaciones ordinarias de edición/eliminación de auditoría.

## 4. Aprobaciones

**IMPLEMENTADO.** `ApprovalService` recibe entidad/registro, organización, elección opcional, solicitante, pasos explícitos, snapshot e información necesaria del módulo. Los pasos se resuelven en orden, por su aprobador autorizado o el Super Administrador. Comprueba contexto, permiso y alcance del recurso propietario antes de asignar/resolver.

Estados: pendiente, aprobada, rechazada, cancelada y expirada. Conserva motivo, resolvedor y fecha por paso. El snapshot se guarda al solicitar y no tiene API de modificación; cambiar una regla posterior no cambia esa solicitud. La cancelación es explícita y la expiración se procesa en mantenimiento. No se incorporó un lenguaje de reglas ni decisiones financieras.

## 5. Integración con aprobaciones de estructura

**IMPLEMENTADO.** Fase 3 utiliza el componente común `ApprovalState` para estado pendiente, asignación del resolvedor y decisión. Sus solicitudes y resoluciones alimentan auditoría universal y conservan su historial específico.

`estructura_transferencias` continúa siendo la fuente del flujo estructural. No se migraron solicitudes a tablas genéricas. Permanecen versiones, aprobador configurado/superior común/Super Administrador, revalidación de todos los contextos compartidos y permisos del solicitante, rechazo de solicitudes obsoletas y comportamiento de bajas/transferencias. Las 514 comprobaciones de Fase 3 siguen pasando.

## 6. Archivos privados

**IMPLEMENTADO.** `FileService` y `PrivateStorage` utilizan `archivos` y sus variantes. Almacenamiento fuera de docroot, UUID opaco, rutas relativas internas, permisos de directorios 0700/archivos 0600 y comprobación de enlaces/traversal. El navegador recibe metadatos y UUID, nunca la ruta física.

Uploads admitidos: JPEG, PNG, WebP y PDF administrativo. No se permite vídeo, audio, ejecutables, HTML ni SVG subido por usuario. Se valida carga real, tamaño, extensión coherente, MIME obtenido con fileinfo y firma/decodificación. Se rechazan rutas del cliente y extensiones peligrosas, incluso dobles. Límite inicial: 10 MiB, configurable hasta 50 MiB.

Conserva nombre original, contexto, propietario, tipo, MIME, tamaño, hash, estado, uploader, fecha y dimensiones. La baja lógica bloquea media y conserva historia; no borra contenido silenciosamente. Los archivos históricos requieren revisión explícita antes de adaptarse al almacenamiento privado.

## 7. Procesamiento de imágenes

**IMPLEMENTADO.** Se utilizó GD 2.3.3 con WebP y EXIF ya disponibles. Se decodifican píxeles, corrige orientación JPEG, limita dimensión inicial a 1920, recodifica WebP con calidad 80 y produce miniatura de 320. Los valores están centralizados en configuración privada. Se limita la imagen de entrada a 24 millones de píxeles.

La recodificación elimina EXIF/GPS y contenido adjunto; no conserva originales de imagen. Los temporales del upload terminan con la petición. Los PDF conservan su original validado. No se instaló Imagick ni paquetes del sistema. La prueba incluye JPEG con orientación EXIF real y verifica orientación y ausencia de esos metadatos en WebP.

## 8. `/media/{uuid}`

**IMPLEMENTADO.** GET normal y `?size=thumb` requieren validación de sesión, organización, elección, permiso, alcance y recurso propietario. Se comprueba tamaño/hash antes de entregar. Devuelve MIME real, `nosniff`, caché privada/no-store, disposición apropiada y CSP restrictiva; no expone rutas.

`ResourceRegistry` permite registrar el autorizador del módulo propietario, con predicados para listados cuando correspondan. Actualmente integra personas y resultados de exportación. Un propietario desconocido se rechaza; los módulos futuros añadirán su política al registro. El UUID no sustituye autorización.

Entrada PHP: `public/media.php`. Para el futuro Nginx, el enrutamiento deberá conservar el URI original y dirigir `/media/*` a esa entrada, del mismo modo que `/api/*` se dirige a `api.php`. Ejemplo documental, **no aplicado al VPS**:

```nginx
location ^~ /media/ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/media.php;
    fastcgi_param SCRIPT_NAME /media.php;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
```

## 9. Notificaciones internas

**IMPLEMENTADO.** Destinatario, contexto, tipo, título, resumen, entidad/referencia, enlace local, fecha y lectura. Listado paginado, lectura individual, múltiple o de todas las propias. Se restringen destinatario, organización y elecciones aún autorizadas; no se mezclan bandejas. Los enlaces externos, traversal y caracteres de control se rechazan.

Se utilizan también para informar fallos de respaldo al Super Administrador. No dependen de conversaciones, grupos ni habilitación de Mensajería.

## 10. Correo

**IMPLEMENTADO.** `NotificationProvider` y `SmtpProvider`, utilizando PHPMailer. Los envíos se registran y encolan, sin ejecutar SMTP dentro de una petición web. Configuración privada: `SMTP_HOST`, `SMTP_PORT` (587), `SMTP_TLS` (`starttls`/`smtps`), `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM`, `SMTP_FROM_NAME`.

Se utiliza texto UTF-8, TLS y Message-ID estable por envío, sin debug del proveedor. Configuración ausente falla antes del intento y deja error seguro. Las pruebas usan proveedores falsos; no se enviaron correos reales. El código está listo para configurar el proveedor elegido, sin inventar credenciales.

## 11. WhatsApp

**IMPLEMENTADO.** `WhatsAppProvider` utiliza cURL y Cloud API para plantillas operativas autorizadas. Configuración privada: `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_BUSINESS_ACCOUNT_ID`, `WHATSAPP_API_BASE`, `WHATSAPP_API_VERSION`, `WHATSAPP_OPERATIONAL_TEMPLATES`.

La base se limita al endpoint HTTPS oficial `https://graph.facebook.com`; la versión debe configurarse explícitamente. No se asumió una versión vigente. Se registran canal/destinatario, plantilla/parámetros permitidos, fecha, intentos, resultado, referencia externa y error seguro. El panel omite destinatarios y contenido. No se implementaron conversaciones, campañas ni segmentación.

## 12. Consentimientos

**IMPLEMENTADO.** Se reutilizan `canales_contacto`, sus tipos y `consentimientos`. El canal requiere verificación, permiso de contacto y fecha de consentimiento. Una revocación posterior del consentimiento específico EMAIL/WHATSAPP prevalece.

Se comprueban medio, configuración, permisos y consentimiento al solicitar y nuevamente al ejecutar. Tener teléfono o correo en la ficha no constituye por sí mismo autorización. WhatsApp exige además una plantilla de la lista operativa privada. La idempotencia distingue organización, elección, persona, canal y evento lógico.

## 13. Cola de trabajos

**IMPLEMENTADO.** `trabajos` almacena UUID, handler, payload con referencias sin secretos/PII, prioridad, contexto/actor, estado, intentos/máximo, disponibilidad, worker, lease, inicio/fin, error e idempotencia. No existe API pública para encolar handlers arbitrarios.

Estados: pendiente, ejecutando, reintento, completado, fallido y cancelado. El claim usa transacción/FOR UPDATE, compatible con ambos motores, sin depender de SKIP LOCKED. La disponibilidad inmediata usa el reloj de BD. Un bloqueo de conexión por trabajo evita dos ejecuciones simultáneas.

## 14. Workers

**IMPLEMENTADO.** `WorkerService` tiene registro explícito de handlers de notificación, exportación, backup, verificación, restauración y mantenimiento. Relee facultades del solicitante cuando corresponde. `ActorContext` crea el contexto interno del worker desde BD, sin tomar derechos del payload.

Ejemplos CLI, sin crear servicios ni daemons:

```sh
php bin/worker.php --once
php bin/worker.php --max=10 --seconds=60
php bin/worker.php --type=exportacion --max=5 --seconds=120
```

El límite temporal se comprueba entre trabajos; una operación ya iniciada concluye o aplica su timeout específico. El comando devuelve error operativo seguro y exit 1 ante fallo no manejado. Los errores de un job quedan registrados y se administra su estado.

## 15. Reintentos e idempotencia

**IMPLEMENTADO.** Backoff exponencial desde 30 segundos, máximo de una hora entre intentos y contador acotado. Un lease vencido sólo se recupera si desapareció el bloqueo del worker. Al agotarse, se marca fallo y se sincroniza el estado de exportación/backup/restauración interrumpidos.

Cancelación de trabajos pendientes y reintento manual autorizado, auditados. Los clientes administran trabajos propios con permiso; operaciones técnicas exigen Super Administrador. Restauraciones fallidas requieren nueva revisión. Un envío incierto o confirmado nunca se reintenta por esta acción.

Los proveedores externos no garantizan exactamente una entrega ante una desconexión posterior a su aceptación. Para evitar duplicados, un envío ya intentado sin confirmación queda `incierto`, con job fallido y revisión necesaria; no se reenvía automáticamente. La idempotencia de registros y Message-ID no se presenta como garantía de entrega externa.

## 16. Scheduler

**IMPLEMENTADO.** `php bin/scheduler.php` genera jobs por tipo/fecha, idempotentes. Programa BD diaria, full semanal, incremental diario y mantenimiento/retención. Si no hay base full, programa una antes del incremental. Las copias incompatibles esperan otra invocación; no se duplica el trabajo periódico.

Ejemplo para el futuro despliegue, **no instalado ni ejecutado como cron**:

```cron
* * * * * /usr/bin/php /srv/sicrat/bin/scheduler.php
* * * * * /usr/bin/php /srv/sicrat/bin/worker.php --max=10 --seconds=50
```

Se deberá ajustar únicamente la ruta real del proyecto. Mantenimiento también expira aprobaciones y elimina revisiones de importación ya caducadas mediante lotes.

## 17. Exportaciones

**IMPLEMENTADO.** `ExportRegistry` y `ExportService` centralizan solicitante, organización, elección, tipo, filtros, estado, fecha, expiración (7 días), error y archivo privado resultante. Todas las solicitudes pasan a cola, incluidas las grandes.

Exportadores actuales: personas, participaciones, estructura y usuarios/accesos. Búsqueda/sección donde corresponda y alcances se aplican en SQL antes de recorrer lotes de 250. La generación y la descarga revalidan permisos del módulo y permisos granulares, incluyendo el alcance de descarga. Una revocación previa al worker termina la solicitud con fallo seguro, sin dejarla pendiente. Un resultado pertenece al solicitante; otro administrador tampoco lo descarga.

La firma del conjunto autorizado permite rechazar un resultado cuyo alcance/conjunto cambió. Es conservadora: también un cambio de integrantes del conjunto exige regenerar. No se ofrecen SQL, dumps ni JSON crudo al cliente.

## 18. PDF

**IMPLEMENTADO.** `PdfService` concentra TCPDF 6.11.3, con título, encabezado, fecha/generador, filtros, tablas, nuevas páginas, pie y numeración. Permite incorporar imágenes raster privadas validadas; los módulos podrán producir PNG para futuras gráficas. No se agregó una biblioteca de gráficas ni reportes electorales.

Se corrigió durante validación el ancho de logo del encabezado: TCPDF requiere un número y se usa cero cuando no existe logo. No se sustituyó la biblioteca.

## 19. XLSX

**IMPLEMENTADO.** Reutiliza OpenSpout 4.32.0 para escritura secuencial. No se acumula el padrón completo en memoria ni se añadió otra biblioteca de hojas de cálculo. Los valores peligrosos se tratan como texto; se generan libros reales y legibles.

## 20. CSV

**IMPLEMENTADO.** UTF-8 con BOM, escritura secuencial y escape explícito mediante `fputcsv`. Se protege texto que comienza con `=`, `+`, `-` o `@`, incluso precedido de espacios/control. La prueba usa una persona cuyo nombre parece fórmula y confirma su neutralización.

## 21. Paquete de datos del cliente

**IMPLEMENTADO.** ZIP con `Datos del cliente/Personas.xlsx`, `Participaciones.xlsx`, `Estructura.xlsx`, `Usuarios_y_accesos.xlsx`, `Reportes/` y `Archivos/`. Los archivos privados actuales autorizados se incluyen con UUID y un índice CSV de nombres originales; se aplican filtros y autorización del propietario. `Reportes/` queda disponible para exportadores posteriores.

El registro determina los exportadores incluidos y permite ampliarlo. No incluye contraseñas, hashes, tokens, configuración interna, SQL ni datos de otros clientes. Permanece legible y sin cifrado técnico. La descarga vuelve a comprobar también el conjunto de archivos privados autorizados.

## 22. Respaldos de BD

**IMPLEMENTADO.** `DatabaseDump` detecta herramientas nativas instaladas, con posibilidad de configurar `MYSQL_DUMP_BINARY` y `MYSQL_CLIENT_BINARY`. Se comprobó el dumper/cliente XAMPP local. Usa single-transaction, quick, blobs hexadecimales, rutinas, triggers y eventos; comprueba InnoDB para consistencia.

Credenciales en archivo temporal privado, nunca contraseña en argv; stderr nativo no se entrega a la interfaz y se elimina junto con las credenciales. Timeout privado `BACKUP_NATIVE_TIMEOUT`, inicial 1800 segundos. El dump de prueba se restauró y validó en otra base desechable.

## 23. Respaldos completos de archivos

**IMPLEMENTADO.** Respaldan el área permanente `archivos/`, incluyendo variantes; omiten locks, temporales y credenciales. El manifiesto guarda ruta relativa, SHA-256, tamaño y fecha. No acepta enlaces ni objetos no regulares.

Se verifica el contenedor y cada archivo antes de marcar éxito. Las operaciones de almacenamiento y backup/restore comparten mutex de BD y flock, que conserva exclusión aun si se pierde la conexión PDO durante un proceso nativo. Una operación incompatible se rechaza con 409.

## 24. Respaldos incrementales

**IMPLEMENTADO.** Cada incremental identifica full base y anterior. Compara el inventario con el manifiesto previo y copia sólo nuevos/modificados, con tombstones de eliminados. No vuelve a copiar todo el contenido. Verificar/restaurar recorre y valida toda la cadena antes de utilizarla.

El inventario calcula hashes de los archivos para detectar cambios; evitar copiar no elimina ese coste de lectura. Se probaron full y dos incrementales consecutivos, modificación, alta, eliminación, daño y ausencia de un incremental intermedio.

## 25. Retención

**IMPLEMENTADO.** Política inicial persistida: 14 días. Conserva respaldos en curso, protegidos, requeridos por restauraciones activas y todos los ancestros/bases de cadenas retenidas. Elimina físicamente sólo copias vencidas elegibles y mantiene su registro con estado `eliminado`; registra auditoría.

El panel permite proteger/desproteger. El scheduler programa limpieza automática controlada. Se comprobaron preservación de base requerida/protegida y eliminación de una copia independiente vencida.

## 26. Almacenamiento/cifrado

**IMPLEMENTADO.** `BackupDestination` y `FilesystemBackupDestination` permiten almacenamiento local o filesystem montado. `BACKUP_STORAGE_PATH` debe apuntar fuera de docroot y del repositorio. El valor por defecto es un directorio privado hermano del proyecto; en despliegue se debe fijar explícitamente el destino permanente.

`PRIVATE_STORAGE_PATH` configura los archivos privados (por defecto `storage/private`, excluido de Git). Variables adicionales: `PRIVATE_FILE_MAX_BYTES`, `IMAGE_MAX_DIMENSION`, `IMAGE_WEBP_QUALITY`, `IMAGE_THUMB_DIMENSION`.

Se comprueba espacio en destino y área de preparación, con margen para dump, ZIP y validación. Falta de espacio produce fallo claro y notifica al Super Administrador. No se añadió un SDK de almacenamiento externo.

Si existe `BACKUP_ENCRYPTION_KEY` privada (base64 de 32 bytes aleatorios), se utiliza Sodium secretstream XChaCha20-Poly1305, por bloques y con cierre autenticado. La copia cifrada se descifra/verifica antes de marcar éxito. La llave no se guarda junto a la copia ni en BD. Las pruebas cubren descifrado, restauración y rechazo de otra llave. Las exportaciones cliente permanecen claras.

## 27. Restauración

**IMPLEMENTADO.** La web muestra componentes, cantidad de archivos, cadena y revisión del destino configurado; exige escribir `RESTAURAR <uuid>` y confirmar la revisión con CSRF. Encola el trabajo. La verificación de contenido se ejecuta en el worker antes de reconstruir; no convierte la revisión HTTP en una restauración prolongada.

Los destinos web `RESTORE_DATABASE` y `RESTORE_FILES_PATH` deben ser independientes de la instalación activa. Conserva copia previa del destino de BD y directorio anterior de archivos; prepara/valida archivos en staging y los activa por renombrado. Registra estados, error seguro y auditoría. No se sobrescribe la base local de trabajo durante pruebas.

La instalación activa sólo se restaura mediante CLI offline, con Super Administrador, mantenimiento explícito (`SICRAT_MAINTENANCE_FILE`), confirmación y respaldo previo reciente, protegido y verificado que cubra los componentes. Ejemplo documental:

```sh
php bin/restore.php --backup=<uuid> --prior=<uuid_previo> --actor=<id_superadmin> --confirmation='RESTAURAR INSTALACION <uuid>'
```

Antes de usar ese procedimiento en producción: detener invocaciones de workers y escrituras, activar mantenimiento y drenar peticiones. Mantener mantenimiento hasta validar la recuperación; no se retira automáticamente. La herramienta conserva un diario privado, necesario porque restaurar la BD puede sustituir también sus metadatos de auditoría/jobs. La restauración activa **no se ensayó sobre la base real**: las comprobaciones destructivas utilizaron exclusivamente bases/directorios desechables.

## 28. Panel del Super Administrador

**IMPLEMENTADO.** Cantidad, bytes registrados, espacio disponible, última copia satisfactoria, listado paginado, tipo/estado, fecha, duración, protección, verificación, cifrado y error sanitizado. Ubicación lógica, sin rutas privadas. Permite backup manual BD/archivos/incremental/completo, política, verificación en cola y revisión/restauración independiente.

Trabajos y envíos tienen paneles paginados con estados/errores; los jobs permiten acciones controladas. Una concesión electoral o individual llamada `superadmin` no concede administración técnica. Los clientes no generan dumps, restauran ni cambian políticas.

## 29. Dependencias añadidas

**IMPLEMENTADO:** únicamente `phpmailer/phpmailer` **7.1.1**, mediante Composer, para SMTP con TLS, MIME y manejo correcto del protocolo. Compatible con el PHP objetivo y sin actualizaciones/eliminaciones de las otras bibliotecas. Se consultaron su [repositorio oficial](https://github.com/PHPMailer/PHPMailer) y requisitos.

Se conservan TCPDF 6.11.3, OpenSpout 4.32.0 y QR/settings. Se reutilizan GD/EXIF, cURL, ZIP y Sodium. Composer validate/check-platform-reqs pasaron; persisten avisos previos de licencia/versión exacta OpenSpout y deprecaciones de Composer local. La instalación reportó cero alertas de vulnerabilidad.

## 30. Archivos modificados/añadidos

**Añadidos:** `app/Controllers/CommonServicesController.php`, `app/Repositories/CommonRepository.php`; servicios de `app/Services/Common/` (auditoría, acceso/contexto, aprobaciones/estado, recursos, archivos/imágenes/almacenamiento, notificaciones/proveedores, cola/worker/scheduler, exportadores/writers/PDF, destino/archive/crypto/dump/backup/restore, valores/sanitización/mantenimiento/locks); vista y entrada `/services/`, JS de servicios, `public/media.php`, tres CLI en `bin/`, migración 005, cuatro archivos de pruebas Fase 4 y este informe.

**Adaptados:** autorización, bootstrap/respuestas/configuración de entorno; repositorios de identidad/estructura; servicios existentes de identidad indirectamente, cuentas/accesos, organización/proceso, estructura y administración; rutas, sidebar y CSS; Composer; preflight; README de migraciones/pruebas; harness/router y pruebas Chrome/migraciones históricas.

Los SQL originales y los informes/migraciones anteriores no cambiaron. `.env` y la configuración privada existente no se editaron. Ninguna credencial real se añadió al repositorio.

## 31. Pruebas ejecutadas

| Comprobación | Resultado |
|---|---|
| `php tests/run.php` | 29 PHP |
| JavaScript Fases 1/2/3 | 25 / 16 / 16 |
| `node tests/phase4-frontend.js` | 13 JavaScript |
| `php tests/phase3-migrations.php` | 14 históricas/conflictos |
| `php tests/phase4-migrations.php` | 16 preservación/conflictos/idempotencia |
| `php tests/integration.php --phase4 --browser` | **1,033 HTTP/BD**; incluye 224 adicionales Fase 4 |
| Chrome existente | **27**, sin excepciones JavaScript |
| Sintaxis PHP | 176 archivos, sin errores |
| Sintaxis JS | 28 archivos, sin errores |
| Composer y requisitos | Correctos, avisos descritos arriba |
| Backup/restore/ensayo real local | Campos/registros originales de 76 tablas conservados |
| Migración local y repetición | Correctas; 87 tablas, cinco migraciones registradas |
| Hashes protegidos y whitespace | Intactos / sin errores |
| Enlace simbólico en storage | Rechazado antes de crear directorios externos |

HTTP/BD incluye toda la regresión de sesión/CSRF/multielección/identidad/importación/estructura/permisos/módulos, además de casos positivos y negativos de cada servicio, dos workers, formatos reales, más de mil personas, backups nativos y restauraciones desechables. Las bases de pruebas se eliminaron al terminar. No se enviaron mensajes reales.

La excepción de prueba en stderr de `tests/run.php` es intencional. Capturas y logs ficticios permanecen privados en el directorio temporal mostrado por cada ejecución; se inspeccionó `phase4-services.png` y se ajustó la presentación de tablas.

## 32. Resultados

**IMPLEMENTADO / CORREGIDO.** Fase 4 funcional y validada localmente. Se conservaron las fases anteriores y los registros de la base local. Auditoría/colas/notificaciones/seguridad no dependen de módulos opcionales. Se comprobaron aislamiento, permisos/alcances, idempotencia, concurrencia, archivos privados, exportaciones y recuperación técnica desechable.

Hashes SQL originales:

```text
database/database.sql
48339f405bccc7a8bd14cee5f16e283aa4a6f5a95c896c6d84fdc925f45e1dcc
database/colonias mysql.sql
b109dee64af11335304554c5babe26abd7a3c9a3f9f6da6935d31aced471e28a
```

## 33. Nuevos hallazgos

**NUEVO HALLAZGO / CORREGIDO:** `env()` no leía variables nuevas heredadas por el proceso si no estaban también declaradas en `.env`. Ahora conserva la precedencia existente y añade fallback a `getenv`, comprobado por servidor/worker desechables.

**CORREGIDO DURANTE VALIDACIÓN:** inicialización del controlador nuevo, parámetros TCPDF, nombre original/full_path del upload, reloj de disponibilidad de jobs, alcance independiente de descarga, alcance de aprobador/listado, contexto global de auditoría, presentación de tablas y configuración del selector electoral en la vista nueva. Los rechazos permanecen efectivos en backend.

**NO REPRODUCIDO EN LA BASE REAL:** auditoría con organización electoral incompatible o archivos de cargadores de otro cliente. Los escenarios preparados se rechazaron antes del DDL, preservando datos.

## 34. Pendientes para Fase 5 o posteriores

**PENDIENTE PARA OTRA FASE:** conversaciones/grupos/anuncios, encuestas, interacciones, tareas/metas, eventos/brigadas, peticiones/incidencias, transporte, finanzas/presupuestos/ingresos/inventario y Jornada Electoral/resultados/actas. No se desarrollaron ni se crearon datos de esos módulos.

Los nuevos módulos registrarán propietarios, exportadores, handlers y pasos de aprobación conforme a sus reglas. No es necesario reconstruir estos servicios. Proveedor real, credenciales, versión/plantillas autorizadas de Meta, destino permanente, cron y parámetros de producción requieren la configuración elegida para despliegue; no se inventaron ni instalaron en el VPS.

## 35. Riesgos conocidos

Objetivo conservado: Ubuntu 24.04, Nginx 1.24.0, PHP 8.3.6, MySQL 8.0.46 y utf8mb4. Se revisó compatibilidad: APIs PHP disponibles en 8.3 y SQL InnoDB/JSON/FK/índices/FOR UPDATE/GET_LOCK compatible con MySQL 8, sin SQL exclusivo de MariaDB. La validación en las versiones exactas y el enrutamiento/extensiones de producción se harán antes del despliegue, como se acordó.

El DDL produce commits implícitos: coordinar código/esquema y mantenimiento con respaldo validado. No hay reversión destructiva automática. GD/WebP y EXIF son necesarios para el procesamiento real; no se sustituyen por una conversión simulada.

Los locks priorizan integridad: un backup prolongado puede rechazar escrituras incompatibles de archivos con 409. El inventario de archivos/manifiesto tiene coste proporcional a su cantidad; PDF y cadenas grandes requieren medir memoria/tiempo con volumen real. La firma de autorización de exportaciones es conservadora y puede exigir regenerar tras cambios del conjunto.

Preservar llaves de cifrado fuera de backups y coordinar su rotación; perderlas impide recuperar copias cifradas. Una restauración activa no es atómica entre BD/files: exige mantenimiento, respaldo previo, diario y validación manual antes de abrir el sistema. Las herramientas nativas deben corresponder al motor del despliegue. SMTP/Meta necesitan reconciliación humana en resultados inciertos, sin prometer exactamente una entrega externa.

## 36. Estado de git diff

Respecto de HEAD `a00ffc7` (Fase 3), hay **26 archivos existentes modificados** (incluido `.DS_Store` previo) y **47 nuevos**, incluido este informe. Los cambios quedan sin commit ni staging, disponibles para revisión. Se revisaron archivos modificados/nuevos, sintaxis, Composer, hashes y whitespace respetando finales de línea previos. Se preservó `.DS_Store`, modificado antes de implementar.

**Fase 4 terminada. El trabajo se detiene aquí y espera revisión e instrucciones expresas. Fase 5 no iniciada.**
