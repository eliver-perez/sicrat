# SICRAT: PWA y consultas sin conexión

Esta fase mantiene PHP/MySQL como autoridad y conserva las reglas de negocio. No hay escrituras offline, colas, formularios pendientes ni sincronización de archivos. No necesita migraciones ni tablas nuevas en MySQL.

## 1. Arquitectura e instalación

`public/manifest.webmanifest` utiliza inicio y alcance `/`, `display: standalone`, idioma español, color `#0099cc` e iconos PNG de 192 y 512 píxeles. Los iconos existentes se declaran como `any`: no se anuncian como maskable sin haber sido diseñados y comprobados para ese recorte. El Apple touch icon apunta al archivo existente de 180 píxeles. Autenticación, layout y shell incluyen los metadatos Apple.

`assets/js/pwa/register.js` es el único lugar que registra el worker. Se retiraron los registros de `global.js` y `autenticar.js`. Requiere HTTPS; localhost se admite para desarrollo. Un navegador sin Service Worker, IndexedDB o Web Crypto sigue trabajando online y no habilita perfiles offline. Esta instalación, igual que el manifest anterior, presupone publicar `public/` en la raíz del origen. No está preparada para montar SICRAT bajo un subdirectorio.

La instalación se mantiene disponible mediante las opciones nativas del navegador (en Safari: Compartir → Añadir a pantalla de inicio). No se agregan controles flotantes ni se fuerza una instalación.

El worker es un coordinador pequeño de módulos separados:

- `policy.js`: rutas de lectura permitidas y estrategias.
- `static.js` y `pwa-assets.js`: assets públicos y versión de contenido.
- `database.js`: transacciones y esquema IndexedDB.
- `vault.js`: cifrado, persistencia de CryptoKey no exportables, perfiles, límites y vigencia.
- `network.js`: lecturas condicionales, conectividad, permisos y revalidación.
- `delta.js`: actualizaciones incrementales de mensajes ya consultados.

`offline.html` es un shell público sin datos personales ni sesión embebida. Recupera automáticamente las capturas autorizadas del último contexto validado en este navegador, incluso después de cerrar la pestaña, el navegador o la PWA. Permite buscar dentro de lo guardado y filtrar por módulo. No copia el HTML privado del servidor. Las pantallas PHP existentes siguen siendo la interfaz principal con conexión.

## 2. Caché técnica

Cache Storage contiene exclusivamente archivos estáticos incluidos en una lista generada desde directorios públicos concretos. Estrategia **cache first**, con nombre `sicrat-static-<hash de contenido>`. La instalación precarga el shell, su CSS/JS, manifest e iconos; el resto de assets públicos se incorpora cuando se solicita.

No se almacenan `/api/*`, `/media/*`, páginas PHP, HTML autenticado, documentos privados ni respuestas de otros orígenes. No se usa una expresión por extensión para permitir arbitrariamente cualquier archivo del sitio. `pwa-assets.js` es la lista explícita generada; no es una lista de archivos privados.

Si falta un asset esencial, la instalación no termina y el worker anterior continúa. La activación elimina únicamente caches con el prefijo técnico de SICRAT y versiones antiguas. El logout no borra esta caché pública.

## 3. IndexedDB

Base: `sicrat-offline`, esquema **2**. La actualización agrega almacenes sin borrar ni reinterpretar los registros de esquema 1 durante el cambio de versión. Esos perfiles heredados requieren una conexión: `CONNECT` los reemplaza únicamente después de obtener un contexto autorizado del servidor.

| Almacén | Clave | Contenido |
| --- | --- | --- |
| `profiles` | `id = usuario:organización:elección` | IDs de contexto, versión de esquema, marca de revalidación y autorización cifrada |
| `records` | `[profile, key]` | Módulo, tamaño, timestamp de uso y contenido cifrado; `key` es SHA-256 de la URL normalizada |
| `keys` | `id = perfil` | Referencia nativa `CryptoKey` AES-256-GCM, `extractable: false`, usos encrypt/decrypt; nunca bytes/JWK/contraseña |
| `recovery` | `id = active` | Solo el identificador del último perfil habilitado tras validar online; no contiene una clave ni token de sesión |

Índices: `records.profile` y `records.module` (`[profile, module]`). No existe almacén de escrituras pendientes.

