# Revisión UX/UI de SICRAT — Fases 1–4

Fecha: 12 de octubre de 2026. Base: commit `9c6026b` (Fase 4), con Git limpio al comenzar.

## 1. Resumen

**IMPLEMENTADO Y COMPROBADO.** Se mejoró la presentación de las pantallas existentes conservando las reglas de identidad, multielección, sesiones, permisos, alcances, estructura y servicios comunes. Se revisaron diagnóstico, informes anteriores, código, Git/diff, pruebas frontend y el recorrido Chrome existente. La línea base pasó con **1,033 HTTP/BD y 27 Chrome**.

La revisión final pasó con **1,051 HTTP/BD y 306 Chrome**, además de pruebas PHP/frontend, migraciones históricas y sintaxis. Chrome recorrió Dashboard, Organizaciones, Procesos Electorales, Secciones, Casillas, Personas, Usuarios, Estructura, Configuración, Servicios, Perfil y Autenticación, incluyendo diálogos, usuarios restringidos y cuatro tamaños.

No se inició Fase 5. No se modificaron el esquema, SQL originales, migraciones, dependencias, configuración privada, autenticación backend ni informes anteriores. Las escrituras de prueba se realizaron únicamente en bases y directorios desechables. No se intervino en Adariel, el VPS ni servicios del sistema.

## 2. Componentes Tailwick reutilizados

Se inspeccionaron los assets de Tailwick/Tailwind instalados, los patrones del layout y Perfil, el ejemplo local de Dashboard y el código incluido de tabs, dropdowns, overlays, selects y accesibilidad. No se copiaron demos ni se añadieron bibliotecas.

| Patrón existente | Aplicación en la revisión |
|---|---|
| `card`, `card-header`, `card-body` | Layout actual, configuración, accesos del Dashboard y servicios. |
| `btn`, `bg-primary`, `bg-danger`, colores de la plantilla | Jerarquía de acciones, bordes secundarios, foco y estados disabled. |
| `form-input`, `form-checkbox`, grids y espaciado | Formularios agrupados, búsquedas, selectores y territorios. |
| Tabs de Perfil: `role="tab"`, `data-hs-tab`, `aria-controls` | Navegación secundaria de Configuración y Servicios. |
| Tablas y badges | Permisos, accesos, estructura, estados y listados. |
| Sidebar/offcanvas y dropdown de cuenta | Navegación global conservada; accesibilidad del menú móvil reforzada. |
| Diálogos ya existentes con estética de cards | Anchos proporcionados, scroll, títulos y botones identificables. |
| Disclosure/accordion con `details` | Agregar/editar acceso, programación de respaldos y detalles de auditoría. |

`SicratUI` administra las pestañas con ese patrón visual, activación por permiso, carga diferida y teclado que omite controles ocultos o deshabilitados. Las extensiones CSS utilizan los colores y componentes de la plantilla. Se mantuvieron los diálogos nativos existentes. **No se introdujo Bootstrap ni otra biblioteca UI.**

## 3. Cambios en header

Selector electoral situado inmediatamente después del botón del menú. El header conserva el tema, dropdown de cuenta y enlaces existentes. Se añadieron nombres accesibles a controles de iconos y estado expandido del menú. El idioma del documento es español.

Se corrigió la presentación móvil de breadcrumbs y texto secundario del footer sin alterar los assets compilados de la plantilla. El menú cerrado queda fuera del foco; Escape cierra el offcanvas y devuelve el foco al botón.

## 4. Selector electoral

Eliminada la tarjeta que se insertaba al inicio de `main`. El header muestra etiqueta, organización, elección y rol electoral cuando está disponible. Tiene ancho acotado y se adapta a móvil sin desbordar.

Conserva `loadOptions`, la API de elecciones autorizadas, POST de cambio, CSRF y redirección al inicio para renovar el contexto. Se muestra estado de cambio y se deshabilita cuando no hay elecciones. Chrome comprobó cambios reales desde el header, incluyendo retorno a otra elección.

## 5. Configuración electoral

