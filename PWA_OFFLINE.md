# SICRAT: PWA y consultas sin conexión

Esta fase mantiene PHP/MySQL como autoridad y conserva las reglas de negocio. No hay escrituras offline, colas, formularios pendientes ni sincronización de archivos. No necesita migraciones ni tablas nuevas en MySQL.

## 1. Arquitectura e instalación

`public/manifest.webmanifest` utiliza inicio y alcance `/`, `display: standalone`, idioma español, color `#0099cc` e iconos PNG de 192 y 512 píxeles. Los iconos existentes se declaran como `any`: no se anuncian como maskable sin haber sido diseñados y comprobados para ese recorte. El Apple touch icon apunta al archivo existente de 180 píxeles. Autenticación, layout y shell incluyen los metadatos Apple.

`assets/js/pwa/register.js` es el único lugar que registra el worker. Se retiraron los registros de `global.js` y `autenticar.js`. Requiere HTTPS; localhost se admite para desarrollo. Un navegador sin Service Worker, IndexedDB o Web Crypto sigue trabajando online y no habilita perfiles offline. Esta instalación, igual que el manifest anterior, presupone publicar `public/` en la raíz del origen. No está preparada para montar SICRAT bajo un subdirectorio.

La instalación en Chrome se ofrece cuando el navegador dispara `beforeinstallprompt`. En Safari se explica Compartir → Añadir a pantalla de inicio. No se fuerza una instalación ni se confunde instalar la PWA con autorizar datos privados en el dispositivo.

El worker es un coordinador pequeño de módulos separados:

- `policy.js`: rutas de lectura permitidas y estrategias.
- `static.js` y `pwa-assets.js`: assets públicos y versión de contenido.
- `database.js`: transacciones y esquema IndexedDB.
- `vault.js`: cifrado, claves en memoria, perfiles, límites y vigencia.
- `network.js`: lecturas condicionales, conectividad, permisos y revalidación.
- `delta.js`: actualizaciones incrementales de mensajes ya consultados.

`offline.html` es un shell público sin datos personales ni sesión embebida. Permite desbloquear y consultar capturas de datos, buscar dentro de lo guardado y filtrar por módulo. No copia el HTML privado del servidor. Las pantallas PHP existentes siguen siendo la interfaz principal con conexión.

## 2. Caché técnica

Cache Storage contiene exclusivamente archivos estáticos incluidos en una lista generada desde directorios públicos concretos. Estrategia **cache first**, con nombre `sicrat-static-<hash de contenido>`. La instalación precarga el shell, su CSS/JS, manifest e iconos; el resto de assets públicos se incorpora cuando se solicita.

No se almacenan `/api/*`, `/media/*`, páginas PHP, HTML autenticado, documentos privados ni respuestas de otros orígenes. No se usa una expresión por extensión para permitir arbitrariamente cualquier archivo del sitio. `pwa-assets.js` es la lista explícita generada; no es una lista de archivos privados.

Si falta un asset esencial, la instalación no termina y el worker anterior continúa. La activación elimina únicamente caches con el prefijo técnico de SICRAT y versiones antiguas. El logout no borra esta caché pública.

## 3. IndexedDB

Base: `sicrat-offline`, esquema **1**.

| Almacén | Clave | Contenido |
| --- | --- | --- |
| `profiles` | `id = usuario:organización:elección` | IDs de contexto, versión de esquema, salt aleatorio, marca de revalidación y autorización cifrada |
| `records` | `[profile, key]` | Módulo, tamaño, timestamp de uso y contenido cifrado; `key` es SHA-256 de la URL normalizada |

Índices: `records.profile` y `records.module` (`[profile, module]`). No existe almacén de escrituras pendientes.

Dentro del cifrado se guardan URL y filtros de la consulta, JSON, ETag, módulo, última modificación (`updatedAt`) y última comprobación (`checkedAt`). La fecha de validación, el nombre del usuario y el permiso local también permanecen cifrados. Los identificadores numéricos, módulo, tamaños y salt son metadatos visibles; no se guarda el nombre para identificar un perfil bloqueado.

Se conservan como máximo 100 consultas y aproximadamente 12 MiB de ciphertext por perfil. Una respuesta de más de 512 KiB no se incorpora. Se eliminan las consultas menos recientes al superar el límite. El navegador puede aplicar cuotas adicionales o eliminar almacenamiento; nunca se promete que IndexedDB sea un respaldo permanente.

