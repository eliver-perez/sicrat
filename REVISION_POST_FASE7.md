# Revisión integral posterior a Fase 7

Fecha: 13 de octubre de 2026. Base del repositorio: `7bb609d` («Fase 7»).

Esta intervención corresponde a correcciones y revisión de desarrollo. **No constituye Fase 8 ni QA de producción.** Se conservaron los informes anteriores, las migraciones 001–008, los SQL originales y las dependencias instaladas.

Las etiquetas distinguen **CORREGIDO**, **MEJORADO**, **NUEVO HALLAZGO**, **PENDIENTE** y **NO REPRODUCIDO**. Las pruebas usan bases, sesiones y almacenamiento desechables; se aplicaron 009 y 010 exclusivamente a la base local, cada una después de restaurar su respaldo y ensayar la conservación.

## 1. Resumen

**CORREGIDO / MEJORADO.** Se retomó el proyecto existente sin reiniciarlo. Se revisaron Diagnostico, Fases 1–7, la revisión UX/UI anterior, migraciones, estado y diferencias de Git. La línea base aprobó 2,528 comprobaciones HTTP/BD y 1,197 comprobaciones de Chrome antes de modificar la aplicación.

Las correcciones principales afectan UUID, gestión electoral de secciones/casillas, consultas automáticas de Personas/Configuración, permisos obsoletos, categorías de casos, despacho de archivos protegidos y captura continua de asistentes. Se reorganizaron navegación, formularios, diálogos, Mensajería y header, conservando los servicios y reglas existentes.

## 2. Errores funcionales encontrados

| Estado | Problema y evidencia | Corrección |
|---|---|---|
| CORREGIDO | `resources/core.js` generaba el UUID antes de instalar el submit AJAX. La ausencia de `crypto.randomUUID` interrumpía el flujo y dejaba un envío normal del formulario. | UUID común y prevención temprana del envío. Guardados reales sin API nativa. |
| CORREGIDO | `Authorization.php` restringía el catálogo de secciones a Super Administrador; Personas consultaba ese catálogo al iniciar. | Permisos granulares y alcance por elección, también para los selectores. |
| CORREGIDO | Casillas mostraba una pantalla de funcionalidad pendiente, sin administración real. | Endpoints, servicio y pantalla de registro, consulta y edición. |
| CORREGIDO | Configuración consultaba niveles/reglas aunque Estructura estuviera deshabilitada. | Consultas según capacidad y mensaje inline cuando la sección no está disponible. |
| CORREGIDO | La lista de permisos conservaba datos anteriores al cambiar selección. | Limpieza inmediata y descarte de respuestas antiguas. |
| CORREGIDO | Asistencia cerraba el diálogo y reconstruía el evento tras cada alta. | Captura continua, contador y lista reciente. |
| CORREGIDO | Categorías de peticiones/incidencias eran texto libre. | Catálogo con referencias, contexto electoral y estados. |
| CORREGIDO | El front controller de `public/index.php` no despachaba `/media/` cuando el servidor utilizaba ese fallback. | Despacho al router protegido; pruebas de media pasan por el front controller real. |

## 3. Error randomUUID y solución

**CORREGIDO.** `public/assets/js/security.js` expone `SicratSecurity.uuid()`. Usa la API nativa cuando existe; en su ausencia utiliza `crypto.getRandomValues`, fija versión/variante UUID v4 y formatea los 16 bytes. Si no existe un generador criptográfico, comunica el error y conserva el formulario.

Se sustituyeron todas las llamadas directas de la aplicación en Finanzas/Inventario, Mensajería, comunicaciones externas y Jornada. No se utilizó `Math.random`. La búsqueda global no encontró llamadas restantes a `crypto.randomUUID()` en el código de la aplicación. Chrome probó operaciones reales con el método nativo deshabilitado.

## 4. Permisos corregidos

**CORREGIDO.** La 009 añade `secciones.ver`, `secciones.administrar`, `casillas.ver`, `casillas.administrar`, `peticiones.categorias` e `incidencias.categorias` a la plantilla electoral del Administrador. No sobrescribe decisiones anteriores ni revocaciones individuales.

El servidor exige permisos y alcance electoral para administrar catálogos; restringe los registros a la elección seleccionada. El selector de secciones admite los permisos de Personas y administración de territorios. Se añadió una operación de lectura de niveles que admite `niveles.administrar` sin ampliar el acceso a otras operaciones de Estructura.

También se corrigió la expansión del permiso histórico `captura-personas`: una decisión granular global del rol, incluso con valor 0, ya no vuelve a concederse por ese alias. Se conserva la prioridad de revocaciones individuales y la evaluación de organización, elección y alcance.

Las pruebas incluyen acceso propio, UUID ajeno, búsquedas, revocación global/electoral del rol, excepciones y revocación individual, niveles y territorios de otra elección. Ocultar controles mantiene todas las comprobaciones del servidor.

## 5. Secciones y Casillas

**CORREGIDO / MEJORADO.** Se conserva el catálogo físico original y se añaden asociaciones electorales `secciones_procesos` y `casillas_procesos`. Hay altas, consultas paginadas y edición de registros propios. Los selectores utilizan el mismo alcance.

