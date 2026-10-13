# FASE 6 — Finanzas e inventario

Revisión e implementación local: 12 de octubre de 2026. Base del trabajo: commit `49cdbed`, Fase 5. Este informe distingue **IMPLEMENTADO**, **CORREGIDO**, **NUEVO HALLAZGO**, **NO REPRODUCIDO** y **PENDIENTE PARA OTRA FASE**. No se inició Jornada Electoral, Actas ni Resultados.

## 1. Resumen

**IMPLEMENTADO.** Finanzas e Inventario utilizan la arquitectura Controlador → Servicio → Repositorio, autorización y alcances existentes. Se añadieron presupuestos, compromisos, gastos, pagos, políticas, anticipos, apoyos, reembolsos, ingresos, aportaciones, donaciones, cuentas y transferencias; también artículos, almacenes, existencias y movimientos. Se integraron los servicios comunes y referencias opcionales a Fase 5.

Antes de los cambios se leyeron `Diagnostico.md`, `FASE1.md`–`FASE5.md`, `UX_UI_FASES_1_4.md`, las seis migraciones, servicios comunes y módulos operativos. Git estaba limpio. La regresión inicial pasó 1,532 comprobaciones HTTP/BD y 814 en Chrome, además de pruebas básicas y migraciones. La base local tenía 103 tablas; no existían tablas ni movimientos financieros/inventario que debieran convertirse. Se conserva autenticación y sesiones existentes.

## 2. Migraciones

**IMPLEMENTADO.** [20261012_007_finanzas_inventario.php](database/migrations/20261012_007_finanzas_inventario.php) añade 28 tablas:

- Control: `recursos_control`, `recursos_folios`, `recursos_historial`, `recursos_avisos`.
- Finanzas: `finanzas_categorias`, `finanzas_metodos`, `finanzas_cuentas`, `finanzas_politicas`, `finanzas_presupuestos`, `finanzas_presupuestos_procesos`, `finanzas_presupuestos_revisiones`, `finanzas_gastos`, `finanzas_gastos_distribuciones`, `finanzas_anticipos`, `finanzas_reembolsos`, `finanzas_pagos`, `finanzas_devoluciones`, `finanzas_ingresos`, `finanzas_transferencias`, `finanzas_caja`, `finanzas_aportaciones`.
- Inventario: `inventario_categorias`, `inventario_origenes`, `inventario_articulos`, `inventario_almacenes`, `inventario_almacenes_usuarios`, `inventario_minimos`, `inventario_movimientos`.

Checksum final de 007: `e7c2bd057a4dda4855f443cba89d509e9bf0d10f92a808a8afa3c450a1593c0e`.

No modifica columnas anteriores, SQL originales ni migraciones 001–006. Comprueba conflictos antes del DDL: InnoDB, columnas, tipos/longitudes/precisión, claves foráneas, unicidad y relaciones/contextos preparados. Admite reanudación conservadora e idempotencia del ejecutor con checksum. No inventa movimientos ni saldos históricos.

Añade 23 permisos y plantilla electoral del administrador; marca ambos módulos como implementados. Conserva las autorizaciones y selecciones existentes: los clientes deben contar con autorización del Super Administrador y seleccionar el uso dentro de esos límites. El DDL tiene commits implícitos; no se ofrece un `down` destructivo.

**APLICADA LOCALMENTE.** Se respaldó `sicrat` con el cliente nativo, se restauró en una base desechable y se ensayó exclusivamente 007. Se conservaron los registros originales de las 103 tablas, comprobando contenido y cantidades; sólo se permiten nuevas filas de permisos/plantilla/migración y la bandera de implementación de los dos módulos. Tras pasar el ensayo, la base local pasó de 103 a 131 tablas y de seis a siete migraciones; reejecutar dejó cero pendientes.

Respaldo privado: `/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat-phase6-backup-20261013-013922-a6dbd7/sicrat-before-phase6.sql`. SHA-256: `7382bf37428493a164ce82a287d9fb57677c5289ce2b0455d321726090e2d881`. Se eliminaron la copia desechable y el archivo temporal de credenciales; el respaldo permanece privado. Conviene copiarlo a almacenamiento durable autorizado antes de una futura intervención, porque su directorio es temporal.

