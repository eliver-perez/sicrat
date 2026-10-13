# Pruebas de SICRAT

No requiere dependencias de desarrollo ni un framework de pruebas.

Desde la raíz del proyecto:

```sh
php tests/run.php
node tests/frontend.js
php tests/integration.php
node tests/phase2-frontend.js
php tests/integration.php --phase2
```

`run.php` comprueba autorización, CSRF, contraseñas, entradas, paginación y respuestas seguras. La excepción técnica que aparece en stderr es intencional: verifica que el detalle se registra internamente y no llega al JSON.

`frontend.js` ejecuta los scripts reales con adaptadores pequeños de DOM/jQuery para comprobar presentación de datos, paginación y envío de CSRF. No sustituye una prueba visual en navegador.

`integration.php` necesita las credenciales locales de `.env`, permisos para crear una base de pruebas y PHP con PDO MySQL y cURL. Crea un nombre aleatorio `sicrat_phase1_test_<12 caracteres hexadecimales>`, importa el SQL original en esa base, prepara datos previos, aplica las migraciones y verifica que se conserven. Prepara dos organizaciones y cuentas ficticias. El servidor PHP hijo recibe exclusivamente esa base y una URL local. El router de pruebas rechaza cualquier otro nombre de base.

Las operaciones de alta, contraseña, expiración, revocación y el fallo de tabla provocado pertenecen únicamente a esa base desechable. El bloque `finally` termina el servidor y elimina exclusivamente esa base. No modifica la base indicada originalmente en `DB_DATABASE`, los archivos SQL ni servicios existentes.

Logs, cookies y sesiones de prueba permanecen en un directorio temporal privado indicado al terminar. Si el proceso se mata sin ejecutar `finally`, revisar ese nombre de base y ese directorio antes de limpiar; no aplicar borrados por patrón amplio.

La opción `--phase2` ejecuta primero toda la regresión de Fase 1 y después las pruebas nuevas de cuentas multielección, contextos, roles, personas, códigos y colisiones, participación, configuración, coincidencias, importaciones CSV/XLSX y decisiones. Utiliza más de mil filas, lotes de hasta cien y dos procesos PHP con conexiones distintas para verificar altas concurrentes estrictas. `identity-writer.php` rechaza bases que no cumplan el patrón desechable y solo se utiliza desde esa prueba.

`phase2-frontend.js` comprueba los scripts reales de ficha, advertencias, selector y revisión mediante adaptadores DOM/jQuery. No sustituye la validación visual en navegador.

Requisitos adicionales de lectura XLSX: OpenSpout instalado por `composer install` y extensiones DOM, fileinfo, filter, libxml, XMLReader y ZIP. No se añaden dependencias de desarrollo. Resultados actuales documentados en FASE2.md.


## Fase 3

```sh
node tests/phase3-frontend.js
php tests/phase3-migrations.php
php tests/integration.php --phase3
php tests/integration.php --phase3 --browser
```

`--phase3` ejecuta completas las Fases 1 y 2 antes de verificar identidad uno a uno, estructuras de personas, proyecciones por elección, jerarquía, cierre transitivo, bajas, transferencias, aprobaciones, clonación completa, independencia, módulos, alcances y promociones. Dos procesos separados prueban incorporaciones y vinculación de cuentas concurrentes; `structure-writer.php` exige el mismo patrón de base desechable. Nunca usa la base local real para estas escrituras.

`phase3-migrations.php` crea otra base desechable y prepara un estado post-Fase 2 con datos históricos. Comprueba conflictos de cuentas, falta de persona, ciclos, preservación de IDs/campos/referencias, reejecución y restricción de una ubicación activa por persona/elección. El ensayo elimina su base en `finally`.

`phase3-frontend.js` verifica texto seguro, selección de elecciones, controles según permisos, paginación, JSON, CSRF y bloqueo de peticiones a otro origen.