Una sección física puede registrarse para otra elección mediante una asociación explícita, sin duplicarla ni modificar sus datos compartidos. Los administradores electorales no modifican datos físicos utilizados por varias elecciones; la interfaz explica la responsabilidad del Super Administrador. Los registros con actas conservan su configuración histórica incluso frente a una edición global.

**AJUSTE AL REANUDAR.** Tipo de casilla usa un select de tipos activos en alta y edición, también al registrar una casilla desde Jornada. El servidor rechaza texto libre y tipos inactivos. Se comprobó directamente el SQL original y `SHOW TABLES` de la base local: no había una tabla de tipos de casilla. La 010 incorpora un único catálogo `casillas_tipos`, conserva los valores físicos anteriores y recupera tipos históricos sin renombrar casillas. No se duplicó un catálogo existente.

Los bloqueos siguen el orden de Jornada y administración: organización, elección y catálogo físico. Así se serializan los cambios de metadatos con capturas y cambios de capacidad. La edición global de una casilla compartida mantiene sus asociaciones de sección. Las modificaciones omitidas no borran distritos existentes.

**PENDIENTE — decisión de datos.** Catálogos sin referencias electorales verificables no se distribuyen automáticamente entre elecciones. Deben asociarse explícitamente en el contexto correspondiente; no se puede inferir su pertenencia a partir de una organización con varias elecciones.

## 6. Navegación/sidebar

**MEJORADO.** Grupos: Administración, Estructura, Operación, Recursos, Jornada y Ajustes. Configuración electoral y Servicios quedan al final. Organizaciones/Procesos conservan su acceso de Super Administrador. Secciones/Casillas utilizan los nuevos permisos.

Se conservan visibilidad por módulo, permisos y las reglas existentes de consulta histórica de Recursos/Jornada. Tareas/Metas y Peticiones/Incidencias mantienen vistas y URLs propias.

## 7. Personas

**CORREGIDO / MEJORADO.** Se resolvió el 403 del catálogo de secciones consultado al entrar. El servidor también comprueba el alcance de la sección cuando se guarda una persona.

Teléfono, correo, CURP y clave de elector se agrupan en dos columnas en escritorio y una en móvil. Se preservan identidad organizacional, participaciones, duplicados, políticas, direcciones, importación y validaciones anteriores. Los diálogos reutilizan cierre y footer comunes. La ficha de consulta/edición ocupa toda la anchura del formulario; los resultados de importación utilizan separación común entre botones.

**AJUSTE AL REANUDAR — permisos efectivos.** La opción y el diálogo de importación sólo se renderizan con `personas.importar` y alcance organizacional. La inicialización y los helpers de frontend también comprueban la capacidad. Todos los endpoints —carga multipart, mapeo, vista previa, decisiones, confirmación, aplicación, resultados, estado y altas automáticas— deniegan con 403 cuando falta el permiso. La sesión recalcula permisos desde la BD; no basta una autorización antigua del navegador.

Crear, editar y gestionar participaciones tienen visibilidad según permiso. El detalle devuelve capacidad de edición según alcance de la persona y opciones de participación según la elección objetivo. La ficha puede permanecer en consulta con campos deshabilitados y sin guardar/retirar/reactivar/incorporar. Se comprobaron denegaciones directas y la pantalla real en Chrome. Una denegación durante la aplicación manual devuelve 403 y conserva la fila para continuar cuando exista autorización.

**AJUSTE AL REANUDAR — padrón.** Se mantienen «Revisar por lotes» y sus decisiones explícitas. «Altas automáticas» recorre todo el archivo en lotes internos de hasta 100, sin confirmaciones humanas entre ellos. Antes de crear revisa el archivo completo, incluida la primera fila de un grupo de coincidencias intrarchivo. Siempre detecta coincidencias; no fusiona ni confirma duplicados automáticamente. Reinspecciona bajo el bloqueo de identidad antes de cada alta, para apartar coincidencias sobrevenidas. Sólo registra nuevas inequívocas; duplicados, ambigüedades, inválidos y referencias sin resolver quedan separados.

La interfaz muestra progreso real y bloquea dobles envíos. Al terminar ofrece resultados y revisión únicamente de las filas apartadas, con paginación si superan 100. El estado y los resultados persisten en las tablas temporales existentes y admiten reintentos sin duplicación durante su vigencia de 24 horas. El recorrido automático usa solicitudes acotadas de la página, no una cola nueva: si se interrumpe, las filas ya registradas se conservan. No asigna elecciones automáticamente. Requiere permiso de alta y consulta organizacional para revisar todas las posibles coincidencias; la opción se oculta y se explica el requisito cuando faltan esas capacidades.

Se verificaron 1.005 altas automáticas reales, coincidencias al final del archivo, datos inválidos, secciones ajenas/no resolubles, XLSX, carrera de identidad, nombres textuales cero, reintentos y 131 excepciones paginadas. Chrome procesó 121 altas y dejó tres filas para revisión manual sin intervención por lote.

## 8. Usuarios

**MEJORADO.** Búsqueda y botón se alinean; el vínculo se guarda en un footer separado. La búsqueda asistida de personas es paginada y conserva los endpoints y controles de unicidad existentes.

Los formularios de accesos/vínculo utilizan la protección común contra envíos duplicados y toast. Los formularios heredados de registro y contraseña conservan sus funciones mediante un adaptador que espera sus AJAX y restaura los botones.

## 9. Estructura organizacional