## 3. Presupuestos

**IMPLEMENTADO.** Presupuesto general de organización o por elección, categoría, periodo y evento; puede abarcar varias elecciones de la misma organización. Las revisiones permiten ampliar, reducir o transferir disponible entre presupuestos autorizados. El periodo, categoría, evento y elecciones del gasto se validan al comprometer.

[BudgetService.php](app/Services/Resources/BudgetService.php) conserva una revisión inicial y cada modificación con UUID, versión, usuario, fecha, motivo e importes anterior/nuevo. Una transferencia comparte referencia entre ambas revisiones y conserva el total. No se permite reducir por debajo de compromisos vigentes.

## 4. Estados presupuestales

**IMPLEMENTADO.** Presupuestado = importe vigente; Comprometido = gastos comprometidos no cancelados; Pagado = suma neta de pagos y reversiones; Disponible = Presupuestado − Comprometido. Un pago no vuelve a descontarse del disponible.

La prueba inicial de $100,000 presupuestados, $35,000 comprometidos y $20,000 pagados mantiene $65,000 disponibles. Los gastos sin presupuesto quedan visibles en compromisos/gastos, pero no reducen partidas a las que no pertenecen.

## 5. Gastos

**IMPLEMENTADO.** Documento principal con folio, organización, elección/distribución, categoría, concepto, descripción, proveedor o persona beneficiaria, monto, fecha, creador, estado, comprobantes, referencia opcional y snapshot de autorización. Categorías configurables conservan historial al desactivarse.

Estados: registrado, comprometido, pendiente de autorización, aprobado, parcialmente pagado, pagado, requiere corrección y cancelado. Registrar no implica pagar. [ExpenseService.php](app/Services/Resources/ExpenseService.php) valida cambios de versión, contexto y presupuesto.

Toda edición efectiva de un gasto sin pagos retira y regenera la autorización vigente cuando corresponde; esta regla también cubre cambios menores y es más estricta que exigirlo sólo para cambios materiales. Un gasto con historia de pagos conserva sus importes incluso tras revertirlos. Cancelar exige saldo pagado neto cero y ausencia de recepciones registradas de inventario. No elimina documentos ni comprobantes.

## 6. Pagos

**IMPLEMENTADO.** `finanzas_pagos` es una entidad separada. Admite pagos parciales y múltiples, cuenta, método, fecha, referencia, folio e idempotencia. El saldo se calcula desde movimientos; se bloquean pagos excesivos, sin autorización vigente o sin fondos realmente recibidos en la cuenta.

Una reversión agrega otro pago con signo negativo, vínculo único al original y movimiento compensatorio de caja. La cancelación posterior conserva ambos. La comprobación de un anticipo utiliza un pago interno asociado al anticipo, sin otra salida de caja.

## 7. Políticas de autorización

**IMPLEMENTADO.** [PolicyService.php](app/Services/Resources/PolicyService.php) permite política de organización y política específica por elección. Modos: sin autorización, todos requieren autorización y autorización por monto. Si no se ha configurado una política, se aplica explícitamente el modo sin aprobación, conservado en el snapshot.

Selecciona usuarios reales por cuenta, rol o permiso, con autorización de negocio y permiso común para resolver. Excluye al solicitante. Los roles reutilizan su código existente y se presentan por nombre; no se agregó UUID a `usuarios_tipos`. No hay excepción implícita de autoaprobación ni sustitución financiera de aprobador por Super Administrador.

## 8. Aprobaciones por monto

**IMPLEMENTADO.** Hasta 50 rangos consecutivos desde cero, sin solapamientos ni huecos; el último queda abierto. Cada rango admite de uno a veinte pasos ordenados. `ApprovalService` ejecuta los pasos consecutivos y conserva la política, versión, rango, importe, configuración, revisión y aprobadores seleccionados.

Un cambio de política no modifica solicitudes anteriores. Un cambio de gasto invalida el snapshot anterior; una autorización antigua nunca aprueba un monto nuevo. Se probaron usuario, rol, permiso, límites, varios pasos, orden, rechazo, autoaprobación y carrera entre edición/resolución.

## 9. Anticipos

