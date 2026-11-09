# Ajustes móviles y contexto electoral — 9 de noviembre de 2026

Implementación local, sin commits, despliegues ni cambios al Dashboard.

## Comportamiento

- Encabezado en una fila, sin etiqueta superior. El nombre de elección tiene prioridad, se trunca y conserva el texto completo en el título accesible. En móvil, Mensajería sigue disponible en el menú de cuenta.
- Una elección disponible se establece en la sesión PHP al autenticar y el selector queda sin interacción ni flecha. Con varias se restaura la última elección autorizada de este navegador. Una preferencia retirada deja el contexto sin selección y muestra un aviso. Sin elecciones se conserva el acceso general autorizado.
- La preferencia es una cookie HttpOnly, SameSite=Lax, firmada y vinculada a cuenta/organización; Secure según la configuración existente. No contiene permisos y nunca sustituye la comprobación del servidor. No requiere migraciones.
- Inputs, selectores, áreas de texto y controles editables usan al menos 16 px en pantallas pequeñas o dispositivos táctiles, incluidos buscadores y modales. Se conserva el zoom manual. Las tablas mantienen sus contenedores con desplazamiento horizontal.
- Se eliminan el botón flotante, el panel técnico, la tarjeta de administración en Perfil y los controles manuales de actualización. Se conservan el aviso de desconexión real, cifrado, límites de almacenamiento, ETag/304, revalidación de permisos, aislamiento y bloqueo tras 12 horas.
- La PWA prepara automáticamente el perfil autorizado. El secreto aleatorio permanece en sessionStorage de la pestaña; las claves AES solo viven en memoria. Una navegación o reinicio del worker puede recuperar el perfil de esa pestaña sin pedir una frase. Login/logout eliminan los perfiles privados locales; cambiar elección bloquea el contexto anterior.
- La actualización del worker es automática y no recarga formularios abiertos. Se regeneró el manifiesto de assets.

## Archivos

| Área | Archivos nuevos o modificados |
| --- | --- |
| Preferencia y selección en servidor | `app/Auth/ElectionPreference.php` (nuevo), `app/Controllers/AuthController.php`, `app/Services/ElectoralContextService.php` |
| Encabezado y Perfil | `app/Views/layout/header.php`, `app/Views/profile/index.php`, `public/assets/js/electoral-context.js` |
| Estilos globales | `public/assets/css/platform.css`, `public/assets/css/pwa.css` |
| Ciclo PWA | `public/service-worker.js`, `public/assets/js/pwa/register.js`, `public/assets/js/pwa/network.js`, `public/assets/js/pwa/vault.js` (comentario actualizado) |
| Visor offline y assets | `public/offline.html`, `public/assets/js/pwa/offline.js`, `public/pwa-assets.js` (generado) |
| Pruebas | `tests/mobile-context.php` (nuevo), `tests/pwa.php`, `tests/pwa-browser.js` |
| Documentación | `tests/README.md`, `PWA_OFFLINE.md`, `MOBILE_UI.md` (nuevo) |

## Validación realizada

- `php tests/run.php`: 29 comprobaciones; la excepción técnica en stderr es intencional.
- `node tests/frontend.js`: 25 comprobaciones.
- `node tests/ux-frontend.js`: 17 comprobaciones.
- `node tests/phase3-frontend.js`: 16 comprobaciones.
- `node tests/pwa-unit.js`: 13 comprobaciones.
- `php tests/integration.php --pwa`: 150 comprobaciones HTTP/BD y 70 comprobaciones Chrome, sin excepciones JavaScript.
- Sintaxis de todos los PHP/JavaScript modificados y `git diff --check`: correctos.

La suite de integración se ejecutó con MariaDB 10.4.21 en un directorio temporal y puerto aislados, usando las bases desechables de la suite. No se modificaron `.env` ni la base real. Chrome utilizó un perfil temporal.

Los casos nuevos cubren selección única antes de JavaScript, persistencia tras reingreso, preferencia revocada con otras opciones disponibles, rechazo de selección no autorizada y acceso general sin elecciones. Chrome verificó encabezado a 320/390/768/1440 px, controles de al menos 16 px, modales dinámicos, desplazamiento interno de tablas, ausencia del panel y viewport sin restricciones al zoom. La regresión PWA verifica dos cuentas/organizaciones, elecciones separadas, pestañas, recuperación automática sin clave en memoria, cifrado, expiración, renovación, permisos revocados, cierre de sesión y actualización real del worker.

Capturas y logs del ensayo final: `/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat_phase1_test_053b3a625be1/`.

## Límites

- Safari/iPhone físico, teclado nativo, zoom por gesto e instalación standalone en Android/iOS requieren comprobación en dispositivos reales. Chrome con viewport móvil no sustituye esa validación.
- La última elección se recuerda en el mismo navegador, por cuenta y organización; no se sincroniza entre dispositivos.
- El acceso offline requiere conservar la sesión de pestaña. Cerrar esa sesión o perder sessionStorage exige conexión para preparar otra. No se persiste una clave de descifrado junto a los datos privados.
- `node tests/phase2-frontend.js` se intentó y falla en un adaptador anterior de Personas: falta `window.SicratPersonMap.mount`, antes de llegar al selector. Ese archivo y el módulo de Personas no se modificaron. Las comprobaciones nuevas de selección se ejecutaron contra HTTP y Chrome reales.