**MEJORADO.** Se busca primero una persona existente. Al seleccionarla, aparece un resumen compacto con «Cambiar». El alta nueva queda en un acordeón cerrado y utiliza una confirmación natural.

Las participaciones se presentan como tarjetas de elección; una sola opción se selecciona automáticamente. La búsqueda de personas está limitada y paginada; no descarga el padrón completo. Se conservaron niveles, reclutamiento, jerarquía, transferencias, aprobación, contenedores compartidos y personas sin cuenta.

## 10. Configuración electoral

**CORREGIDO / MEJORADO.** Las consultas de niveles/reglas se ejecutan solamente con permiso y módulo habilitado. Si Estructura no está disponible, se muestra una explicación inline y se ocultan los formularios correspondientes.

La selección de un nivel existente carga sus valores para editarlo. Se verificaron creación, edición, activación/desactivación, configuración y aislamiento. Las reglas de promoción, aprobación y responsable tienen ayudas breves. La cuenta autorizadora actual se conserva aunque esté fuera de la primera página del selector.

**NO REPRODUCIDO parcialmente.** No fue necesario reconstruir el guardado de niveles: ya existía. Se mejoró el flujo y se corrigió el caso de lectura con permiso exclusivo de administración de niveles.

## 11. Roles y permisos

**CORREGIDO / MEJORADO.** Dos paneles en escritorio, lista con altura máxima, búsqueda y filtro de decisión. Usuario, rol, contexto y selector de elección limpian inmediatamente los resultados; las respuestas de solicitudes anteriores se descartan.

El contexto global filtra correctamente los registros configurados para ese ámbito. Los selectores de cuentas cargan 25 resultados y permiten búsqueda asistida, en lugar de materializar todos los usuarios. Los territorios tienen búsqueda, selección múltiple, contador, scroll y comprobación de pertenencia a la elección.

## 12. Servicios/Auditoría

**MEJORADO.** Organización, elección y usuario utilizan un selector asistido común: búsqueda, resultados paginados, selección y cierre automático. Los filtros y alcances del servicio permanecen vigentes.

«Archivos por persona» explica el expediente al que se adjuntan documentos e imágenes. Se mantuvieron auditoría, aprobaciones, exportaciones, notificaciones, trabajos, respaldos y restauraciones existentes. No se enviaron comunicaciones externas reales.

## 13. Finanzas

**CORREGIDO / MEJORADO.** Configuración financiera abre un diálogo; sus categorías, cuentas/fondos, métodos, políticas y transferencias dejan de ocupar otra fila permanente de navegación. Formularios agrupados, footer, cierre X y resumen compacto con iconos Lucide.

Chrome realizó altas reales de categorías, cuentas, métodos, política, presupuesto, gasto, anticipo, reembolso, ingreso, aportación y transferencia; también edición y pago. Hubo un guardado adicional en móvil. La regresión anterior mantiene los ciclos de revisión, autorización, caja, distribución, comprobación, cancelación y reversión.

**NUEVO HALLAZGO — CORREGIDO.** El selector mostraba categorías de ingreso y gasto indistintamente. Ahora filtra según la operación. Proveedor/beneficiario y donante registrado/externo tienen instrucciones y validación coherentes con el servidor.

## 14. Inventario

**CORREGIDO / MEJORADO.** Configuración de inventario tiene su propio nombre y diálogo. Se conservaron catálogo, almacenes, existencias calculadas, mínimos, validación de mermas y relaciones operativas/financieras.

Chrome comprobó categorías, artículos, almacenes, entradas, entregas, consumo, préstamo, daños, pérdidas, transferencia entre almacenes y devolución. Los guardados permanecen en Inventario y funcionan sin UUID nativo. Se añadió un guardado real de catálogo en móvil.

**NO REPRODUCIDO como ruta copiada.** Los endpoints distinguían correctamente los módulos. El envío normal causado por la excepción de UUID perdía el parámetro de Inventario y utilizaba la vista predeterminada de Finanzas.

## 15. Interacciones

**MEJORADO.** Bloques de Persona, Datos de interacción y Elecciones relacionadas. La persona seleccionada tiene resumen y cambio; las elecciones usan tarjetas compactas. La relación con una tarea queda en un acordeón opcional con texto natural.

Se preservan participaciones múltiples y la decisión explícita de completar una asignación: registrar una interacción no altera esa regla.

## 16. Encuestas

**MEJORADO.** Quitar pregunta es una acción Lucide compacta y accesible. Frecuencia utiliza «Cada cuánto»; intervalo y unidad se presentan juntos y sólo cuando corresponde repetir. Las escalas enteras con diferencia máxima de diez muestran todas sus opciones; las mayores mantienen el control numérico y sus límites.

Chrome verificó opciones 2–5, rechazo de valor fuera de rango, escala amplia y persistencia de la respuesta. Se conservan versiones, modalidades, condiciones, borradores, correcciones, historial y resultados autorizados.

## 17. Tareas y metas

**MEJORADO.** Navegación separada y formularios por datos generales, asignación y validación/evidencias. Recurrencia y relación con otro registro permanecen cerradas inicialmente.

El permiso de PDF habilita documentos adicionales: no restringe fotografías. Su etiqueta y la persona que autoriza la finalización se expresan en lenguaje de negocio. Se mantienen subtareas, responsables, progreso, validaciones, indicadores y distribución de metas.