## 4. Aislamiento por cuenta y contexto

Cada perfil deriva una clave distinta con su propio salt. Las claves de los registros incluyen el perfil completo. AES-GCM autentica también la identidad del perfil y del registro como datos asociados; copiar ciphertext a otra identidad no lo convierte en un registro válido.

Solo existe un contexto desbloqueado a la vez en el worker. La clave no se entrega a las páginas. Cada cliente/ventana necesita desbloqueo local o comprobación online del contexto. Las respuestas de servidor incluyen identidad de perfil, huella de permisos e identificador de correlación de sesión; una respuesta de otra cuenta o elección no puede guardarse ni entregarse como respuesta del contexto anterior. Ese identificador público no permite autenticarse en ninguna API.

Cambiar de elección bloquea el contexto anterior. Un login correcto elimina los perfiles guardados de otras cuentas, incluso si el worker anterior terminó o estaba bloqueado. El logout elimina los perfiles de la cuenta. Los mensajes a otras ventanas retiran el contenido privado ya renderizado; `pageshow` también protege el regreso mediante la caché de navegación del navegador. Operaciones asíncronas anteriores al cambio de contexto no pueden volver a insertar datos después del cierre.

El contexto corresponde a la selección que valida SICRAT. Un contexto general sin elección usa `0`; esto no concede permisos. Las consultas de un administrador conservan el alcance que ya les asigna el servidor, incluido su alcance global cuando corresponda. No se amplían ni se recortan las reglas de negocio para llenar el almacenamiento.

## 5. Autorización y vigencia de 12 horas

`GET /api/pwa/context` pasa por `AuthMiddleware` y `SessionValidator`. Revisa en la BD la cuenta activa, la sesión, el acceso electoral y los permisos efectivos. Devuelve el momento del servidor y un vencimiento 12 horas después. Nunca devuelve contraseña, token de sesión PHP, token de autenticación ni CSRF para almacenamiento offline.

Autorizar un dispositivo necesita una sesión online válida y una frase local de al menos cuatro palabras y 14 caracteres, distinta de la contraseña de SICRAT. Desbloquear con esa frase descifra un perfil existente; **no inicia una sesión contra el servidor**.

La autorización cifrada contiene la última validación real. Abrir la aplicación, consultar datos guardados o desbloquearlos offline no renueva la vigencia. Pasadas las 12 horas, los datos quedan bloqueados y la interfaz retira su contenido. El shell sigue abriendo y pide conectarse para validar nuevamente cuenta y permisos. Los datos cifrados pueden permanecer hasta renovarlos o eliminarlos; no están disponibles al estar vencidos.

Se combina tiempo del servidor, tiempo transcurrido y reloj monotónico durante la vida del worker. Se detecta un reloj local anterior al último observado y se exige conexión. Un navegador sin reloj confiable ni hardware seguro no puede garantizar 12 horas frente a un dueño del dispositivo que manipule deliberadamente reloj, almacenamiento y código. Ese límite del entorno no se oculta ni se presenta como resuelto mediante JavaScript.

## 6. Reconexión y revocación

`/api/pwa/ping` es una comprobación pequeña de comunicación real. Se verifica el JSON esperado; `navigator.onLine` únicamente dispara una comprobación, no autoriza acceso. La página comprueba al abrir, recuperar visibilidad, recibir eventos de red y cada 30 segundos mientras está visible. El worker comparte las comprobaciones simultáneas.

Al reconectar, abrir una página con un perfil desbloqueado o necesitar una nueva validación, se vuelve a consultar el contexto. Mientras dura la verificación se retiran los datos del visor offline. La renovación sigue estos pasos:

1. Cuenta y sesión válidas; comprobar usuario, organización y elección.
2. Si cambiaron permisos efectivos/módulos, eliminar las consultas anteriores del perfil y los otros perfiles de esa cuenta que no se pueden renovar sin desbloquearlos.
3. Poner las consultas conservadas en cuarentena y repetir **cada URL previamente consultada** con su ETag.
4. El controlador original comprueba nuevamente permiso, alcance, asignación y filtros antes de responder. Un 304 también exige esta autorización. Un 403/404 invalida conservadoramente las capturas del contexto para evitar copias indirectas en otros listados; el barrido solo vuelve a guardar respuestas autorizadas. Un JSON actualizado reemplaza la representación anterior.
5. Solo al terminar renovar la autorización cifrada durante otras 12 horas.