**IMPLEMENTADO.** [AdvanceService.php](app/Services/Resources/AdvanceService.php) registra beneficiario y responsable como personas, sin exigir cuenta de usuario, propósito, monto, fecha límite, elección, autorización y referencia operativa. La entrega autorizada afecta caja una sola vez, pero no crea automáticamente gasto definitivo.

Saldo por comprobar = entregado − gastos válidos comprobados − devoluciones. Los estados derivados distinguen pendiente, parcial, comprobado y vencido; la autorización histórica permanece separada. Un anticipo entregado sólo se cancela tras devolverlo íntegramente sin comprobaciones.

## 10. Comprobaciones

**IMPLEMENTADO.** Relaciona varios gastos autorizados y pagos internos con el anticipo. Valida misma organización, elección y beneficiario, saldo disponible y tipo compatible; un reembolso no comprueba un anticipo. Permite devoluciones de saldo con idempotencia y caja compensatoria.

Los comprobantes pertenecen al gasto, anticipo o pago/comprobación mediante `FileService`. Se probaron comprobación parcial, múltiples gastos, devolución, cierre, vencimiento y concurrencia. Comprobar no duplica salida de caja.

## 11. Apoyos fijos

**IMPLEMENTADO.** Tipos de gasto `apoyo` y `viatico`, sujetos a la política vigente. No generan anticipo ni deuda de comprobación individual. Mantienen beneficiario, referencia operativa, autorización y pagos separados.

## 12. Reembolsos

**IMPLEMENTADO.** [ReimbursementService.php](app/Services/Resources/ReimbursementService.php) conserva solicitado, autorizado, motivo, solicitante, beneficiario, categoría y elección. Un revisor distinto puede autorizar todo, una parte o rechazar con importe cero.

La revisión positiva genera un único gasto asociado, manteniendo al solicitante original. El gasto aplica la política correspondiente y admite pagos parciales. La solicitud no se convierte en anticipo; se conserva diferencia solicitado/autorizado. La resolución y los pagos son auditados.

## 13. Ingresos

**IMPLEMENTADO.** [TreasuryService.php](app/Services/Resources/TreasuryService.php) diferencia prometido y recibido. Sólo recibir genera caja. Registra fuente, categoría, fecha, importe, cuenta, método, notas, usuario y evidencia privada. Los métodos son configurables: efectivo, transferencia, tarjeta u otro.

Recibir es idempotente. Cancelar un ingreso recibido conserva el original y genera movimiento compensatorio; se rechaza si la cuenta ya no dispone del importe. No se hace conciliación bancaria ni contabilidad fiscal.

## 14. Aportaciones

**IMPLEMENTADO.** [ContributionService.php](app/Services/Resources/ContributionService.php) registra donante como persona o referencia externa, concepto, tipo, organización/elección, estado y evidencia. La aportación monetaria enlaza un solo ingreso, sin contabilizar otra entrada.

**CORREGIDO.** La recepción/cancelación del ingreso actualiza el estado de su aportación asociada dentro de la misma transacción y deja historial. Ficha y exportación muestran el importe monetario. Las consultas y archivos exigen permisos y alcance; los exportadores omiten datos innecesarios del donante.

## 15. Donaciones

**IMPLEMENTADO.** Donación monetaria con ingreso prometido/recibido. En especie: registro pendiente de validación administrativa, con artículo, cantidad, almacén y valor unitario opcional.

Modalidad directa: validar crea recepción física. Modalidad posterior: validar conserva stock; confirmar recepción crea exactamente un movimiento. Ambas operaciones respetan los módulos y permisos necesarios. Una donación recibida físicamente conserva su aportación; una corrección requiere ajuste de inventario.

## 16. Transferencias internas

**IMPLEMENTADO.** Transferencias entre cuentas/fondos distintos de la misma organización, con importe, motivo, folio e idempotencia. Se registran dos movimientos de caja opuestos dentro de la misma transacción. Se comprueba saldo en origen.

No crean gasto ni ingreso y se excluyen del flujo externo. El saldo total de la organización permanece igual. No se permite usar cuentas ajenas manipulando referencias.

## 17. Gastos compartidos

**IMPLEMENTADO.** Un gasto principal y sus distribuciones por elección, mediante monto exacto o porcentaje. Importes deben sumar el total y porcentajes el 100%. Todas las elecciones deben pertenecer al mismo cliente y estar autorizadas para la operación.