## 18. Eventos

**CORREGIDO / MEJORADO.** Tipos de evento usan listado paginado y formulario organizado. La captura de asistentes permanece abierta, limpia sus controles y muestra contador y los diez registros recientes.

Chrome guardó diez participantes reales consecutivos como asistentes en una sesión, sin volver al inicio ni reconstruir todo el evento por cada alta. La actualización de un participante existente conserva su flujo. Permanecen QR, invitados, modalidades de asistencia y relaciones de materiales.

## 19. Peticiones

**CORREGIDO / MEJORADO.** Catálogo configurable por elección, estado activo y edición. Solicitantes externos tienen «Contacto del solicitante». PDF y autorización de cierre usan etiquetas claras.

Los cambios de estado generan una entrada explícita, junto al comentario cuando existe. El detalle mantiene seguimientos, resolución y evidencias. Actualizar una ficha conserva su pestaña. Las categorías históricas inactivas pueden conservarse al editar su registro.

## 20. Incidencias

**CORREGIDO / MEJORADO.** Comparte infraestructura de casos con un catálogo separado por tipo y elección. No admite una categoría de Peticiones ni otra elección. Conserva su tipo histórico, evento relacionado, prioridades, responsables, transferencias y cierre.

Se aplican los mismos ajustes de historial, evidencias y formularios, manteniendo su pantalla y navegación independientes.

## 21. Evidencias/media

**CORREGIDO.** Se revisaron `FileService`, registros de recursos, ACL, vínculos y router. `/media/{uuid}` y miniaturas se despachan mediante el front controller protegido. El router de pruebas utiliza ese recorrido, evitando validar únicamente un bypass del servidor de pruebas.

La regresión verifica imágenes, MIME real, permisos, actor/contexto, fotografías de actas y persistencia tras cambios de estado. Chrome subió y abrió una imagen real en el nuevo chat. No se expusieron rutas físicas ni se cambiaron reglas de evidencia fotográfica/documental. Las acciones visibles dicen «Abrir».

**NO REPRODUCIDO en VPS.** No se consultó Nginx ni el despliegue real. Si allí una solicitud termina antes de PHP, su configuración debe comprobarse posteriormente; no se modificaron servicios para intentar resolverlo.

## 22. Transporte

**MEJORADO.** Necesidad, coordinación, proveedor y observaciones se agrupan. «No definido 1» se reemplaza por una cifra con significado: personas cuya necesidad de transporte está por definir. Los estados sin datos se explican.

La confirmación del traslado continúa siendo voluntaria y no modifica métricas de desempeño. Se preservan relaciones financieras y permisos de coordinación.

## 23. Mensajería

**MEJORADO Y REVISADO AL REANUDAR.** Se leyó directamente `tailwick/apps-chat.html` y se abrió en Chrome como referencia visual con assets de `public/template/assets`. La interfaz utiliza su estructura de chat: cabecera Chats con alta compacta, menú contextual para Configuración/Actualizar, búsqueda integrada, lista de conversaciones con representación por iniciales, último mensaje/estado, fecha, no leídos y silencio. La selección queda resaltada. El panel principal tiene encabezado propio, burbujas diferenciadas, adjuntos, acciones del mensaje, escritura inferior y estado vacío.

En móvil se alternan lista y conversación mediante retorno. Las opciones de integrantes, anuncios, silencio y búsqueda de mensajes están integradas en la cabecera/contexto. Se conservaron los endpoints y controles existentes para directas, grupos manuales/de estructura, anuncios, alcance, membresía, lecturas, moderación, idempotencia y adjuntos. Se añadieron proyecciones acotadas de último mensaje/no leídos a la consulta autorizada y se actualiza la lista al leer/enviar. Los mensajes moderados no revelan su texto en la vista previa.

Chrome comparó la referencia con el chat real en escritorio y móvil, envió desde dos cuentas, comprobó ambos tipos de burbuja, grupo, imagen privada, destinatarios de lectura, conteo, silencio, selección y retorno. No hay información demo ni referencias runtime a `/tailwick/`; los iconos se reutilizan del asset Lucide definitivo.

## 24. Header/notificaciones/chat

**MEJORADO.** Se conserva el cambio de tema de Tailwick. La campana muestra contador propio, recientes, marcar/abrir y acceso al historial. Los errores secundarios de carga tienen explicación inline y no toast automático.

El menú de usuario contiene perfil, notificaciones, Mensajería cuando está disponible, contraseña y cierre de sesión. La contraseña tiene un destino real en el perfil. Chat tiene acceso rápido según módulo y permisos. No se creó un Inbox separado ni opciones ficticias. El contador de chat era opcional y no se añadió; el contador de notificaciones sí está implementado.

## 25. Cambios globales de UX

**MEJORADO.** Tailwick/Tailwind siguen siendo la base. Se extiende `platform.css` y se reutiliza `SicratUI` para diálogos, acciones, tarjetas, selectores y detalles.

SweetAlert mantiene sus helpers y configuración común. Éxito, warning, error e info usan toast; las decisiones explícitas utilizan modal. Validación, instrucciones y estados completos permanecen inline. Las llamadas de la aplicación a `Swal.fire` permanecen centralizadas.

## 26. Botones

