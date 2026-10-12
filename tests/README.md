# Pruebas de Fases 1, 2, 3 y 4

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