[Allocation.php](app/Services/Resources/Allocation.php) distribuye centavos por cociente y mayores restos con desempate estable. El total final coincide exactamente con el principal. El acceso a un documento compartido exige autorización sobre todas sus elecciones; no duplica obligación ni pago.

## 18. Flujo de efectivo

**IMPLEMENTADO.** Ingresos recibidos − pagos reales − anticipos entregados + devoluciones, con movimientos compensatorios. Excluye promesas, compromisos, comprobaciones internas y transferencias entre fondos.

El filtro electoral usa la elección principal del movimiento de caja. Un gasto compartido conserva un pago principal y su distribución informativa; no divide automáticamente caja en libros separados por elección. Los reportes son administrativos internos, sin carácter fiscal oficial.

## 19. Inventario

**IMPLEMENTADO.** [InventoryService.php](app/Services/Resources/InventoryService.php) administra inventario común de la organización mediante movimientos. Diferentes elecciones utilizan el mismo artículo/almacén; no se duplica el inventario completo por elección.

Entradas, compras, donaciones, entregas, consumos, préstamos, transferencias, devoluciones, mermas, ajustes y reversiones conservan folio, actor, contexto, fecha y motivo. No permite existencia negativa.

## 20. Artículos

**IMPLEMENTADO.** Código administrativo legible y único por organización, nombre, descripción, categoría configurable, unidad, estado, mínimo y costo de referencia opcional. No requiere precio cuando no aplica.

Una unidad usada en movimientos no puede reinterpretarse mediante edición. La baja es lógica; conserva referencias. Categorías y orígenes configurables usados mantienen su tipo/clase histórica.

## 21. Almacenes

**IMPLEMENTADO.** Múltiples almacenes, nombre, ubicación, descripción, estado, responsables autorizados y configuración de validación de pérdidas/daños. Los responsables son cuentas activas del cliente.

Permisos electorales o restringidos de inventario requieren responsabilidad sobre el almacén; el alcance de organización permite administración general. Se validan los dos almacenes de una transferencia y se rechaza operar referencias ajenas.

## 22. Existencias

**IMPLEMENTADO.** Suma de cantidades de movimientos aplicados por artículo/almacén. La valoración administrativa utiliza costo promedio ponderado, calculado desde cantidades y valores del historial. Pendientes/rechazados no afectan stock.

Dinero `DECIMAL(14,2)` y cantidades `DECIMAL(14,3)`; PHP usa enteros escalados y textos decimales, rechazando floats, notación científica y formatos ambiguos. [Amount.php](app/Services/Resources/Amount.php) evita desbordar el producto en proporciones. Cada importe/valor individual se limita a la precisión de su columna; agregados que excedan esos límites requieren una evolución específica.

## 23. Entradas

**IMPLEMENTADO.** Compra, donación, entrada administrativa, ajuste positivo y otro origen configurable. Registra cantidad, valor unitario opcional, fecha, motivo, almacén y referencia.

Una compra puede relacionarse con gasto inventariable y se limita el valor de recepción al importe correspondiente. Recibir no paga ni compromete automáticamente el gasto. El inventario aumenta al registrar recepción aplicada, independientemente del estado del pago.

## 24. Salidas

**IMPLEMENTADO.** Entrega, consumo, préstamo, pérdida, daño, ajuste negativo y origen configurable. Conserva destinatario/responsable como persona, elección, fecha, motivo y referencias operativas opcionales.

La salida valora material al promedio disponible y bloquea cantidades insuficientes. Entregar o consumir material ya adquirido no genera otro gasto ni pago.

## 25. Transferencias

**IMPLEMENTADO.** Transferencia entre almacenes con una referencia de operación: salida origen y entrada destino, igual cantidad/valor y transacción única. No cambia existencias ni valoración total del cliente.

La prueba de colisión en la segunda mitad confirma rollback de la salida previamente insertada. Reintentar la misma referencia no duplica movimientos. Revertir una transferencia comprueba ambos almacenes y conserva los originales.

## 26. Devoluciones