Navegación principal: Módulos, Niveles y reglas, Roles y permisos. Sólo aparecen apartados autorizados. Se agruparon nombre/orden del nivel, capacidades organizacionales y reglas de transferencia. Se conserva la diferencia entre nivel y rol.

Las acciones de creación y guardado siguen usando las APIs anteriores. No se modificaron reglas de promoción, aprobador, delegación ni transferencias.

## 6. Grid de módulos

Funciones esenciales en badges compactos. Los diez módulos opcionales existentes aparecen en cards de tres, dos o una columna según ancho. Los estados se expresan como Activo, Disponible, No autorizado y Próximamente. Los switches tienen etiquetas y estados disabled coherentes con autorización, organización e implementación.

Un único **Guardar cambios** envía las modificaciones por las APIs actuales. Durante el guardado bloquea los controles; muestra progreso y éxito. Ante un fallo conserva los cambios restantes y permite reintentar. No se presenta el conjunto como una transacción: cada respuesta satisfactoria se retira de los pendientes. Cambiar organización/ámbito con cambios pendientes pide decidir si se descartan.

Cuando la organización elegida no coincide con la elección de trabajo, se presenta su configuración organizacional y se deshabilita el ámbito electoral incompatible. No cambia la elección global. Chrome comprobó fallo simulado, reintento, persistencia y recuperación del estado inicial del ensayo.

## 7. Roles

Pestaña propia con lista de nombres y formulario alineado: nombre y Crear rol en una fila; apilados en móvil. La creación real se comprobó sobre la base desechable y actualiza los selectores.

## 8. Permisos

Pestañas separadas para Permisos del rol y Excepciones por usuario. El selector usa nombres del catálogo y categorías comprensibles. El listado es una tabla con Permiso, Estado, Alcance y Origen; incluye búsqueda y filtro Concedido/Revocado.

Se distingue Heredado del rol, Configuración global y Configurado para esta elección. El GET existente añade el campo `contexto`, ya presente en BD, para distinguir plantilla electoral de permiso global sin inferirlo de un ID. No cambió la escritura, herencia, precedencia ni validación de permisos.

## 9. Territorios

Buscador con lista de checkboxes de 176 px, scroll, contador, anterior/siguiente y deselección. Consulta el catálogo en páginas de 25. Mantiene las selecciones al buscar o paginar; primero lee las asignaciones guardadas del usuario. No descarga todo el catálogo.

El guardado conserva el endpoint y límite de cien secciones. Mientras cambia el usuario bloquea Guardar y descarta respuestas anteriores para evitar mezclar selecciones de cuentas distintas.

## 10. Personas

Listado y alta rápida en un grid que se apila en pantallas pequeñas. Los filtros tienen nombres accesibles y Buscar es visible. La ficha se divide en Datos personales, Contacto, Domicilio y Participación electoral; mantiene nombres de campos, código permanente, validaciones y datos enviados.

Contacto usa controles de teléfono/correo, referencias un textarea y elecciones un multiselect con altura suficiente. Participaciones en tabla con estado y acción. Las acciones del listado tienen apariencia de botón.

Se revisaron ficha, reglas de identidad y carga/mapeo/revisión de CSV en Chrome. El CSV visual se cargó en el ensayo y se revisó, sin confirmar su importación. La suite conserva toda la regresión de importaciones CSV/XLSX y coincidencias.

## 11. Usuarios/accesos

Acción explícita Administrar accesos en el listado. Modal dividido en Accesos electorales y Persona vinculada. Los accesos existentes se muestran en tabla Elección/Rol/Estado/Acción; el formulario adicional se abre mediante disclosure y al editar una fila.

Se conservan las APIs de asignación y vínculo. La contraseña de alta ahora se presenta como campo password con autocomplete adecuado. Se mantienen los controles de estado y permisos.

## 12. Estructura

Acciones visibles para incorporar, crear copia independiente, asociar elección, transferir, modificar nivel y cambiar estado. Se utiliza Responsable directo, Superiores, Equipo a cargo, Integrantes, Nivel y Estado. Se ajustó el ancho de la columna de integrantes.

