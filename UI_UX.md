# Ajustes de UI/UX — noviembre 2026

Esta ronda completa el tema oscuro, unifica la selección de registros y distingue la sesión inválida de una pérdida real de conexión. No incorpora captura, mensajería ni sincronización de escrituras offline.

## Presentación

`data-theme` es la fuente del tema utilizada por Tailwick. `theme.css` comparte la paleta entre tarjetas, tablas, formularios, diálogos, navegación, estados de selección y el shell offline. `theme.js` comunica cambios del tema; los gráficos ApexCharts del Dashboard actualizan sus ejes, cuadrícula y tooltip sin volver a consultar datos.

Los archivos de marca disponibles contienen la versión azul de SICRAT. Sobre fondos oscuros se presenta su silueta original en negativo monocromo mediante CSS; no se usa el logotipo de Tailwick ni se altera la imagen fuente. Login, navegación, encabezado y shell offline comparten el tratamiento. Los logotipos pequeños del menú usan el PNG local en lugar de cadenas base64 incrustadas.

Las notas tienen borde de acento, encabezado con icono y estados diferenciados para recordatorio, fijada y completada. La actividad conserva su estructura, añade un asunto corto de la fuente autorizada y ofrece un enlace discreto al detalle cuando existe una ruta compatible. No expone JSON de auditoría; mantiene los filtros de cuenta, organización, elección, permisos y alcance.

## Selección y búsqueda

`SicratUI.selectionTable` implementa filas completas seleccionables, estado `aria-selected`, selección simple/múltiple, Enter/Espacio y navegación con flechas/Inicio/Fin. Los controles conservan el valor real consumido por los formularios. Las tablas paginan y las selecciones múltiples se conservan al cambiar de búsqueda o página.

El patrón se usa en los selectores compartidos, formularios de Operaciones, búsqueda de responsables en Estructura/Personas, coincidencias de identidad y elección de la persona para adjuntar archivos en Servicios. Se conservan los selects breves de estados y opciones fijas. Los controles vinculados de Usuarios muestran código y nombre completo. Los selectores de personas muestran ambos datos cuando los entrega la fuente; los resúmenes existentes con nombre permanecen identificables.

La búsqueda por nombre, apellidos y código usa `utf8mb4_unicode_ci`, con coincidencia parcial, sin distinguir mayúsculas ni acentos. Los predicados de autorización permanecen aplicados antes de la paginación. Una búsqueda vacía sigue funcionando.

## Sesión y conexión

- Una respuesta real 401 activa el flujo de sesión inválida, elimina la autorización offline desde el worker y envía al login con una explicación. También se maneja en `fetch`, en pantallas jQuery y en navegación HTML.
- Logout elimina perfiles/claves locales. Las otras pestañas van al login; el formulario que lo inició recibe su respuesta antes de navegar para evitar interrumpir la petición.
- Un cambio de contexto o de permisos confirmado online vuelve al contexto de trabajo, sin presentarlo como pérdida de conexión.
- Una falla real de conexión conserva el shell de consultas guardadas como vista de lectura. Sigue siendo necesario para reabrir datos autorizados sin almacenar HTML autenticado; no se agrega un acceso técnico al menú.
- Recuperación cifrada, autorización por cuenta/organización/elección y vencimiento absoluto de 12 horas conservan el diseño documentado en `PWA_OFFLINE.md`.

## Archivos