**IMPLEMENTADO.** Devolución ligada a entrega, consumo o préstamo original. Vuelve al almacén original con cantidad/valor asociados y conserva evento o referencia operativa. Se limita a la cantidad pendiente de devolver.

No se puede devolver una salida revertida, duplicar cantidades ni revertir silenciosamente movimientos con devoluciones. La devolución de dinero de anticipo utiliza entidad financiera separada.

## 27. Pérdidas/daños

**IMPLEMENTADO.** Cantidad, causa/motivo, fecha, responsable que reporta, evidencia y autorización cuando el almacén lo exige. `ApprovalService` resuelve una cuenta asignada distinta del solicitante.

Una merma pendiente no reduce stock. Aprobar revalida la existencia actual bajo bloqueo, recalcula valoración y aplica movimiento atómicamente; si ya no hay cantidad, conserva la solicitud pendiente. Rechazo/cancelación mantiene historia sin stock aplicado.

## 28. Stock mínimo

**IMPLEMENTADO.** Mínimo general por artículo y reemplazo por almacén. Listados/reportes filtran bajo mínimo. Scheduler/worker comunes generan avisos internos con destinatarios autorizados, sin correo ni WhatsApp automáticos.

La clave de aviso incluye artículo, almacén, existencia y umbral; repetir el mismo estado no duplica notificaciones. **CORREGIDO:** el enlace se construye desde el tipo de aviso antes de usar su firma de idempotencia, y abre Existencias.

## 29. Integración Finanzas-Inventario

**IMPLEMENTADO.** Compra financiera y recepción física conservan entidades separadas. Compromiso, autorización, pago y recepción son pasos independientes; distribuir material no crea otra salida financiera.

La donación en especie genera una recepción única desde la aportación validada. Historial mantiene referencias. Cancelar una compra con recepción registrada exige una corrección autorizada que preserve la operación.

## 30. Integraciones Fase 5

**IMPLEMENTADO.** Gastos pueden relacionarse con eventos, tareas, peticiones, incidencias y transporte. Anticipos/reembolsos y apoyos fijos pueden referenciar transporte. Movimientos de inventario aceptan referencias operativas opcionales, con validación del módulo y alcance de origen.

Eventos muestran referencias financieras y cantidades entregadas, consumidas, prestadas, devueltas y perdidas/dañadas. Transporte muestra anticipos, gastos/apoyos y reembolsos autorizados para consulta. Las referencias se conservan al editar salvo retiro explícito.

Los módulos operativos funcionan con Finanzas/Inventario apagados. Los recursos históricos permanecen consultables según permisos, sin habilitar nuevas escrituras. La navegación histórica comprueba datos autorizados de varios tipos, incluso cuando no existe ningún gasto o movimiento.

## 31. Auditoría

**IMPLEMENTADO.** Reutiliza `AuditService`, además del historial específico de recursos. Se auditan altas/configuración, revisiones, compromisos, edición, autorización, pagos, reversiones, comprobaciones, devoluciones, ingresos, aportaciones y movimientos.

No se auditan consultas triviales. Las operaciones fallidas revierten sus cambios. Se conserva quién, cuándo, motivo y valores anteriores/nuevos relevantes. Los folios no contienen datos personales y se generan con bloqueo concurrente por organización/tipo/año.

## 32. Archivos

**IMPLEMENTADO.** `ResourceRegistry` registra propietarios financieros e inventario; el controlador común resuelve UUID únicamente de entidades del catálogo cerrado. `FileService` valida, procesa y guarda imágenes/PDF administrativos privados; `/media/{uuid}` revalida permiso y alcance.

Gastos, pagos/comprobaciones, ingresos, anticipos, devoluciones, reembolsos, aportaciones y movimientos pueden poseer evidencia. No admite videos ni ejecutables. El endpoint de eliminación genérica rechaza borrar comprobantes financieros/inventario; un documento cancelado conserva evidencia. Módulo apagado permite lectura histórica autorizada y bloquea nuevas cargas.

## 33. Notificaciones

**IMPLEMENTADO.** Aprobación requerida/resuelta, reembolso revisado, anticipo pendiente, merma revisada, compra recibida, donación pendiente y stock bajo utilizan `NotificationService` interno y claves idempotentes.

