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


## 20261012_003_autorizacion.php

Preflight de cuentas por persona, UNIQUE nullable para `usuarios.persona`, ampliación de los tres identificadores de permiso a 128 caracteres conservando IDs/FKs, roles propios de una organización y permisos globales, plantillas electorales, permisos por elección, alcances/editor/fecha. Añade catálogo y configuración de módulos y secciones autorizadas. No cambia contraseñas, sesiones ni vínculos electorales existentes. Las tablas de permisos conservan sus UUID anteriores.

## 20261012_004_estructuras.php

Preflight de estructura antigua: exige cuentas vinculadas a personas de la misma organización, relaciones sin ciclos ni cruce electoral, orden válido y responsables activos. Un conflicto se informa sin borrar/fusionar/crear identidades. Evoluciona `estructura` conservando IDs y columnas originales, añade contenedores y personas, niveles por elección, proyecciones activas/históricas, historial y solicitudes específicas de transferencias. Reutiliza `estructura_relaciones` y conserva las referencias de `personas_responsables`.

La columna generada nullable `persona_activa` y su índice único permiten varias proyecciones históricas y exactamente una activa por persona/elección. La adaptación de datos antiguos es transaccional después del DDL. La aplicación actual debe desplegarse junto con estas migraciones; los cambios DDL no tienen reversión automática destructiva.

El módulo de estructura queda sin habilitar por defecto. El Super Administrador autoriza su disponibilidad para la organización y, cuando corresponda, la elección; el administrador selecciona su uso dentro de esos límites. Los componentes esenciales permanecen disponibles.

## 20261012_005_servicios_comunes.php

Reutiliza `auditoria` y `archivos`: añade referencia/información JSON e índices de auditoría, permite contexto global NULL; añade elección, integridad, estado, dimensiones y variantes privadas de archivos, y amplía tamaño a BIGINT. Conserva todos los IDs y campos anteriores. Un archivo antiguo queda `historico`: su ruta no se publica ni se transforma sin revisión explícita.

El preflight rechaza auditoría electoral con organización incompatible y archivos cuyo cargador ordinario pertenece a otro cliente antes del primer DDL. No concilia ni elimina datos.

Añade aprobaciones/pasos, trabajos, notificaciones/configuración/envíos, exportaciones, respaldos/política y restauraciones. Las tablas de transferencias de Fase 3 permanecen intactas. Se añaden permisos granulares, con plantilla electoral del administrador, sin conceder privilegios de backup a clientes. Valores iniciales: BD diaria, archivos full semanal, incremental diario, retención 14 días.

Se ensayó en bases desechables, con datos/conflictos históricos y mediante restauración del respaldo real local. La aplicación local preservó los registros originales de 76 tablas y la reejecución no dejó migraciones pendientes. El SQL original, las cuatro migraciones anteriores y los informes previos conservaron sus hashes. El procedimiento y respaldo están documentados en `FASE4.md`; no existe un `down` destructivo.