Dentro del cifrado se guardan URL y filtros de la consulta, JSON, ETag, módulo, última modificación (`updatedAt`) y última comprobación (`checkedAt`). La fecha de validación, el nombre del usuario y el permiso local también permanecen cifrados. Los identificadores numéricos, módulo y tamaños son metadatos visibles; no se guarda el nombre para identificar un perfil bloqueado.

Se conservan como máximo 100 consultas y aproximadamente 12 MiB de ciphertext por perfil. Una respuesta de más de 512 KiB no se incorpora. Se eliminan las consultas menos recientes al superar el límite. El navegador puede aplicar cuotas adicionales o eliminar almacenamiento; nunca se promete que IndexedDB sea un respaldo permanente.

## 4. Aislamiento por cuenta y contexto

Cada perfil genera una clave AES aleatoria distinta con `crypto.subtle.generateKey`, no exportable desde su creación. Las claves de los registros incluyen el perfil completo. AES-GCM autentica también la identidad del perfil y del registro como datos asociados; copiar ciphertext a otra identidad no lo convierte en un registro válido.

Solo existe un contexto desbloqueado a la vez en el worker. El protocolo del worker no entrega la clave a las páginas. Cada cliente/ventana necesita recuperación del contexto vigente o comprobación online. Una ventana offline adicional no hereda el contexto de otra ventana que lo tenga abierto. Las respuestas de servidor incluyen identidad de perfil, huella de permisos e identificador de correlación de sesión; una respuesta de otra cuenta o elección no puede guardarse ni entregarse como respuesta del contexto anterior. Ese identificador público no permite autenticarse en ninguna API.

Cambiar de elección bloquea el contexto anterior. Un login correcto y el logout eliminan todos los perfiles privados locales, incluso si el worker anterior terminó o estaba bloqueado. Los mensajes a otras ventanas retiran el contenido privado ya renderizado; `pageshow` también protege el regreso mediante la caché de navegación del navegador. Operaciones asíncronas anteriores al cambio de contexto no pueden volver a insertar datos después del cierre.

El contexto corresponde a la selección que valida SICRAT. Un contexto general sin elección usa `0`; esto no concede permisos. Las consultas de un administrador conservan el alcance que ya les asigna el servidor, incluido su alcance global cuando corresponda. No se amplían ni se recortan las reglas de negocio para llenar el almacenamiento.

## 5. Autorización y vigencia de 12 horas

`GET /api/pwa/context` pasa por `AuthMiddleware` y `SessionValidator`. Revisa en la BD la cuenta activa, la sesión, el acceso electoral y los permisos efectivos. Devuelve el momento del servidor y un vencimiento 12 horas después. Nunca devuelve contraseña, token de sesión PHP, token de autenticación ni CSRF para almacenamiento offline.

La preparación es automática después de autenticar: `CONNECT` consulta el contexto autorizado, crea o recupera la CryptoKey y ejecuta la revalidación existente antes de escribir `recovery/active`. El perfil se identifica por usuario + organización + elección. Las fechas de concesión, permisos y contenido siguen cifrados con AES-GCM. No se almacena contraseña, secreto del servidor ni un token de sesión PHP. El identificador de correlación de sesión ya existente no permite autenticarse.

`RECOVER` no acepta una cuenta o elección elegida por el cliente: usa exclusivamente `recovery/active`, comprueba la integridad del perfil cifrado y recupera su CryptoKey. Si el servidor es accesible, primero valida cuenta, sesión y permisos mediante el flujo existente. Sin conexión, solo permite leer mientras la concesión original siga vigente y no esté en cuarentena. Recuperar no llama a `renew` sin una validación online real.

Cerrar Safari/Chrome/PWA no elimina la clave ni la concesión: quedan en IndexedDB del mismo origen y perfil de navegador. Abrir a los 30 minutos, 2 horas, 6 horas o 11 h 59 min permite consultar lo guardado. A las 12 horas exactas o después, los datos quedan bloqueados. `sessionStorage` ya no contiene claves ni es un requisito de recuperación: únicamente puede conservar una marca no secreta para que una pantalla desplazada por otra no intente abrirse otra vez. Borrar o perder sessionStorage no prolonga el plazo ni permite elegir otra cuenta.