Repetir cada consulta detecta pérdidas de alcance que no cambian un permiso nominal: retirada de una estructura, miembros de conversaciones, asignaciones de Jornada o filas que dejaron de pertenecer a un listado. No se depende exclusivamente de una huella de roles. No hay índices de revocación nuevos en MySQL ni reglas duplicadas en JavaScript.

Si falla la comprobación a mitad, se eliminan las capturas del contexto que estaba siendo renovado y no se extiende su plazo. La marca de cuarentena sobrevive al cierre del worker. Es una decisión conservadora: puede reducir la disponibilidad offline ante una conexión intermitente, a cambio de impedir renovar datos no comprobados.

Las lecturas comparten revalidaciones en curso, y las consultas idénticas simultáneas se agrupan. Se limita la frecuencia de barridos en una misma página; con actividad se vuelve a verificar periódicamente. No hay actualización silenciosa de todos los perfiles bloqueados ni descarga completa de una organización.

## 7. Ahorro de datos y políticas por módulo

| Información | Estrategia | Alcance guardado |
| --- | --- | --- |
| CSS/JS/fuentes/iconos públicos | Cache first versionado | Assets usados y shell mínimo |
| Estructura | Stale-while-revalidate | Listado, rama/página o ficha consultados |
| Personas | Network first, fallback cifrado | Página y filtros exactos o ficha consultada |
| Tareas, peticiones, incidencias | Network first, fallback cifrado | Listado o detalle consultado |
| Jornada | Network first, fallback cifrado | Casillas, detalle, resumen o versión consultados |
| Mensajería | Network first e incrementales existentes | Conversaciones y páginas de mensajes consultadas |
| Autenticación, administración, finanzas, catálogos no incluidos, archivos | Red, sin almacenamiento privado PWA | Ninguno en esta fase |

La estructura muestra datos locales de inmediato, indica su última actualización y revalida en segundo plano. Una respuesta nueva emite un evento que actualiza la rama abierta sin recargar toda la página. Los datos críticos usan primero la red y no se presentan como recién comprobados si son fallback.

Los GET elegibles llevan `X-Sicrat-Offline: 1`. `ConditionalResponse` calcula ETag sobre contexto, permisos y JSON **después** del servicio autorizado. `If-None-Match` coincidente produce 304 sin cuerpo; el worker reconstruye la respuesta desde IndexedDB. `Cache-Control: no-store` sigue impidiendo que un caché HTTP guarde una copia privada sin cifrar. No se aplica ETag offline a `/auth/me`, CSRF ni autenticación.

El ETag ahorra transferencia, no elimina el coste de consultar y autorizar en SQL. Un barrido puede requerir hasta 100 comprobaciones pequeñas por perfil. No se inventó un `Last-Modified` global ni se omitió autorización para ahorrar consultas.

Se preservan límites, offsets, búsquedas SQL y navegación por rama. No hay precarga de páginas para llenar el almacenamiento, ni nuevas llamadas a recorridos completos de `pages()`/`loadOptions()` existentes.

Mensajería mantiene `after`, `before`, `changes_after`, `files_after`, sus límites y sus cursores existentes. `delta.js` aplica retiradas y cambios de metadatos a las páginas locales ya vistas. Una retirada elimina texto y adjuntos de esas capturas; los sondeos incrementales vacíos no crean capturas adicionales. No se implementó delta general para Personas/Estructura: requieren un contrato de revisiones y bajas estable; por ahora usan ETag.

## 8. Qué funciona sin conexión

- Abrir el shell instalado/visitado previamente y sus assets esenciales.
- Desbloquear un perfil autorizado, dentro de su vigencia.
- Consultar los resultados previamente guardados de las rutas permitidas.
- Filtrar por módulo y buscar **solo entre esas capturas**.
- Ver última actualización, cuenta/contexto, última validación y almacenamiento usado.
- Bloquear un perfil o eliminar sus datos del dispositivo.

Las pantallas PHP que ya estaban abiertas pueden reutilizar GET elegibles con fallback. Al navegar/reabrir sin servidor se presenta el visor común de solo lectura. No se garantiza la ejecución offline completa de cada pantalla heredada ni de sus catálogos auxiliares.