Historial y solicitudes se presentan en tablas con columnas y estado, conservando paginación y resolución autorizada. Los diálogos tienen tamaños adecuados y etiquetas; al asociar una elección se oculta también la etiqueta del nombre que no aplica. Chrome abrió incorporación, ficha, creación, copia, asociación, historial, transferencias y creación de cuenta.

## 13. Servicios

Pestañas Notificaciones, Auditoría, Aprobaciones, Archivos, Exportaciones, Trabajos, Envíos y Respaldos, según permisos. El capturista del ensayo sólo ve Notificaciones; el administrador cliente no ve Respaldos.

Cada dataset se carga al abrir su pestaña, con actualización explícita y paginación conservadas. Archivos busca personas por páginas de 25. Estados, tamaños y tipos se presentan con términos legibles y badges. Aprobaciones y revisión de restauración usan información estructurada en vez de JSON crudo.

Programación/retención de respaldos se agrupa en un disclosure. Los listados largos tienen scroll acotado y paginación fuera de su área. No se modificaron workers, archivos privados, exportadores, consentimiento, backup/restore ni sus controles backend.

## 14. Auditoría

Filtros por nombres de organización, elección y cuenta; actividades y áreas con etiquetas legibles. Organización, elección y usuario admiten búsqueda y opciones paginadas. Los identificadores se transmiten internamente y no son campos numéricos que el operador deba conocer.

Nuevo GET `/api/audit/options`, autorizado con `audit.read`, reutiliza exactamente los criterios de autorización/contexto del listado. Ofrece sólo sujetos/actividades registrados en el historial permitido. Usa tipos de consulta explícitos, parámetros, límites y paginación. El listado añade nombres de organización, elección y actor; conserva los datos previos y sanitización.

La presentación de cambios omite identificadores internos y traduce estados descriptivos. Las pruebas verifican nombres, paginación, ausencia de secretos en opciones, búsqueda parametrizada y rechazos de cuenta/organización no autorizadas.

## 15. Botones

Primarios azules para acciones principales. Secundarios con fondo, borde, hover y tamaño reconocible. Peligro reservado para rechazo/restauración y acciones equivalentes; Cancelar es secundario. La paginación usa botones secundarios con disabled visible.

Se verificó expresamente que clases `hidden` y atributos `hidden` siguen ocultando acciones: un override visual no puede hacer aparecer Registrar/Cancelar fuera del estado correspondiente.

## 16. Formularios

Labels asociados, explicación breve, agrupación por intención, rejillas de dos columnas y apilado móvil. Campos opcionales y ajustes secundarios usan disclosures. Textarea y multiselect ya no parecen inputs de una línea.

En Procesos se eliminó el formulario deshabilitado que simulaba detalle y se dejó el acceso claro al alta real desde Organizaciones. En Casillas se comunica el estado todavía no implementado y se enlaza Secciones; no se crearon operaciones de casillas.

Autenticación conserva su flujo, corrige el cierre HTML de Ingresar y autocomplete. Se retiró el checkbox Recordarme que el script no enviaba y no tenía efecto, sin cambiar la política de sesiones.

## 17. Tablas/listados

Padding, encabezados, alineación, acciones y scroll coherentes. Se corrigieron los encabezados de Secciones para coincidir con Estado/Municipio. Organizaciones muestra Ver elecciones y explica el listado dependiente de la organización seleccionada.

Se retiró el alto mínimo de 480 px que producía grandes espacios en tablas pequeñas. Hay mensajes de consulta vacía y estructura sin integrantes. Los listados paginados mantienen sus límites anteriores.

## 18. Responsive

| Perfil Chrome | Viewport | Resultado |
|---|---|---|
| Escritorio ancho | 1600 × 1000 | Header compacto y módulos en tres columnas. |
| Laptop | 1280 × 960 | Controles y columnas legibles; tablas con scroll cuando corresponde. |
| Tablet | 820 × 1024 | Módulos en dos columnas y sidebar offcanvas. |
| Móvil | 390 × 844 | Una columna, formularios apilados, header y diálogos dentro del viewport. |

Se comprobaron cambios de ancho sin recargar y recorridos de páginas recargadas en cada tamaño. Las tablas anchas conservan scroll horizontal, sin ampliar el documento. Los diálogos largos usan scroll interno. Se esperó el fin de las transiciones para inspeccionar capturas.