**MEJORADO.** Tamaño compacto, jerarquía primaria/secundaria/peligro y acciones de tabla con iconos, título y nombre accesible. El estado busy deshabilita temporalmente las acciones y recupera sus nodos originales, incluidos los iconos.

Las pruebas verifican una sola escritura ante doble envío en formularios AJAX modernos y heredados.

## 27. Modales

**MEJORADO.** Ancho aprovechable en escritorio, dos columnas cuando corresponden, una en móvil, encabezado con X y footer separado. No se sustituyeron callbacks ni se recrearon controles que perdieran sus eventos.

Los campos que quedan solos antes de un bloque ocupan su fila completa, evitando huecos de media columna; se retiran contenedores de acciones vacíos. Exportaciones tienen un diálogo compacto y comunican que la operación se programó. Mensajería utiliza un panel de conversación en el layout y conserva diálogos para sus decisiones/configuración.

## 28. Tablas

**MEJORADO.** Padding y filas más densas, estados, acciones compactas y contenedores con scroll cuando corresponde. La paginación común separa Anterior, Página y Siguiente.

Se preservan límites y offsets del servidor. Los selectores comunes también tienen paginación de 25 resultados y estados sin coincidencias.

## 29. Responsive

**MEJORADO / VERIFICADO.** La regresión anterior recorre escritorio, tableta y móvil. El bloque nuevo añade todas las pantallas a 1280, 768 y 390 px, además de CRUD a 1440 px y guardados en móvil. Comprueba desbordamiento y conserva capturas para inspección visual.

Se inspeccionaron también capturas de diálogos, Estructura, Personas, recursos y chat. Estas comprobaciones son Chrome local con emulación de viewport; no se ensayó hardware móvil ni el VPS.

## 30. Migraciones nuevas

**NUEVO / APLICADO LOCALMENTE.** `20261013_009_revision_post_fase7.php` añade tres tablas de asociaciones/categorías y referencias `categoria_id` en Peticiones/Incidencias. Conserva textos originales, IDs, datos y configuraciones anteriores. Relaciona variantes del nombre dentro del mismo tipo/elección y completa únicamente UUID físicos heredados nulos.

Las asociaciones se recuperan de participaciones, territorios y casillas de Jornada. Personas organizacionales sin participación sólo sirven para inferir elección cuando la organización tiene una única elección. Se evita distribuir catálogos ambiguos.

Preflight de motor, columnas, índices, FKs y contexto; soporte de collations históricas; reentrada por checksum. **La 009 queda congelada después de su aplicación.**

- Tablas locales: **146 → 149**.
- Migraciones locales: **8 → 9**.
- Registros originales de las 146 tablas: conservados; UUID no nulos conservados, UUID nulos completados como cambio previsto.
- SHA-256 de 009: `2306aa85ee0bbc1d8486912fb64bae2b756407a5d7c704d6a69a505a15066d93`.
- Respaldo privado: `/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat-post7-backup-20261014-000651-a3478c/sicrat-before-post7.sql`.
- SHA-256 del respaldo: `e4ed57bb3eca10d5a5540281b8c8d6464a6e2a877e1821a13c4afc1e833c5fe5`.

El respaldo fue restaurado en una base temporal, migrado y comparado antes de aplicar la 009 localmente. El ensayo y las bases de pruebas se eliminaron; el respaldo privado se conserva.

**010 — APLICADA LOCALMENTE Y CONGELADA.** `20261014_010_padron_tipos_casilla.php` añade `casillas_tipos` y `personas_importaciones.modo` (por defecto `batches` para conservar las importaciones anteriores). No modifica filas originales ni reactiva tipos existentes durante una reentrada. Preflight de tabla/columna, motor y clave; verificación de conservación e idempotencia.

- Tablas locales al reanudar: **149 → 150**.
- Migraciones locales al reanudar: **9 → 10**.
- Registros originales de las 149 tablas: conservados.
- SHA-256 de 010: `e93506f11fb002ab3ab00a9b50a47bbf6ece218a10eca6efe64a7f9aacdc05c4`.
- Respaldo privado adicional: `/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat-post7-resume-backup-20261014-045431-0dad25/sicrat-before-post7-resume.sql`.
- SHA-256 del respaldo: `459e289ee1ddbe152b4bfcd40be45158bc4a8e01777299bede5ea64198fcce9c`.

También se restauró y migró este respaldo en una base temporal antes de aplicar 010 localmente. No se intervino en Adariel, VPS ni servicios. La 009 conserva exactamente el checksum de su aplicación anterior.

## 31. Archivos modificados

La lista exacta se incluye al final de este informe. Los cambios se concentran en autorización/rutas, geografía, catálogos de casos, notificaciones, identidad/importación, vistas, helpers y JavaScript existentes. Se añadieron pruebas específicas y se actualizaron expectativas de migraciones/renders sin eliminar las comprobaciones anteriores.

`.DS_Store` y `tailwick/` eran cambios previos del usuario y se conservaron. No se modificaron Composer, `.env`, assets compilados, SQL originales, informes anteriores ni migraciones 001–008.

## 32. Pruebas ejecutadas