Una consulta no guardada devuelve: “Esta información no está disponible sin conexión.” Los formularios del visor solo sirven para desbloquear y filtrar localmente.

## 9. Qué exige conexión

Login/contraseña del servidor; primera autorización del dispositivo; validación tras vencer el plazo; cambios de elección; crear, editar, eliminar, aprobar, rechazar, pagar, transferir, capturar Jornada, enviar mensajes y subir archivos.

El worker trata los métodos mutables como network only. Si la comunicación no está disponible, responde con “Esta operación requiere conexión a Internet.” Los formularios online también se bloquean durante el estado sin conexión. No se registra ni reintenta automáticamente ninguna escritura. Si una conexión se corta después de enviar una operación, el usuario debe verificar su resultado antes de repetirla; no se afirma que el servidor haya revertido lo que pudo haber recibido.

## 10. Imágenes y documentos

Se conserva el uso existente de miniaturas en mensajería/Jornada y de enlaces explícitos para originales y PDFs. El visor offline no crea imágenes, iframes, precargas ni enlaces descargables a partir del JSON almacenado. Los metadatos de adjuntos pueden formar parte del JSON cifrado, pero los bytes privados nunca se guardan en Cache Storage ni se descargan para llenar IndexedDB.

Incluso abrir explícitamente un documento privado no lo hace disponible offline en esta fase. La política conservadora evita copias indefinidas de PDFs, actas y evidencias fuera de la autorización del servidor.

## 11. Logout y limpieza

“Datos sin conexión”, disponible en la interfaz común, y la tarjeta de Perfil abren la configuración. Allí se puede autorizar, desbloquear, bloquear y eliminar datos guardados. El tamaño mostrado es la suma de ciphertext del perfil, no una estimación engañosa del espacio libre de todo el dispositivo.

Cerrar sesión elimina los perfiles de esa cuenta, conservando assets públicos. Cambiar a otra cuenta elimina los perfiles anteriores, aun estando bloqueados. Cambiar elección bloquea la anterior y utiliza un perfil diferente.

Si se intenta logout sin comunicación, se cierra y elimina el contexto local desbloqueado. Se informa que aún se necesita conexión para cerrar la sesión del servidor; no se simula invalidar una cookie HttpOnly remotamente y no se encola un logout pendiente. El botón “Bloquear perfil” del visor es exclusivamente local.

## 12. Actualizaciones del service worker

Después de modificar assets, ejecutar:

```sh
php bin/build-pwa.php
```

El generador produce hashes reproducibles, una lista pública permitida y una nueva versión técnica. Se debe incluir `pwa-assets.js` junto con sus assets. El registro usa `updateViaCache: none` para verificar también los scripts importados.

La instalación de una nueva versión no fuerza `skipWaiting` automáticamente. Si hay ventanas abiertas, se ofrece “Actualizar SICRAT”; al activarla se bloquea el perfil, activa el nuevo worker y recarga. Conviene terminar primero los formularios online. También se activa normalmente al cerrar las ventanas antiguas. `activate` elimina caches técnicas obsoletas; no migra, descifra ni mezcla perfiles.

Una terminación del worker por el navegador elimina las claves en memoria. El usuario tendrá que desbloquear otra vez. IndexedDB conserva los datos cifrados, sin una llave persistida que permita abrirlos automáticamente a otra persona.

## 13. Seguridad del almacenamiento

Web Crypto: **PBKDF2-HMAC-SHA-256, 600 000 iteraciones, salt aleatorio de 16 bytes**, y **AES-256-GCM con IV nuevo de 12 bytes por cifrado**. El perfil/registro se autentica como datos asociados. La clave AES es no extraíble y permanece únicamente en memoria del worker. No hay Base64 como sustituto de cifrado, claves en localStorage, contraseña del servidor, secreto de BD ni token permanente offline.

Una frase local corta o reutilizada debilita la protección: se exige una frase de varias palabras y se explica que no debe coincidir con la contraseña de SICRAT. No hay recuperación de esa frase. Si se pierde, se eliminan los datos guardados y se autoriza otra vez con conexión.