Anticipos: avisos tres días antes del vencimiento, en vencimiento y siete días después mientras haya saldo. Recepción posterior: al validar, tres y siete días después si sigue pendiente. El worker revalida destinatario/alcance y módulos. No se notifica cada consulta ni movimiento trivial.

El job `recursos_alertas` utiliza la cola, worker y scheduler existentes; requiere actor Super Administrador activo para la revisión global. No se instaló ni modificó cron o servicios del equipo/VPS.

## 34. Exportaciones/reportes

**IMPLEMENTADO.** Doce exportadores en `ExportRegistry`: presupuestos, gastos, pagos, ingresos, anticipos, reembolsos, aportaciones, artículos, almacenes, movimientos, existencias y flujo de caja. Utilizan PDF/XLSX/CSV y generación privada en cola.

Reportes financieros por elección/categoría/fecha/estado: presupuesto, gastos, obligaciones aprobadas o parcialmente pagadas, anticipos por comprobar/vencidos, reembolsos, ingresos/aportaciones y flujo. Inventario: existencias, bajo mínimo y movimientos filtrados por tipo/artículo/almacén/fecha para entradas, salidas, consumo, merma, devolución y transferencias. La elección se obtiene del contexto autorizado del encabezado; el inventario permanece común de organización.

Permisos y alcances se aplican antes de paginar, se revalidan al generar y descargar y omiten información sensible innecesaria. Los nuevos exportadores no se añaden automáticamente al paquete ZIP anterior de clientes.

## 35. UI/UX

**IMPLEMENTADO.** Reutiliza Tailwick/Tailwind, componentes y helpers de Fase 5: cards, grids, tabs, tablas con scroll interno, badges, diálogos nativos, búsqueda/paginación y selectores descriptivos. No se añadió Bootstrap ni otra biblioteca UI.

Finanzas tiene navegación principal compacta y configuración/fondos agrupados; Inventario distingue resumen, artículos, existencias, movimientos y almacenes. Filtros de búsqueda se agrupan en un desplegable para mantener la tabla visible en móvil. El encabezado y selector electoral son los existentes.

Importes de texto con entrada decimal y visualización exacta MXN; cantidades con tres decimales; personas, cuentas, categorías y roles se seleccionan por nombre, sin solicitar IDs. Políticas tienen editor de rangos y pasos, sin JSON crudo. **CORREGIDO:** anchura de formularios, botones secundarios, avisos dentro del modal activo y selectores de almacén según el permiso real del movimiento.

Chrome validó escritorio 1440 px, tablet 820 px y móvil 390 px; se inspeccionaron capturas de políticas, tablas, navegación y formularios móviles. Sin desbordamiento horizontal de página ni excepciones JavaScript en la ejecución final.

## 36. Archivos modificados

Nuevos componentes principales:

- `app/Controllers/ResourcesController.php`, `app/Repositories/ResourcesRepository.php`.
- `app/Services/Resources/`: `Amount`, `Allocation`, `Catalog`, `Context`, `BaseService`, `CatalogService`, `BudgetService`, `ExpenseService`, `PolicyService`, `AdvanceService`, `ReimbursementService`, `TreasuryService`, `ContributionService`, `InventoryService`, `Resources`, `Alerts`, `Exports`, `Overview`, `Options`, `Related`, `Factory`.
- `app/Views/resources/index.php`, `public/resources/index.php` y cuatro scripts en `public/assets/js/resources/`.
- Migración 007, `FASE6.md` y ocho archivos de pruebas Fase 6/escritor concurrente.

Integraciones pequeñas en autorización/rutas, controlador común de archivos, registries/exportaciones, aprobación común, scheduler/worker, servicios de eventos/transporte, sidebar, CSS y ficha operativa. Los runners, router y prueba de migraciones Fase 5 reconocen el nuevo archivo. Se ampliaron los README de pruebas y migraciones. No hubo refactor masivo de fases anteriores.

## 37. Dependencias

**IMPLEMENTADO CON DEPENDENCIAS EXISTENTES.** TCPDF, OpenSpout, PHPMailer y QR siguen en Composer sin cambios; Fase 6 usa los motores comunes y no instala bibliotecas. PDO MySQL, GD/fileinfo y las extensiones requeridas por las fases anteriores siguen siendo necesarias.