- Línea base: `php tests/integration.php --phase7 --browser`.
- Regresión completa y revisión: `php tests/integration.php --post7 --browser`.
- PHP unitario: `tests/run.php` y `tests/phase7-unit.php`: 29 + 28 comprobaciones.
- Frontend: suites base, Fases 2/3/4, UX, Fase 6, feedback y post7: 25 + 16 + 16 + 13 + 17 + 24 + 36 + 206 comprobaciones.
- Migraciones: Fases 3/4/5/6/7 y revisión 009/010: 14 + 16 + 203 + 302 + 168 + 399 + 166 comprobaciones (incluida 010).
- Sintaxis: 308 archivos PHP/JavaScript, sin errores.
- Whitespace: `git -c core.whitespace=cr-at-eol diff --check`, conservando los finales CRLF existentes.
- Integridad: hashes de 23 archivos protegidos; checksums de 009 y 010; conservación del respaldo restaurado y base local.

Resultado final de `php tests/integration.php --post7 --browser`:

| Comprobación | Resultado |
|---|---:|
| HTTP/BD, Fases 1–7 + revisión | **2,810 aprobadas** |
| Chrome, Fases 1–7 + revisión | **1,447 aprobadas** |
| Excepciones JavaScript | **0** |
| Bloque nuevo HTTP/BD | 281 (156 al reanudar) |
| Bloque nuevo Chrome | 249 (23 al reanudar) |
| Capturas privadas de la ejecución | 243 (92 de revisión) |

Log: `/private/tmp/sicrat-post7-resume-final3.log`.
Artefactos: `/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat_phase1_test_16a9d527337a`.

Después de esa regresión completa se ajustó únicamente la anchura del contenedor de la ficha de Personas y la separación de botones de resultados. Verificación final específica: `php tests/integration.php --post7 --browser --post7-browser-only`, **2.810 HTTP/BD y 250 comprobaciones de Chrome del bloque de revisión** (24 al reanudar), **cero excepciones JavaScript**. Incluye una comprobación adicional de anchura de ficha. Log: `/private/tmp/sicrat-post7-resume-layout2.log`; capturas finales adicionales: `/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat_phase1_test_fab0ab1b9e70`.

## 33. Resultado

**APROBADO LOCALMENTE, INCLUIDA LA REANUDACIÓN.** La regresión completa conserva las 1,197 comprobaciones anteriores de Chrome y añade las de esta revisión. Hay cero excepciones JavaScript; los guardados y las comprobaciones de permisos, medios, migraciones y responsive descritos aprobaron.

Entorno ejecutado: PHP 8.5.7 y MariaDB 10.4.21 locales. Los informes anteriores documentan como destino Ubuntu 24.04, Nginx 1.24.0, PHP 8.3.6 y MySQL 8.0.46. Esta ejecución no consultó ni intervino en el VPS. El código nuevo utiliza recursos compatibles con PHP 8.3 y SQL común a ambos motores; no propone cambiar esos componentes.

## 34. Hallazgos adicionales

**NUEVO HALLAZGO — CORREGIDO.** Algunas clases de iconos no están en el CSS compilado. Se reutilizaron los nodos SVG Lucide del bundle existente mediante una factoría común, evitando cuadrados o iconos ausentes y sin añadir librerías.

**NUEVO HALLAZGO — CORREGIDO.** Collations `utf8mb4_general_ci` históricas y el catálogo nuevo requerían comparación explícita. Se corrigió y pasó la regresión de migraciones históricas antes de aplicar la 009.

**NUEVO HALLAZGO — CORREGIDO.** Requisitos de proveedor/donante no estaban suficientemente expresados en formularios; categorías financieras mezclaban clases. Se ajustaron opciones, instrucciones y validación.

**NUEVO HALLAZGO — comportamiento conservado.** Los módulos operativos de Fase 5 bloquean el módulo completo cuando está deshabilitado. Recursos y Jornada tienen reglas de consulta histórica diferentes. No se añadió un modo histórico de Operación ni se modificó esa regla de negocio.

## 35. Pendientes reales antes de Fase 8

**PENDIENTE.** Revisar con datos reales las asociaciones electorales que no tienen referencias suficientes y efectuar su asignación explícita. La migración evita suposiciones que darían acceso a otra elección.

**PENDIENTE para la etapa posterior.** Ejecutar el ensayo sobre el runtime objetivo PHP 8.3.6/MySQL 8.0.46 y comprobar el recorrido de Nginx hasta PHP para archivos protegidos. También permanece pendiente la aceptación del usuario sobre los flujos y el diseño con sus datos. Estas tareas no se iniciaron como QA o configuración de producción.

No quedan instalaciones de dependencias, migraciones anteriores por editar ni reconstrucciones de autenticación propuestas. La etapa se detiene en la entrega de esta revisión.

## 36. git diff

HEAD continúa en `7bb609d`. El staging está vacío. Hay **90 archivos de esta intervención** (incluido este informe); la lista de Git conserva además `.DS_Store` modificado y `tailwick/` sin seguimiento, ambos previos. El diff de archivos rastreados y los archivos nuevos se revisaron por separado.

No se hicieron commits ni staging. Se conservaron los cambios previos del usuario. La siguiente lista corresponde a archivos de esta intervención, separados de `.DS_Store` y `tailwick/`:

- [REVISION_POST_FASE7.md](/opt/homebrew/var/www/sicrat/REVISION_POST_FASE7.md)
- [app/Auth/Authorization.php](/opt/homebrew/var/www/sicrat/app/Auth/Authorization.php)
- [app/Controllers/CommonServicesController.php](/opt/homebrew/var/www/sicrat/app/Controllers/CommonServicesController.php)
- [app/Controllers/OperationsController.php](/opt/homebrew/var/www/sicrat/app/Controllers/OperationsController.php)
- [app/Controllers/PersonImportController.php](/opt/homebrew/var/www/sicrat/app/Controllers/PersonImportController.php)
- [app/Controllers/PollingBoothsController.php](/opt/homebrew/var/www/sicrat/app/Controllers/PollingBoothsController.php)
- [app/Controllers/SectionsController.php](/opt/homebrew/var/www/sicrat/app/Controllers/SectionsController.php)
- [app/Repositories/PermissionRepository.php](/opt/homebrew/var/www/sicrat/app/Repositories/PermissionRepository.php)
- [app/Repositories/PersonIdentityRepository.php](/opt/homebrew/var/www/sicrat/app/Repositories/PersonIdentityRepository.php)
- [app/Repositories/PersonImportRepository.php](/opt/homebrew/var/www/sicrat/app/Repositories/PersonImportRepository.php)
- [app/Repositories/SectionsRepository.php](/opt/homebrew/var/www/sicrat/app/Repositories/SectionsRepository.php)
- [app/Routes/api.php](/opt/homebrew/var/www/sicrat/app/Routes/api.php)
- [app/Services/AdministrationService.php](/opt/homebrew/var/www/sicrat/app/Services/AdministrationService.php)
- [app/Services/Common/NotificationService.php](/opt/homebrew/var/www/sicrat/app/Services/Common/NotificationService.php)
- [app/Services/ElectoralProcessService.php](/opt/homebrew/var/www/sicrat/app/Services/ElectoralProcessService.php)
- [app/Services/GeographyService.php](/opt/homebrew/var/www/sicrat/app/Services/GeographyService.php)
- [app/Services/Jornada/Configuration.php](/opt/homebrew/var/www/sicrat/app/Services/Jornada/Configuration.php)
- [app/Services/Jornada/Queries.php](/opt/homebrew/var/www/sicrat/app/Services/Jornada/Queries.php)
- [app/Services/Operations/CaseCategories.php](/opt/homebrew/var/www/sicrat/app/Services/Operations/CaseCategories.php)
- [app/Services/Operations/CaseService.php](/opt/homebrew/var/www/sicrat/app/Services/Operations/CaseService.php)
- [app/Services/Operations/Context.php](/opt/homebrew/var/www/sicrat/app/Services/Operations/Context.php)
- [app/Services/Operations/MessagingService.php](/opt/homebrew/var/www/sicrat/app/Services/Operations/MessagingService.php)
- [app/Services/PersonIdentityService.php](/opt/homebrew/var/www/sicrat/app/Services/PersonIdentityService.php)
- [app/Services/PersonImportService.php](/opt/homebrew/var/www/sicrat/app/Services/PersonImportService.php)
- [app/Services/Resources/Options.php](/opt/homebrew/var/www/sicrat/app/Services/Resources/Options.php)
- [app/Services/SectionsService.php](/opt/homebrew/var/www/sicrat/app/Services/SectionsService.php)
- [app/Views/administration/index.php](/opt/homebrew/var/www/sicrat/app/Views/administration/index.php)
- [app/Views/layout/footer.php](/opt/homebrew/var/www/sicrat/app/Views/layout/footer.php)
- [app/Views/layout/header.php](/opt/homebrew/var/www/sicrat/app/Views/layout/header.php)
- [app/Views/layout/sidebar-menu.php](/opt/homebrew/var/www/sicrat/app/Views/layout/sidebar-menu.php)
- [app/Views/operations/index.php](/opt/homebrew/var/www/sicrat/app/Views/operations/index.php)
- [app/Views/persons/index.php](/opt/homebrew/var/www/sicrat/app/Views/persons/index.php)
- [app/Views/persons/phase2.php](/opt/homebrew/var/www/sicrat/app/Views/persons/phase2.php)
- [app/Views/polling-booth/index.php](/opt/homebrew/var/www/sicrat/app/Views/polling-booth/index.php)
- [app/Views/profile/index.php](/opt/homebrew/var/www/sicrat/app/Views/profile/index.php)
- [app/Views/resources/index.php](/opt/homebrew/var/www/sicrat/app/Views/resources/index.php)
- [app/Views/sections/index.php](/opt/homebrew/var/www/sicrat/app/Views/sections/index.php)
- [app/Views/services/index.php](/opt/homebrew/var/www/sicrat/app/Views/services/index.php)
- [app/Views/structure/index.php](/opt/homebrew/var/www/sicrat/app/Views/structure/index.php)
- [app/Views/users/accesses.php](/opt/homebrew/var/www/sicrat/app/Views/users/accesses.php)
- [database/migrations/20261013_009_revision_post_fase7.php](/opt/homebrew/var/www/sicrat/database/migrations/20261013_009_revision_post_fase7.php)
- [database/migrations/20261014_010_padron_tipos_casilla.php](/opt/homebrew/var/www/sicrat/database/migrations/20261014_010_padron_tipos_casilla.php)
- [public/assets/css/platform.css](/opt/homebrew/var/www/sicrat/public/assets/css/platform.css)
- [public/assets/js/administration/index.js](/opt/homebrew/var/www/sicrat/public/assets/js/administration/index.js)
- [public/assets/js/forms.js](/opt/homebrew/var/www/sicrat/public/assets/js/forms.js)
- [public/assets/js/header.js](/opt/homebrew/var/www/sicrat/public/assets/js/header.js)
- [public/assets/js/jornada/index.js](/opt/homebrew/var/www/sicrat/public/assets/js/jornada/index.js)
- [public/assets/js/lucide-components.js](/opt/homebrew/var/www/sicrat/public/assets/js/lucide-components.js)
- [public/assets/js/messaging.js](/opt/homebrew/var/www/sicrat/public/assets/js/messaging.js)
- [public/assets/js/operations/core.js](/opt/homebrew/var/www/sicrat/public/assets/js/operations/core.js)
- [public/assets/js/operations/details.js](/opt/homebrew/var/www/sicrat/public/assets/js/operations/details.js)
- [public/assets/js/operations/forms.js](/opt/homebrew/var/www/sicrat/public/assets/js/operations/forms.js)
- [public/assets/js/operations/index.js](/opt/homebrew/var/www/sicrat/public/assets/js/operations/index.js)
- [public/assets/js/persons/identity.js](/opt/homebrew/var/www/sicrat/public/assets/js/persons/identity.js)
- [public/assets/js/persons/import.js](/opt/homebrew/var/www/sicrat/public/assets/js/persons/import.js)
- [public/assets/js/persons/index.js](/opt/homebrew/var/www/sicrat/public/assets/js/persons/index.js)
- [public/assets/js/polling-booth/index.js](/opt/homebrew/var/www/sicrat/public/assets/js/polling-booth/index.js)
- [public/assets/js/resources/core.js](/opt/homebrew/var/www/sicrat/public/assets/js/resources/core.js)
- [public/assets/js/resources/details.js](/opt/homebrew/var/www/sicrat/public/assets/js/resources/details.js)
- [public/assets/js/resources/forms.js](/opt/homebrew/var/www/sicrat/public/assets/js/resources/forms.js)
- [public/assets/js/resources/index.js](/opt/homebrew/var/www/sicrat/public/assets/js/resources/index.js)
- [public/assets/js/sections/index.js](/opt/homebrew/var/www/sicrat/public/assets/js/sections/index.js)
- [public/assets/js/security.js](/opt/homebrew/var/www/sicrat/public/assets/js/security.js)
- [public/assets/js/services/index.js](/opt/homebrew/var/www/sicrat/public/assets/js/services/index.js)
- [public/assets/js/structure/index.js](/opt/homebrew/var/www/sicrat/public/assets/js/structure/index.js)
- [public/assets/js/sweetalert.js](/opt/homebrew/var/www/sicrat/public/assets/js/sweetalert.js)
- [public/assets/js/users/accesses.js](/opt/homebrew/var/www/sicrat/public/assets/js/users/accesses.js)
- [public/assets/js/ux.js](/opt/homebrew/var/www/sicrat/public/assets/js/ux.js)
- [public/index.php](/opt/homebrew/var/www/sicrat/public/index.php)
- [public/polling-booth/index.php](/opt/homebrew/var/www/sicrat/public/polling-booth/index.php)
- [public/sections/index.php](/opt/homebrew/var/www/sicrat/public/sections/index.php)
- [tests/README.md](/opt/homebrew/var/www/sicrat/tests/README.md)
- [tests/integration.php](/opt/homebrew/var/www/sicrat/tests/integration.php)
- [tests/phase2-frontend.js](/opt/homebrew/var/www/sicrat/tests/phase2-frontend.js)
- [tests/phase3-browser.js](/opt/homebrew/var/www/sicrat/tests/phase3-browser.js)
- [tests/phase4-frontend.js](/opt/homebrew/var/www/sicrat/tests/phase4-frontend.js)
- [tests/phase5-browser.js](/opt/homebrew/var/www/sicrat/tests/phase5-browser.js)
- [tests/phase5-migrations.php](/opt/homebrew/var/www/sicrat/tests/phase5-migrations.php)
- [tests/phase5.php](/opt/homebrew/var/www/sicrat/tests/phase5.php)
- [tests/phase6-browser.js](/opt/homebrew/var/www/sicrat/tests/phase6-browser.js)
- [tests/phase7-migrations.php](/opt/homebrew/var/www/sicrat/tests/phase7-migrations.php)
- [tests/phase7.php](/opt/homebrew/var/www/sicrat/tests/phase7.php)
- [tests/post7-browser.js](/opt/homebrew/var/www/sicrat/tests/post7-browser.js)
- [tests/post7-frontend.js](/opt/homebrew/var/www/sicrat/tests/post7-frontend.js)
- [tests/post7-migrations.php](/opt/homebrew/var/www/sicrat/tests/post7-migrations.php)
- [tests/post7-resume-browser.js](/opt/homebrew/var/www/sicrat/tests/post7-resume-browser.js)
- [tests/post7-resume-migrations.php](/opt/homebrew/var/www/sicrat/tests/post7-resume-migrations.php)
- [tests/post7-resume.php](/opt/homebrew/var/www/sicrat/tests/post7-resume.php)
- [tests/post7.php](/opt/homebrew/var/www/sicrat/tests/post7.php)
- [tests/router.php](/opt/homebrew/var/www/sicrat/tests/router.php)