## 19. Accesibilidad básica

Documento en español, labels/aria-label, títulos de diálogos, encabezados de columnas, foco visible y disabled distinguible. Las pestañas soportan flechas/Home/End y seleccionan sólo controles visibles/habilitados. El menú móvil cerrado es inert; Escape lo cierra y devuelve el foco.

Estados incluyen palabras y no dependen sólo del color. Se conservan los diálogos nativos y su navegación de foco. Se verificó accesibilidad básica; no se declara una certificación WCAG completa.

## 20. Archivos modificados

El inventario completo se incluye al final de esta sección. Las modificaciones backend se limitan a lecturas descriptivas de auditoría y origen de permisos. El resto corresponde a vistas, CSS/JS y pruebas/documentación. Los assets compilados y dependencias de la plantilla permanecen intactos.

**Existentes modificados (39):**

- `app/Controllers/CommonServicesController.php`
- `app/Routes/api.php`
- `app/Services/AdministrationService.php`
- `app/Services/Common/AuditService.php`
- `app/Views/administration/index.php`
- `app/Views/auth/index.php`
- `app/Views/dashboard/index.php`
- `app/Views/electoral-process/index.php`
- `app/Views/layout/footer.php`
- `app/Views/layout/header.php`
- `app/Views/layout/title.php`
- `app/Views/organizations/index.php`
- `app/Views/persons/index.php`
- `app/Views/persons/phase2.php`
- `app/Views/polling-booth/index.php`
- `app/Views/profile/index.php`
- `app/Views/sections/index.php`
- `app/Views/services/index.php`
- `app/Views/structure/index.php`
- `app/Views/users/accesses.php`
- `app/Views/users/index.php`
- `public/assets/css/platform.css`
- `public/assets/js/administration/index.js`
- `public/assets/js/electoral-context.js`
- `public/assets/js/electoral-process/index.js`
- `public/assets/js/global.js`
- `public/assets/js/organizations/index.js`
- `public/assets/js/persons/identity.js`
- `public/assets/js/persons/index.js`
- `public/assets/js/sections/index.js`
- `public/assets/js/services/index.js`
- `public/assets/js/structure/index.js`
- `public/assets/js/users/accesses.js`
- `public/assets/js/users/index.js`
- `tests/README.md`
- `tests/integration.php`
- `tests/phase2-frontend.js`
- `tests/phase3-browser.js`
- `tests/phase4-frontend.js`

**Nuevos (5):**

- `UX_UI_FASES_1_4.md`
- `public/assets/js/ux.js`
- `tests/ux-browser.js`
- `tests/ux-frontend.js`
- `tests/ux.php`

## 21. Pruebas ejecutadas

| Comprobación | Resultado final |
|---|---|
| `php tests/run.php` | 29 PHP |
| `node tests/frontend.js` | 25 frontend Fase 1 |
| `node tests/phase2-frontend.js` | 16 frontend Fase 2 |
| `node tests/phase3-frontend.js` | 16 frontend Fase 3 |
| `node tests/phase4-frontend.js` | 13 frontend Fase 4 |
| `node tests/ux-frontend.js` | 17 texto seguro, pestañas y teclado |
| `php tests/phase3-migrations.php` | 14 históricas/conflictos, sólo base desechable |
| `php tests/phase4-migrations.php` | 16 preservación/idempotencia, sólo base desechable |
| `php tests/integration.php --phase4 --browser --ux` | **1,051 HTTP/BD + 306 Chrome** |
| Sintaxis PHP | 177 archivos, correcta |
| Sintaxis JS | 31 archivos, correcta |
| Diff/whitespace, EOF y finales de línea | Correctos |
| Hashes protegidos | SQL, migraciones, informes anteriores, autenticación/middleware, `.env` y Composer intactos |

La suite completa conserva sesiones, CSRF, aislamiento, identidad/multielección/importación, estructura, permisos/alcances/módulos y todos los servicios Fase 4, incluyendo formatos reales, concurrencia, respaldos y restauraciones desechables. Nunca restaura la instalación activa ni envía mensajes reales. La excepción técnica intencional de `run.php` comprueba que el detalle no llegue al JSON.