El cifrado protege los datos en reposo frente a otra cuenta que use la interfaz y frente a lectura casual del almacenamiento. No protege un dispositivo comprometido, una extensión con acceso completo, un XSS ejecutado mientras el perfil está abierto ni un atacante que conozca la frase. El código del mismo origen comparte la frontera de confianza del navegador; la PWA no crea un aislamiento de procesos entre usuarios del sistema operativo. Las contraseñas del servidor nunca se aceptan como autorización offline.

Referencias: [Web Cryptography API, W3C](https://www.w3.org/TR/webcrypto-1/) y [cifrado del lado cliente, Chrome](https://developer.chrome.com/docs/modern-web-guidance/guides/security/client-side-encryption).

## 14. Compresión y límites actuales

La configuración Nginx local inspeccionada tiene gzip comentado. No se modificó infraestructura. El bootstrap utiliza `ob_gzhandler` para HTML/JSON cuando PHP dispone de zlib, no tiene un gestor de compresión ya habilitado. El buffer ordinario de PHP no impide la compresión. Los archivos binarios de media/descargas conservan su tamaño y contenido originales. El navegador sigue negociando `Accept-Encoding`.

Los assets servidos directamente por Nginx requieren habilitar su compresión allí. No se aplicó ni se recargó configuración del servidor. Un ejemplo para revisión operativa futura dentro de `http`/`server`, si no existe compresión en el proxy:

```nginx
gzip on;
gzip_vary on;
gzip_min_length 1024;
gzip_types application/json application/javascript text/css image/svg+xml application/manifest+json;
```

Nginx ya contempla HTML al habilitar gzip. No se deben recomprimir PNG/JPEG/PDF ni agregar un segundo compresor sin revisar el proxy. Brotli no se instaló.

Límites adicionales: visor offline común por consultas, sin navegación íntegra de todos los formularios PHP; archivos privados solo online; búsquedas offline limitadas a lo visto; cuotas y posible eliminación del almacenamiento por el navegador; desbloqueo nuevamente si se termina el worker; validación conservadora que puede vaciar capturas durante una reconexión fallida; sin reloj hardware confiable; ETag reduce bytes y no trabajo SQL. No hay descarga masiva inicial.

## 15. Pruebas y siguiente fase

Ejecutar desde la raíz:

```sh
php tests/run.php
node tests/frontend.js
node tests/phase3-frontend.js
node tests/pwa-unit.js
php tests/integration.php --pwa
```

La suite PWA reutiliza el aislamiento de `integration.php`: base `sicrat_phase1_test_<hex>` generada, cuentas ficticias, cookies/sesiones y Chrome temporales. `tests/pwa-control.php` rechaza cualquier otra base. La simulación de una actualización del worker está exclusivamente en el router de pruebas, bajo ese mismo control. La suite no necesita ni utiliza documentos reales.

La instalación nativa en Android/iPhone/iPad, la ejecución standalone real en esos dispositivos y las condiciones de HTTPS/proxy de producción requieren una comprobación manual allí; el ensayo de Chrome de escritorio no las sustituye.

La fase posterior necesitará un diseño independiente para cola cifrada de escrituras, idempotencia, permisos al sincronizar, reintentos controlados, conflictos, vencimiento, archivos pendientes y estados visibles al usuario. Nada de ello está habilitado en esta entrega.


## Archivos de la implementación

Nuevos:

- `app/Controllers/PwaController.php`, `app/Pwa/OfflineContext.php`, `app/Pwa/ConditionalResponse.php`.
- `app/Core/HttpCompression.php`.
- `public/offline.html`, `public/assets/css/pwa.css`.
- `public/assets/js/pwa/{register,offline,policy,static,database,vault,network,delta}.js`.
- `bin/build-pwa.php`, `public/pwa-assets.js` (generado).
- `tests/pwa.php`, `tests/pwa-control.php`, `tests/pwa-browser.js`, `tests/pwa-unit.js`.
- `PWA_OFFLINE.md`.

Modificados:

- `app/Core/Response.php`, `app/Core/bootstrap.php`, `app/Routes/api.php`.
- `app/Views/layout/header.php`, `app/Views/auth/index.php`, `app/Views/profile/index.php`.
- `public/manifest.webmanifest`, `public/service-worker.js`.
- `public/assets/js/global.js`, `public/assets/js/autenticar.js`, `public/assets/js/structure/index.js`.
- `tests/integration.php`, `tests/phase3-browser.js`, `tests/router.php`, `tests/README.md`.

## Checklist de aceptación

| Grupo solicitado | Verificación |
| --- | --- |
| A. Instalación | Chrome comprueba manifest/iconos y ausencia de errores de instalabilidad; actualización real de versión en espera → botón → activación → limpieza. Instalación nativa/standalone real en Android y Safari: manual pendiente. |
| B. Assets | Reapertura sin red, shell/CSS/JS/iconos, caché pública conservada después del logout. |
| C. Datos | Autorización, almacenamiento de consultas visitadas, reapertura, desbloqueo y mensaje para consulta no guardada. |
| D. Separación | Dos cuentas y organizaciones; dos elecciones de la misma cuenta; otra pestaña no hereda descifrado; cambio de cuenta incluso con worker bloqueado; limpieza del DOM previo. |
| E. Vigencia | Acceso vigente, autorización cifrada expirada, bloqueo visible y renovación exclusivamente tras validar en servidor. |
| F. Permisos | Revocación granular, retirada de asociación de estructura sin cambiar la huella de permisos, cuenta desactivada y rechazo offline posterior. |
| G. Ahorro | Lectura inmediata de rama con servidor deliberadamente demorado, actualización posterior, ETag/304 sin cuerpo, identidad de cuenta en ETag, gzip JSON negociado. |
| H. Imágenes/documentos | Caché PWA excluye media y documentos; visor no genera descargas de adjuntos. Regresión existente de imágenes privadas/miniaturas de Jornada, según resultados registrados abajo. |
| I. Solo lectura | POST offline rechazado, sin almacén de cola ni reintento al reconectar. |
| J. Reconexión | Sondeo real, revalidación, invalidación, renovación y desaparición de barra permanente. |

El ensayo de actualización modifica solo las respuestas del router de pruebas: no cambia archivos productivos ni despliega versiones. Los artefactos y capturas pertenecen a cuentas ficticias en directorios temporales privados.

## Resultados ejecutados — 6 de noviembre de 2026

| Comando | Resultado |
| --- | --- |
| `php tests/run.php` | 29 comprobaciones PHP; excepción técnica de prueba intencional en stderr |
| `node tests/frontend.js` | 25 comprobaciones |
| `node tests/phase3-frontend.js` | 16 comprobaciones |
| `node tests/pwa-unit.js` | 13 comprobaciones de política/delta |
| `node tests/messaging-frontend.js` | 15 comprobaciones de polling y recuperación |
| `node tests/feedback-frontend.js` | 38 comprobaciones de UI y doble envío |
| `php tests/phase7-unit.php` | 28 comprobaciones de votos/actas/horario |
| `php tests/integration.php --pwa` | 115 comprobaciones HTTP/BD, incluidas 29 PWA, y 53 comprobaciones Chrome sin excepciones JavaScript |
| `php tests/integration.php --phase3 --browser` | 809 comprobaciones HTTP/BD y 14 comprobaciones Chrome de regresión online |
| `php tests/integration.php --jornada-media --browser` | 974 comprobaciones HTTP/BD; 25 comprobaciones Chrome de Jornada y 27 de imágenes/actas/responsive |
| Lint PHP, `node --check`, `git diff --check` | Sin errores en los archivos revisados |

Se utilizó MariaDB 10.4.21 en una instancia temporal separada: el MySQL local de la aplicación estaba detenido. Cada suite creó y eliminó su propia base de pruebas. No se modificó la base real, `.env`, Nginx ni sus servicios. La captura del visor offline a 390 px se inspeccionó visualmente; no presentó desbordamiento horizontal.

Artefactos de la última suite PWA: `sicrat_phase1_test_295ca0b0bbeb`, con `pwa-offline-mobile.png`, dentro del directorio temporal del sistema. Regresión de imágenes: `sicrat_phase1_test_13b2c834c84d`. Estos directorios contienen únicamente fixtures y logs de pruebas; no forman parte del despliegue.

Pendiente de verificación manual: instalación y apertura standalone real en dispositivos Android/iPhone/iPad, comportamiento del almacenamiento bajo expulsión/cuota del dispositivo y configuración HTTPS/compresión de assets del entorno final. No se afirmó haber probado hardware móvil ni desplegado a producción.