La autorización cifrada contiene la última validación real. Abrir la aplicación, consultar datos guardados o desbloquearlos offline no renueva la vigencia. Pasadas las 12 horas, los datos quedan bloqueados y la interfaz retira su contenido. El shell sigue abriendo y pide conectarse para validar nuevamente cuenta y permisos. Los datos cifrados pueden permanecer hasta renovarlos o eliminarlos; no están disponibles al estar vencidos.

El vencimiento del servidor es absoluto: `expiresAt = validated_at + 43 200 000 ms`. Para compensar diferencias entre relojes sin sumar latencia ni duración del barrido, se ancla el tiempo local al inicio de la petición de validación, antes de contactar al servidor. El tiempo consumido es el máximo del tiempo de pared transcurrido y el tiempo monotónico acumulado durante la ejecución. Se persisten cifrados `localAt`, `lastSeen` y el tiempo consumido; al recuperar no se reemplaza el ancla por la hora de apertura. El plazo declarado tampoco puede exceder 12 horas.

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
- Recuperar automáticamente el último perfil autorizado del dispositivo/navegador, dentro de su vigencia, aun tras cerrar completamente la aplicación.
- Consultar los resultados previamente guardados de las rutas permitidas.
- Filtrar por módulo y buscar **solo entre esas capturas**.
- Ver última actualización, cuenta/contexto y última validación.

Las pantallas PHP que ya estaban abiertas pueden reutilizar GET elegibles con fallback. Al navegar/reabrir sin servidor se presenta el visor común de solo lectura. No se garantiza la ejecución offline completa de cada pantalla heredada ni de sus catálogos auxiliares.

Una consulta no guardada devuelve: “Esta información no está disponible sin conexión.” Los campos del visor solo sirven para filtrar localmente.

## 9. Qué exige conexión

Login/contraseña del servidor; primera autorización del dispositivo; validación tras vencer el plazo; cambios de elección; crear, editar, eliminar, aprobar, rechazar, pagar, transferir, capturar Jornada, enviar mensajes y subir archivos.

El worker trata los métodos mutables como network only. Si la comunicación no está disponible, responde con “Esta operación requiere conexión a Internet.” Los formularios online también se bloquean durante el estado sin conexión. No se registra ni reintenta automáticamente ninguna escritura. Si una conexión se corta después de enviar una operación, el usuario debe verificar su resultado antes de repetirla; no se afirma que el servidor haya revertido lo que pudo haber recibido.

## 10. Imágenes y documentos

Se conserva el uso existente de miniaturas en mensajería/Jornada y de enlaces explícitos para originales y PDFs. El visor offline no crea imágenes, iframes, precargas ni enlaces descargables a partir del JSON almacenado. Los metadatos de adjuntos pueden formar parte del JSON cifrado, pero los bytes privados nunca se guardan en Cache Storage ni se descargan para llenar IndexedDB.

Incluso abrir explícitamente un documento privado no lo hace disponible offline en esta fase. La política conservadora evita copias indefinidas de PDFs, actas y evidencias fuera de la autorización del servidor.

## 11. Logout y limpieza

No existe botón “Datos sin conexión”, panel de administración, tarjeta técnica en Perfil ni controles manuales de sincronización. Se conserva la banda permanente cuando falla la comunicación real con el servidor.

Cerrar sesión elimina transaccionalmente perfiles, registros, referencias CryptoKey y el marcador de recuperación, conservando assets públicos. Las operaciones asíncronas anteriores quedan invalidadas por la generación del contexto. Cambiar a otra cuenta elimina los perfiles anteriores, aun estando bloqueados. Cambiar elección bloquea la anterior y utiliza un perfil diferente.

Si se intenta logout sin comunicación, se cierran y eliminan los perfiles locales, incluso si estaban bloqueados. Se informa que aún se necesita conexión para cerrar la sesión del servidor; no se simula invalidar una cookie HttpOnly remotamente y no se encola un logout pendiente.

## 12. Actualizaciones del service worker

Después de modificar assets, ejecutar:

```sh
php bin/build-pwa.php
```

El generador produce hashes reproducibles, una lista pública permitida y una nueva versión técnica. Se debe incluir `pwa-assets.js` junto con sus assets. El registro usa `updateViaCache: none` para verificar también los scripts importados.

Una versión instalada se activa automáticamente. `controllerchange` recupera el acceso autorizado sin recargar la página ni descartar formularios abiertos. Los nuevos documentos usan los nuevos assets. `activate` elimina caches técnicas obsoletas; no migra, descifra ni mezcla perfiles.