| Área | Archivos |
| --- | --- |
| Tema y marca | `public/assets/css/theme.css`, `public/assets/js/theme.js`, `app/Views/layout/header.php`, `app/Views/auth/index.php`, `public/offline.html` |
| Selección | `public/assets/js/ux.js`, `public/assets/css/platform.css`, `public/assets/js/operations/core.js`, `public/assets/js/structure/assignment.js`, `public/assets/js/persons/identity.js`, `public/assets/js/services/index.js`, `public/assets/js/users/accesses.js` |
| Nombres y búsquedas | `app/Repositories/PersonsRepository.php`, `app/Repositories/UserAccessRepository.php`, `app/Services/UserAccessService.php`, `app/Services/Operations/OptionsService.php`, `app/Services/Resources/Options.php`, `app/Services/StructureService.php` |
| Dashboard | `app/Services/Dashboard/Summary.php`, `public/assets/js/dashboard/index.js`, `public/assets/js/lucide-components.js`, `public/assets/css/dashboard.css`, `public/assets/js/pwa/offline.js` |
| Sesión/PWA | `app/Auth/Session.php`, `public/assets/js/session.js`, `public/assets/js/security.js`, `public/assets/js/pwa/network.js`, `public/assets/js/pwa/register.js`, `public/service-worker.js`, `public/pwa-assets.js` |
| Pruebas | `tests/uiux.php`, `tests/uiux-browser.js`, `tests/integration.php`, `tests/phase3-browser.js`, `tests/phase2.php`, `tests/person-context-browser.js`, `tests/own-responsible-level-browser.js`, `tests/pwa-browser.js` |
| Documentación | `UI_UX.md`, `PWA_OFFLINE.md`, `tests/README.md` |

## Validación reproducible

Los comandos requieren una instancia MariaDB de pruebas y Chrome local; el ejecutor crea y elimina bases desechables, no modifica la base de la aplicación.

```sh
php tests/integration.php --dashboard --uiux
php tests/integration.php --pwa
php tests/integration.php --phase4 --persons-structure --browser
php tests/integration.php --phase4 --browser
node tests/pwa-unit.js
```

`--dashboard --uiux` verifica búsqueda con acentos/mayúsculas, exclusión de otra rama, nombre de persona vinculada, actividad autorizada, formulario real de tareas, selección por fila y teclado, Servicios y sesión sustituida por otro login real. Captura Dashboard, notas, login, selector, Personas, Estructura y Servicios. Revisa anchos 320/390/768/1440 en Dashboard y 390/1440 en las pantallas adicionales, con cambio real de tema.

`--pwa` comprueba cifrado, aislamiento, revocación, cierre completo/reapertura de Chrome, pérdida de sessionStorage, vencimiento, renovación real y logout. Los tests anteriores de selección se adaptan a filas, sin cambiar las reglas funcionales que comprueban. La expectativa antigua de elección vacía al volver a iniciar sesión se actualiza para comprobar la preferencia autorizada ya existente.

Las capturas se guardan en el directorio temporal informado por cada ejecución (`uiux-*.png`, `dashboard-*.png`). Las pruebas de móvil usan emulación de Chrome; no equivalen a un iPhone físico. Queda la comprobación manual de Safari/iOS, PWA instalada, teclado táctil y lectura de pantalla. No se hicieron commits ni despliegues.

### Resultados de esta ejecución

| Suite | Resultado |
| --- | --- |
| Dashboard + UI/UX | 208 comprobaciones HTTP/BD y 48 en Chrome, sin excepciones JavaScript |
| PWA | 151 comprobaciones HTTP/BD, 40 de persistencia con cierre completo de Chrome y 72 de regresión en Chrome |
| Personas y Estructura | 1242 comprobaciones HTTP/BD y 172 en Chrome |
| Fase 4 / Servicios | 1033 comprobaciones HTTP/BD y 28 en Chrome, incluida subida de archivo |
| Política/deltas PWA | 16 comprobaciones |
| Validación estática | Sintaxis correcta en 34 archivos PHP/JavaScript y `git diff --check` sin errores |

Los totales HTTP/BD incluyen casos base repetidos entre suites; no representan casos únicos sumables. Se usó MariaDB 10.4.21 aislado, datos ficticios y perfiles temporales de Chrome. La base habitual y `.env` no se modificaron.

Capturas finales de UI/UX: `/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat_phase1_test_b41fd583c49f/`. Ejecuciones complementarias en el mismo directorio temporal: `sicrat_phase1_test_ab5322782c09` (PWA), `sicrat_phase1_test_3e894abc1577` (Personas/Estructura) y `sicrat_phase1_test_aeae9b6fe11a` (Servicios).
