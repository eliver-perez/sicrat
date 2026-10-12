# Migraciones de SICRAT

El SQL original es el punto de partida y permanece intacto. No ejecutar migraciones desde páginas o peticiones API.

Antes de aplicar en una base con datos: respaldar y comprobar restauración, revisar el nombre de base configurado y ejecutar el diagnóstico de lectura `php database/preflight.php`. Después ejecutar explícitamente:

```sh
php database/migrate.php
```

El ejecutor registra nombre y SHA-256 en `esquema_migraciones`, aplica los archivos PHP por orden y usa un bloqueo para evitar dos ejecutores simultáneos. Repetirlo no reaplica migraciones completas. Una migración ya registrada no puede modificarse; un cambio posterior requiere otro archivo.

MySQL/MariaDB hacen commit implícito en DDL. Las migraciones de esta fase consultan columnas e índices antes de añadirlos y admiten reanudar pasos parciales. No se promete atomicidad de toda la migración. Ejecutar con la aplicación detenida durante el cambio de esquema y desplegar el código junto con su esquema compatible.

## 20261011_001_identidad_personas.php

- Añade código de diez caracteres a personas, comprueba duplicados antes de crear UNIQUE, asigna códigos solo a registros que no los tienen y después impone NOT NULL. El backfill recorre grupos de cien y reintenta colisiones.
- Conserva IDs, UUID, nombres, organización, sección y demás campos existentes.
- Permite sección NULL.
- Añade dos banderas independientes de unicidad en organizaciones, activadas por defecto.
- Añade índices NO únicos por organización e identificador/contacto.
- Añade `usuarios.persona` nullable con índice y FK; las cuentas existentes quedan sin vincular.
- CURP y clave históricamente repetidas se conservan. La regla activa impide nuevas altas/cambios repetidos, sin exigir eliminar fichas anteriores. Todas las escrituras de identidad y política de la aplicación bloquean la fila de organización dentro de su transacción.

No se incluye un `down` automático que borre códigos permanentes o vínculos ya usados. Volver a imponer sección obligatoria tampoco es seguro después de altas rápidas. La reversión de datos requiere restaurar un respaldo validado o diseñar una migración compensatoria según los datos reales. No volver a una versión anterior que inserta personas sin código: su alta sería incompatible con NOT NULL.

## 20261011_002_importaciones_personas.php

Añade área de revisión específica de importaciones de personas y sus filas, con FKs e índices. No representa infraestructura general de archivos, trabajos ni auditoría. Los archivos originales no se conservan; las filas/resultados caducan a las 24 horas. Al cargar otro archivo se eliminan una importación ya caducada por carga, con sus filas mediante FK. La caducidad impide acceso aunque la limpieza física no haya ocurrido. Una limpieza programada general corresponde a una fase posterior.

Eliminar estas tablas para revertir pierde las revisiones y resultados temporales. Solo sería razonable hacerlo después de comprobar que no hay importaciones que deban conservarse.

Las pruebas importan el esquema original en una base desechable, añaden datos previos, ejecutan ambas migraciones, verifican conservación y repiten el ejecutor. La base local de trabajo se migró después de restaurar su respaldo en otra base desechable, ensayar las migraciones y comparar todos los campos originales de personas, usuarios y organizaciones. No se intervino en producción.
