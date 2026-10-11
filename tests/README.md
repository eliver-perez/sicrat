# Regresión de Fase 1

No requiere dependencias de desarrollo ni un framework de pruebas.

Desde la raíz del proyecto:

```sh
php tests/run.php
node tests/frontend.js
php tests/integration.php
```

`run.php` comprueba autorización, CSRF, contraseñas, entradas, paginación y respuestas seguras. La excepción técnica que aparece en stderr es intencional: verifica que el detalle se registra internamente y no llega al JSON.

`frontend.js` ejecuta los scripts reales con adaptadores pequeños de DOM/jQuery para comprobar presentación de datos, paginación y envío de CSRF. No sustituye una prueba visual en navegador.

`integration.php` necesita las credenciales locales de `.env`, permisos para crear una base de pruebas y PHP con PDO MySQL y cURL. Crea un nombre aleatorio `sicrat_phase1_test_<12 caracteres hexadecimales>`, importa el SQL original en esa base y prepara dos organizaciones y cuentas ficticias. El servidor PHP hijo recibe exclusivamente esa base y una URL local. El router de pruebas rechaza cualquier otro nombre de base.

Las operaciones de alta, contraseña, expiración, revocación y el fallo de tabla provocado pertenecen únicamente a esa base desechable. El bloque `finally` termina el servidor y elimina exclusivamente esa base. No modifica la base indicada originalmente en `DB_DATABASE`, los archivos SQL ni servicios existentes.

Logs, cookies y sesiones de prueba permanecen en un directorio temporal privado indicado al terminar. Si el proceso se mata sin ejecutar `finally`, revisar ese nombre de base y ese directorio antes de limpiar; no aplicar borrados por patrón amplio.

Resultados finales: 29 comprobaciones PHP, 25 JavaScript y 83 HTTP/BD. Entorno ejecutado: PHP 8.5.7, Node 22.15.0 y MariaDB 10.4.21. La validación en PHP 8.3.6 y MySQL 8.0.46 queda para un entorno disponible con esas versiones; no se intervino en producción.