Una terminación del worker elimina sus referencias en memoria. Una apertura posterior recupera la CryptoKey persistida, sin necesitar la pestaña anterior. IndexedDB conserva los datos cifrados y, en un almacén separado, la referencia nativa no exportable.

## 13. Seguridad del almacenamiento

Web Crypto: **AES-256-GCM**, clave aleatoria independiente por perfil y un IV nuevo de 12 bytes por cifrado. El identificador del perfil/registro se autentica como datos asociados. La clave nace con `extractable: false`, de modo que no se puede exportar como raw o JWK. Se persiste mediante clonación estructurada de `CryptoKey`, conservando esa restricción; la aplicación nunca serializa sus bytes. El almacén `keys` está separado de `profiles`/`records`, pero esa separación facilita su eliminación y **no constituye una frontera de seguridad adicional**. El navegador administra la representación de la clave en disco. Este uso de CryptoKey e IndexedDB está contemplado en [Web Crypto del W3C](https://www.w3.org/TR/webcrypto-1/#cryptokey-interface).

Antes de habilitar recuperación persistente se prueba escribir la clave, leerla otra vez y descifrar con ella una comprobación autenticada. No se confía solo en la presencia de `crypto.subtle`. Si la clonación/retención de CryptoKey falla, se mantiene un fallback limitado a la clave en memoria durante la ejecución actual, sin exportarla, sin guardar secretos alternativos y sin escribir una autorización recuperable falsa. La aplicación online sigue funcionando; después del cierre se exige conexión. Se comunica la limitación por el canal de estado existente. No se crea un panel técnico nuevo.

El acceso automático confía en el perfil local del navegador: mientras una cuenta tenga una concesión vigente y no haya cerrado sesión, quien tenga acceso a ese mismo perfil de navegador puede abrir esa cuenta. Cambiar a B o cerrar sesión A elimina las claves de A; no existe un selector offline de otras cuentas. Distinguir dos personas físicas que comparten el mismo navegador sin un nuevo gesto de autenticación requeriría otro mecanismo, fuera de este alcance.

`extractable: false` impide exportación mediante Web Crypto; **no promete Secure Enclave, protección física del disco ni aislamiento frente a código malicioso del mismo origen**. Un XSS, extensión con permisos completos, herramientas de desarrollo o control del dispositivo pueden usar la clave aunque no puedan exportarla. Tampoco existe un reloj persistente confiable contra un atacante que controle simultáneamente hora, almacenamiento y código. La expiración y el aislamiento se aplican al acceso por SICRAT; no se presentan como DRM criptográfico. No se almacena una clave en texto/Base64 junto a los datos ni se expone en localStorage/sessionStorage.

### Safari/iOS y retención

La ruta normal usa APIs estándar de navegadores modernos y comprueba su funcionamiento en cada dispositivo. Se solicita `navigator.storage.persist()` cuando existe para reducir desalojos; su concesión no es obligatoria y no renueva la autorización. [WebKit documenta](https://webkit.org/blog/14403/updates-to-storage-policy/) la Storage API desde Safari/iOS 17 y sus políticas de retención. El navegador puede eliminar datos por presión de almacenamiento, modo privado, acción del usuario o políticas propias. Si se pierde el perfil, la clave o los assets esenciales, el fallback exige conexión; no es posible reconstruir material eliminado sin otra fuente de confianza.

Safari y la PWA instalada pueden utilizar contenedores de almacenamiento distintos: se debe preparar online el contexto desde el contenedor que se usará offline. No se promete compartir automáticamente la autorización entre navegador, instalación, perfiles privados o dispositivos. Quedan pendientes ensayos físicos en Safari/iPhone y PWA iOS: cerrar desde el selector de aplicaciones, activar modo avión, reabrir dentro del plazo, verificar expiración, logout, retención de CryptoKey y eliminación de datos del sitio. Chrome de escritorio se prueba con reinicios reales del proceso; no sustituye esos ensayos físicos.

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
| A. Instalación | Chrome comprueba manifest/iconos y ausencia de errores de instalabilidad; actualización real de versión en espera → activación automática → limpieza. Instalación nativa/standalone real en Android y Safari: manual pendiente. |
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

## Corrección de persistencia offline — 9 de noviembre de 2026

Alcance exclusivo: persistencia/recuperación de autorización y clave local. No modifica encabezado, selector, estilos, Dashboard, módulos, reglas de permisos ni escrituras offline.

Archivos de esta corrección:

- `public/assets/js/pwa/database.js`: esquema 2, almacenes de claves/recuperación y eliminación transaccional.
- `public/assets/js/pwa/vault.js`: creación, prueba y recuperación de CryptoKey, concesión y tiempo acumulado.
- `public/service-worker.js`: `CONNECT`/`RECOVER`, selección del único contexto recuperable y cierre local.
- `public/assets/js/pwa/network.js`: invalidación de la referencia recuperable, limpieza de claves y ancla temporal de la validación; mantiene el barrido de permisos existente.
- `public/assets/js/pwa/register.js`: recuperación sin secreto de pestaña, retirada del secreto heredado y solicitud de retención.
- `public/pwa-assets.js`: manifiesto regenerado.
- `tests/pwa-persistence-browser.js` (nuevo), `tests/pwa-browser.js`, `tests/integration.php`: ensayos de persistencia y regresión PWA.
- `PWA_OFFLINE.md`: diseño, alcance, límites y validación.

La prueba nueva usa un proxy local que bloquea la red incluso antes de arrancar un proceso Chrome nuevo. Cierra completamente Chrome, conserva solo su perfil de disco temporal y limpia sessionStorage. Simula concesiones cifradas con edades de 30 minutos, 2 horas, 6 horas, 11 h 59 min y más de 12 horas; no agrega endpoints de pruebas al producto. Comprueba concesión inalterada al abrir, datos autorizados, retroceso del reloj, renovación real, cuentas A/B, rechazo de selección arbitraria, eliminación tras logout, exportación de clave rechazada, migración de esquema 1 y fallback ante DataCloneError.

Validación final de esta corrección:

- `php tests/integration.php --pwa`: completada sobre MariaDB 10.4.21 temporal; 151 comprobaciones HTTP/BD, nueva suite de persistencia con cierres completos de Chrome y 72 comprobaciones de regresión PWA en Chrome sin excepciones JavaScript.
- `node tests/pwa-unit.js`: 13 comprobaciones de política/deltas, sin cambios a esos mecanismos.
- Sintaxis de los JavaScript/PHP afectados y `git diff --check`: correctos.
- Comparación por hashes contra el estado inicial de esta corrección: ningún cambio adicional a encabezado, selector, estilos, Dashboard, reglas de negocio ni módulos.
- Se comprobó también que, tras perder la cookie HTTP al cerrar Chrome, se puede volver a abrir el login y obtener un CSRF válido. La concesión offline persistida no sustituye la autenticación online.

Artefactos de la ejecución final: `/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat_phase1_test_a5cb3fda2253/`. Se usaron una instancia MariaDB aislada en `/tmp`, bases desechables y perfiles Chrome temporales. No se modificaron `.env`, la base de SICRAT ni servicios existentes. No se realizaron commits ni despliegues.

## Dashboard y notas personales (noviembre 2026)

`GET /api/dashboard` y `GET /api/dashboard/notes` usan la misma política `network-first`, ETag, cifrado y vigencia de los demás módulos. No se almacena HTML autenticado. Las consultas individuales de notas por UUID no se incluyen en la lista offline. Los parámetros de periodo/página forman parte de la clave de consulta.

Las notas pertenecen exclusivamente a su cuenta, incluso frente a administradores. Son generales respecto a elecciones en el servidor, pero sus copias cifradas se guardan sólo dentro del perfil local actual (cuenta + organización + elección). La selección de otra cuenta no reutiliza esas copias. El shell incluye el filtro «Dashboard y notas», muestra fecha de guardado y mantiene la lectura sin escritura. Las operaciones crear/editar/completar/fijar/eliminar necesitan conexión y no producen colas offline; una escritura exitosa invalida el caché privado mediante el mecanismo existente.

En el Dashboard abierto, una respuesta local se identifica como información guardada. Al reabrir offline se consulta a través del shell de lectura. La recuperación de claves, el plazo absoluto de 12 horas, el logout y la revalidación de permisos no cambian. La tabla MySQL de notas requiere la migración `20261109_014_dashboard.php`; es persistencia funcional del Dashboard, no una segunda base offline. Arquitectura y limitaciones en `DASHBOARD.md`.