Entorno ejecutado: PHP 8.5.7 y MariaDB 10.4.21 locales. Objetivo informado: Ubuntu 24.04, Nginx 1.24.0, PHP 8.3.6, MySQL 8.0.46, utf8mb4. El código nuevo evita características posteriores a PHP 8.3 y SQL exclusivo de MariaDB.

Las lecturas con bloqueo y transacciones se diseñan conforme a InnoDB; la documentación oficial describe los bloqueos de `SELECT ... FOR UPDATE`. [Manual MySQL 8.0](https://dev.mysql.com/doc/refman/8.0/en/innodb-locks-set.html). La compatibilidad del diseño no sustituye una ejecución real sobre PHP 8.3.6/MySQL 8.0.46: no se accedió a ese runtime ni al VPS y no se propone reemplazarlo.

## 38. Pruebas

Toda la regresión anterior y cobertura de Fase 6 se ejecutan con:

```sh
php tests/run.php
node tests/frontend.js
node tests/phase2-frontend.js
node tests/phase3-frontend.js
node tests/phase4-frontend.js
node tests/ux-frontend.js
node tests/phase6-frontend.js
php tests/phase3-migrations.php
php tests/phase4-migrations.php
php tests/phase5-migrations.php
php tests/phase6-migrations.php
php tests/integration.php --phase6 --browser
```

Las suites generan bases de nombre aleatorio y directorios privados; no usan la base local real para fixtures. Concurrencia utiliza procesos/conexiones independientes para pagos, idempotencia, compromisos, anticipos, stock, folios y autorización/edición. Se prueban rollback de transferencia y lectura de stock después de un snapshot anterior.

Servicios comunes: uploads/media reales, MIME, permisos/alcances, módulos apagados, CSV/PDF/XLSX, revocación al descargar, auditoría, worker, avisos y scheduler. Chrome crea gasto/anticipo/pago, rechaza pago excesivo, consulta snapshot, selecciona rol, resuelve aprobación con su cuenta y revisa modo histórico. No envía comunicaciones externas.

## 39. Resultados

**VERIFICADO.** Ejecución final completa: **2,091 comprobaciones HTTP/BD**, incluidas **559 adicionales de Fase 6**, y **1,110 en Chrome**, de las cuales **296** corresponden a Fase 6; cero excepciones JavaScript.

| Verificación | Resultado |
| --- | --- |
| Pruebas básicas PHP / JavaScript | 29 / 25 correctas |
| Frontend Fases 2 / 3 / 4 / UX / 6 | 16 / 16 / 13 / 17 / 24 correctas |
| Migraciones Fases 3 / 4 / 5 / 6 | 14 / 16 / 184 / 264 correctas |
| Sintaxis PHP / JavaScript | 231 / 43 archivos, cero errores |
| Concurrencia, permisos, servicios y módulos | Incluidos en la suite completa, correctos |
| Respaldo nativo, restauración y aplicación local | Registros originales conservados; 007 aplicada; cero pendientes |
| `git diff --check` y whitespace añadido en nuevos archivos | Sin errores nuevos |

Artefactos privados de la ejecución final: `/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat_phase1_test_3818531f13e5`, con capturas `phase6-*.png` y pruebas previas. Las bases de pruebas se eliminaron en sus bloques `finally`. La base local real conserva una organización, una elección, dos cuentas y dos personas anteriores. Todas las nuevas tablas financieras/inventario están vacías: no contiene fixtures ni movimientos financieros creados por las suites. `database/preflight.php` no encontró conflictos de contexto, identidad o archivos.

La excepción técnica impresa por `tests/run.php` es deliberada y comprueba sanitización de errores; esa suite termina correctamente.

Los SQL originales conservaron SHA-256:

| Archivo | SHA-256 |
| --- | --- |
| `database/database.sql` | `48339f405bccc7a8bd14cee5f16e283aa4a6f5a95c896c6d84fdc925f45e1dcc` |
| `database/colonias mysql.sql` | `b109dee64af11335304554c5babe26abd7a3c9a3f9f6da6935d31aced471e28a` |

También se compararon los hashes de 18 archivos protegidos: informes anteriores, seis migraciones, SQL, `.env` y Composer. Permanecen intactos.

## 40. Nuevos hallazgos

**NUEVO HALLAZGO / CORREGIDO.** Una aprobación común puede abrir un snapshot antes de esperar el bloqueo organizacional. La valoración de stock ahora realiza lectura actual con bloqueo cuando está en transacción; la prueba consume material desde otra conexión y rechaza la merma que excede la existencia actual.

**NUEVO HALLAZGO / CORREGIDO.** `usuarios_tipos` no tiene UUID; la resolución de roles usa su código ya existente y nombres en UI. No se altera la identidad ni estructura de roles.

**CORREGIDO durante validación.** Colisión en segunda mitad de transferencia produce conflicto controlado y rollback completo; edición conserva vínculo operativo salvo retiro explícito; módulo deshabilitado bloquea también cancelación/resolución que cambiaría recursos; estado de aportación sigue al ingreso y los avisos conservan su enlace específico.

**NO REPRODUCIDO en los escenarios probados.** Doble pago, exceso por carrera en presupuesto/anticipo, folios duplicados, inventario negativo, autorización antigua del monto nuevo, pérdida de históricos, acceso cruzado por UUID y excepciones JavaScript. Esta clasificación se limita a la cobertura ejecutada.

## 41. Pendientes para Fase 7

**PENDIENTE PARA OTRA FASE, NO INICIADO.** Jornada Electoral, candidatos, partidos, coaliciones, captura/validación de actas, conteos, versiones/resultados preliminares y concentrados electorales. No se crearon tablas anticipadas, capturistas ni gráficos de resultados.

Las fases anteriores y los servicios comunes quedan disponibles para una revisión expresa antes de iniciar ese trabajo.

## 42. Riesgos

- La verificación ejecutada corresponde al entorno local. Debe ensayarse el despliegue con respaldo y runtime objetivo PHP 8.3.6/MySQL 8.0.46 antes de producción; no se certifica una ejecución que no ocurrió.
- El bloqueo común por organización garantiza conservación de saldos entre operaciones relacionadas, pero serializa escrituras de un cliente. Separar bloqueos para mayor volumen requiere nuevos ensayos de orden/concurrencia.
- El DDL no tiene rollback transaccional completo. Publicar código y migración compatibles, con respaldo restaurable y ventana controlada, sigue siendo necesario. No se desplegó en VPS.
- Los reportes son administrativos MXN; no incluyen contabilidad fiscal, conciliación automática, multidivisa, depreciación ni costeo avanzado.
- Una operación compartida mantiene principal/distribución; el flujo electoral sigue la elección principal del movimiento. No existen libros de caja separados por asignación porcentual.
- La recepción de donación en especie requiere permisos de aportaciones y entradas, además de acceso al almacén. El administrador debe configurar responsabilidades y autorizaciones reales; marcar un módulo implementado no habilita operación de clientes.
- Scheduler y worker deben ejecutarse mediante los comandos existentes para procesar trabajos y avisos. Esta fase no interviene en la configuración de servicios/cron.
- SQL o escrituras directas externas al servicio no participan en su mutex ni validación de alcances; deben evitarse en operación. Claves foráneas y checks básicos complementan el servicio, sin sustituirlo.

## 43. git diff

Base: `49cdbed`. **20 archivos existentes modificados** mediante **106 inserciones y siete eliminaciones**, y **39 archivos nuevos** de Fase 6/documentación/pruebas. `git diff --stat` sólo incluye archivos ya rastreados; los nuevos se revisaron además mediante `git ls-files --others --exclude-standard`, inspección de contenido y sintaxis. No hay archivos staged ni commits nuevos.

No se hicieron commits ni staging. No se modificaron SQL originales, `.env`, Composer, informes anteriores o migraciones 001–006; tampoco Adariel, VPS o servicios. Cambios de archivos existentes son integraciones puntuales; los nuevos archivos pertenecen a Fase 6, pruebas y documentación.

Se revisaron diff, sintaxis y whitespace, incluyendo archivos nuevos. La expectativa de migración en `tests/phase5-migrations.php` reconoce 006 + 007; sus aserciones de conservación/conflictos permanecen completas.

**Punto de detención:** Fase 6 terminada localmente, pendiente de revisión del usuario. No iniciar Fase 7 sin instrucciones expresas.
