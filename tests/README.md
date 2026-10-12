# Pruebas de Fases 1 y 2

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