`--browser` utiliza el Chrome ya instalado en macOS y el WebSocket integrado de Node 22; no instala dependencias. Inicia un perfil temporal, usa exclusivamente las credenciales ficticias y el servidor desechable de esta suite, verifica las pantallas/modal/búsqueda/configuración y registra excepciones JavaScript. Cierra Chrome y deja capturas PNG en el directorio temporal para inspección. Es una prueba adicional local; no es requisito del código de producción. Ejecutar la suite sin esa opción donde Chrome no esté disponible.

## Fase 4

```sh
node tests/phase4-frontend.js
php tests/phase4-migrations.php
php tests/integration.php --phase4
php tests/integration.php --phase4 --browser
```

`--phase4` ejecuta completas las Fases 1–3. Añade auditoría sanitizada y filtros, aprobación simple/secuencial, snapshot, cancelación/caducidad, cargas multipart reales, JPEG con EXIF, WebP/miniatura, media con permiso/alcance/CSRF, notificaciones internas, consentimiento por canal y proveedores falsos. Nunca envía correo ni WhatsApp reales.

Dos procesos `queue-writer.php`, que rechaza bases fuera del patrón desechable, verifican concurrencia y caída tras el claim. También se prueban lease vivo, recuperación, backoff, agotamiento, idempotencia, cancelación y reintento administrativo. Las exportaciones generan PDF/XLSX/CSV/ZIP reales sobre más de mil personas, con protección de fórmulas y autorización al descargar.

Los backups se escriben en un directorio privado del ensayo. Se utilizan el cliente/dumper MySQL/MariaDB instalado (configurables con `MYSQL_CLIENT_BINARY`/`MYSQL_DUMP_BINARY`), credenciales temporales 0600 y otra base aleatoria para restauraciones. Se comprueban full, incrementales consecutivos, cambios/eliminaciones, daño interno/externo, dependencia faltante, retención/protección, espacio insuficiente, mutex y cifrado Sodium. La base y los directorios reales de SICRAT no se restauran ni se borran.

`phase4-migrations.php` prepara datos históricos y conflictos antes del DDL, conserva sus campos/IDs y comprueba reejecución. GD con WebP, EXIF, ZIP, cURL, Sodium y las bibliotecas Composer del proyecto cubren los casos reales. No se instalan paquetes del sistema.

Con `--browser` se conservan los casos de Fase 3 y se añaden auditoría, notificaciones, exportaciones, trabajos, respaldos, upload/miniatura y aislamiento visual del cliente. La captura adicional es `phase4-services.png`. Los resultados y procedimientos operativos están en `FASE4.md`.


## Revisión UX/UI de Fases 1–4

```sh
node tests/ux-frontend.js
php tests/integration.php --phase4 --browser --ux
```

`--ux` conserva la regresión completa anterior. Añade verificaciones HTTP de nombres, opciones paginadas de auditoría, aislamiento y origen de permisos. Recorre Dashboard, Organizaciones, Procesos, Secciones, Casillas, Personas, Usuarios, Estructura, Configuración, Servicios, Perfil y Autenticación en Chrome. Comprueba anchos de 1600/1280/820/390 px, selector en header, cambio real de elección, grid 3/2/1, tabs y navegación restringida, foco/teclado, modales y ausencia de errores JavaScript.

Prueba guardado de módulos con un fallo de conexión simulado y reintento, creación de rol, upload y revisión de CSV (sin confirmar esa importación). Todo se ejecuta exclusivamente sobre la base desechable. El diálogo de restauración se inspecciona con datos descriptivos ficticios, sin confirmar o ejecutar restauraciones desde Chrome; la suite Fase 4 conserva sus ensayos reales sobre destinos desechables.

Capturas `ux-*.png`, hojas de contacto y `ux-captures.json` quedan en el directorio temporal privado. No se incorporan imágenes de datos al repositorio, dependencias de navegador ni bibliotecas UI. Detalle y resultados en `UX_UI_FASES_1_4.md`.


## Fase 6 — Finanzas e inventario