Se adaptaron los mismos casos de navegador a la nueva navegación, conservando sus verificaciones funcionales, y se añadieron los casos UX. Chrome terminó sin excepciones JavaScript. No se instalaron dependencias de prueba.

## 22. Capturas revisadas

Última ejecución completa:

`/var/folders/95/73gsxch94r5bc9zgyxz3_q740000gn/T/sicrat_phase1_test_356772737568`

Generó **100 capturas UX privadas**, cuatro hojas de contacto adicionales y las capturas anteriores de Fases 3/4. `ux-captures.json` enumera el recorrido. No se copiaron imágenes al repositorio/public ni se publicaron.

Se revisaron visualmente las hojas de contacto de todas las páginas en escritorio, laptop, tablet y móvil, y capturas ampliadas de módulos, permisos, territorios, fichas de Persona/Usuario, estructura, auditoría, servicios, importación, creación/asociación/copia de estructura, creación de cuenta y revisión de restauración. Se inspeccionaron también capturas de iteraciones anteriores para corregir los hallazgos visuales.

Los servicios se capturaron por pestaña en escritorio/móvil, con capturista, administrador cliente y Super Administrador. El offcanvas se capturó abierto. La revisión Chrome del diálogo de restauración usa datos descriptivos ficticios y no confirma operaciones; los ensayos reales de backend siguen cubiertos por Fase 4 en destinos desechables.

## 23. Nuevos hallazgos

**COMPROBADOS / CORREGIDOS:**

- Selector electoral en tarjeta repetida que consumía espacio en cada página.
- Botones secundarios sin superficie reconocible, listados pequeños con alto mínimo innecesario y distribución invertida de Personas.
- Códigos de permisos/orígenes poco claros, listados de accesos y permisos sin columnas, territorios como un select demasiado pequeño.
- Dataset completo de cada servicio cargado antes de elegir apartado.
- Auditoría solicitando IDs y mostrando relaciones numéricas en columnas principales.
- Encabezados de Secciones que no correspondían a los datos y labels de selects de Organizaciones asociados a IDs incorrectos.
- Controles sin efecto: Recordarme, formulario de detalle electoral inoperante y acciones de Casillas sin implementación. Se corrigió su presentación; no se crearon funcionalidades nuevas.
- Durante la revisión visual, las extensiones de botones podían sobreescribir `hidden`; se corrigió y se añadió regresión específica.
- Labels de filtros generados como celdas independientes desalineaban la rejilla; se agruparon con sus controles.
- La presentación de una fila con proceso nulo no permite distinguir plantilla electoral de permiso global. Se añadió el campo de lectura existente `contexto` y se corrigió la etiqueta.

**RECOMENDACIÓN DE CONTINUIDAD:** los próximos módulos deben reutilizar esta jerarquía de controles, navegación autorizada y patrones de búsqueda/paginación. No es una instrucción ni un inicio de Fase 5.

## 24. Pendientes visuales

No queda una decisión visual obligatoria ni un fallo reproducido que impida cerrar esta intervención. La aceptación de los cambios corresponde a la revisión del usuario.

La comprobación responsive se realizó en Chrome con viewports emulados, no con dispositivos físicos. La inspección del diálogo de restauración no certifica destinos reales de despliegue. Casillas y los módulos señalados como Próximamente siguen sin implementación funcional, conforme al alcance autorizado.

## 25. Estado de git diff

Respecto de `9c6026b`: **39 archivos existentes modificados y cinco nuevos**, incluido este informe. Todo permanece sin commit ni staging para revisión. Se revisó el diff, whitespace, finales de línea y EOF; no hay reformateo masivo de archivos previos.

Los dos SQL originales, todas las migraciones, `Diagnostico.md`, `FASE1.md`–`FASE4.md`, dependencias y entorno privado conservan sus hashes. No se ejecutaron migraciones sobre la base real, no se cambió el entorno objetivo Ubuntu/Nginx/PHP/MySQL y no se tocó Adariel.

**Revisión terminada. Fase 5 no iniciada. Se detiene el trabajo y se espera revisión e instrucciones.**