```sh
node tests/phase6-frontend.js
php tests/phase6-migrations.php
php tests/integration.php --phase6 --browser
```

`--phase6` incluye toda la regresión de Fases 1–5 y UX. Añade presupuestos y revisiones, compromisos, pagos separados/parciales/reversiones, autorización por usuario/rol/permiso con snapshot y orden, anticipos, apoyos, reembolsos, ingresos, aportaciones, donaciones, transferencias, inventario, valoración, mínimos y módulos deshabilitados. Prueba los servicios comunes mediante archivos privados reales, MIME, PDF/XLSX/CSV, permisos revocados, scheduler/worker e idempotencia de avisos. No envía comunicaciones externas.

`resources-writer.php` exige el mismo patrón desechable de base y usa conexiones independientes para pagos, presupuestos, comprobaciones, stock, folios y autorización simultánea con edición. Una colisión preparada en la segunda mitad de transferencia comprueba rollback; otra conexión consume inventario después de abrir un snapshot para comprobar lectura actual al aprobar una merma.

Chrome recorre Finanzas e Inventario a 1440/820/390 px, comprueba header/selector, tabs, filtros, formularios, modales y desbordamiento. Registra un gasto, pago y anticipo, comprueba rechazo por saldo, selecciona roles por nombre, resuelve una autorización con la cuenta asignada y consulta historial con el módulo deshabilitado. Conserva `phase6-*.png` en el directorio privado de la ejecución. `--phase6-browser-only` permite aislar el navegador nuevo para diagnóstico; la validación final utiliza la suite completa.

`phase6-migrations.php` prepara estado post-Fase 5 y datos históricos, comprueba tablas coincidentes incompatibles, FKs/UUID únicos faltantes, integridad de contexto, preservación completa y reejecución. Los resultados, respaldo de la base local y límites del entorno objetivo figuran en `FASE6.md`.

## Fase 7 — Jornada Electoral y resultados

```sh
node tests/feedback-frontend.js
php tests/phase7-unit.php
php tests/phase7-migrations.php
php tests/integration.php --phase7 --browser
```

`--phase7` incluye completas las Fases 1–6 y UX. Verifica configuración, catálogos sin combinaciones automáticas, casillas físicas reutilizadas, asignaciones múltiples, ambas políticas iniciales, hora de captura, votos enteros estrictos, fotografías privadas, versiones inmutables, correcciones, decisiones obsoletas, cierre/reapertura, concentrados sin doble conteo, filtros, permisos y módulos. Genera los nueve informes en PDF/XLSX/CSV mediante servicios existentes. Los avisos externos usan exclusivamente un proveedor falso; no se envía correo ni WhatsApp real.

`jornada-writer.php` exige una base desechable con el patrón de la suite. Varios procesos con conexiones independientes y barrera READY/GO prueban primeras capturas, validaciones, propuestas rivales, captura/corrección, correcciones pendientes y validadas, cierre, retiro de asignación, borrado de fotografía e idempotencia simultánea. Comprueba el único puntero vigente y la conservación de cada versión.

`phase7-migrations.php` prepara estado post-Fase 6, tablas parciales, datos históricos y conflictos de columnas/FKs/unicidad/contextos/votos. Comprueba preservación y reejecución. `phase7-unit.php` no requiere BD: valida votos tipados y horarios IANA, incluidos intervalos inexistentes o ambiguos por cambios de horario.

Chrome conserva toda la regresión anterior y añade Jornada a 1600/820/390 px: configuración protegida y preview, asignaciones, captura real, comparación de versiones, imagen privada, gráficos, filtros, nueve reportes, confirmación de cierre/reapertura y feedback de cola. Verifica Super Administrador/administrador de elección, tarjetas disponibles, cuatro tipos de toast y prevención de doble envío en la regresión UX. Las capturas `phase7-*.png` permanecen en el directorio privado temporal. `--phase7-browser-only` aísla el navegador nuevo para diagnóstico; la validación final usa la suite completa. Ver `FASE7.md` para resultados y límites de QA/producción.
