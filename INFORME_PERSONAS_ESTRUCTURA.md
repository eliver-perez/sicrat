# Informe de mejoras de SICRAT · 17 de noviembre de 2026

## Personas y mapas

La lista y los marcadores abren la misma ficha en modo consulta. La ficha agrupa los datos personales, el contacto, la información electoral, el domicilio y la ubicación en dos columnas de escritorio y una en móvil. Editar abre el formulario existente. Guardar vuelve a leer el registro y Cancelar recupera los datos originales en el mismo modal. La cabecera ofrece la incorporación a la estructura propia, la asignación a una estructura permitida o la consulta de la membresía según las opciones que valida el servicio de Estructura. Las transferencias siguen su flujo existente.

La vista Lista | Mapa reutiliza Leaflet y los filtros de nombre y sección de Personas. El navegador consulta la zona visible al mover el mapa o cambiar filtros. El servidor toma la organización y la elección de la sesión, aplica el permiso de consulta y su alcance, y selecciona el domicilio preferente con coordenadas. El mapa incluye a las personas con participación electoral o membresía estructural activa en esa elección. Una consulta SQL agrupa los puntos por celdas adaptadas al tamaño de la pantalla. Los grupos muestran la cantidad y permiten acercarse o consultar páginas de 25 personas. Un punto individual muestra nombre, clave y sección y permite abrir la ficha. La consulta general de coordenadas requiere conexión.

En alta y edición, **Usar mi ubicación actual** pide una lectura de alta precisión, centra el mapa y permite ajustar el marcador. Si el navegador deniega el permiso o no obtiene una posición, sigue disponible la selección manual. El guardado usa la confirmación normal del formulario. En la configuración de la organización, el centro y el zoom se eligen gráficamente y se guardan con **Guardar vista inicial**. Una persona sin coordenadas sigue sin marcador.

## Estructura, permisos, actividad e importación

- La raíz identifica la membresía activa del titular en el contenedor y la elección. Muestra su nombre, nivel, equipo directo y tamaño de estructura con el mismo componente y los mismos cálculos que la ficha de otro integrante. Los conteos respetan el alcance de consulta.
- El editor de roles agrupa las funciones base y los permisos de los módulos habilitados para ese contexto. Al desactivar un módulo, el servidor deja de conceder acceso y conserva la asignación almacenada para su posterior reactivación. Las pruebas también verificaron el acceso directo a registros históricos de Inventario y Jornada.
- El ojo de Actividad reciente expande el usuario, la acción, el módulo, el elemento y la fecha y hora. Cuando existe un enlace autorizado, se conserva como **Abrir registro**. El detalle utiliza los campos de presentación existentes.
- Cerrar la ventana de importación conserva el progreso al reabrirla en la misma pestaña. **Cancelar importación** pide confirmación y elimina el proceso temporal y sus decisiones pendientes bajo el mismo bloqueo que la aplicación por lotes. Los registros definitivos se conservan. Una prueba aplicó 100 de 102 filas y verificó el resultado después de cancelar.

## Arquitectura y migración

Se conservaron las rutas y controladores PHP, los servicios de dominio y los repositorios PDO. El mapa usa el filtro compartido de Personas, el repositorio de alcances y los componentes Leaflet ya instalados. El catálogo de módulos y el cálculo de permisos efectivos siguen siendo las fuentes de autorización. El catálogo de recursos estáticos de la PWA se regeneró para distribuir los cambios de interfaz.

La [migración 015](database/migrations/20261117_015_indices_mapa_personas.php) agrega dos índices en `personas_domicilios`, uno por coordenadas y persona, y otro para localizar su domicilio preferente. Se ejecutó en las bases desechables. El esquema histórico `database.sql` quedó intacto.

## Verificación

| Ejecución | Resultado |
| --- | --- |
| Personas y Estructura en MySQL 8.0.46 | 1 146 comprobaciones HTTP/BD y 192 en Chrome |
| Dashboard y experiencia móvil | 208 comprobaciones HTTP/BD y 53 en Chrome |
| PWA | 151 comprobaciones HTTP/BD, 72 en Chrome y 40 de persistencia al cerrar y reabrir sin red |
| Regresión de las fases 1–7 en MariaDB 10.4.21 | 2 557 comprobaciones HTTP/BD y 295 de Recursos en Chrome |
| Pruebas unitarias y de frontend | 29 comprobaciones PHP y 128 JavaScript |

Los casos cubren consulta, edición, descarte, guardado, acciones según permisos, filtros y aislamiento del mapa, agrupación y paginación de coordenadas coincidentes, geolocalización concedida y denegada, vista inicial y métricas de raíz. También cubren el ciclo de permisos al apagar y reactivar módulos, el detalle de actividad, el cierre y la cancelación de importaciones. Chrome verificó 320, 390, 768 y 1440 px, modo oscuro y ausencia de desplazamiento horizontal de página.

La geolocalización y los tamaños móviles se comprobaron mediante emulación en Chrome. Queda sin verificar el comportamiento en un iPhone físico o con receptor GPS real. En MySQL 8, la regresión ampliada se detuvo en la verificación de un manifiesto de respaldo de la fase 4. Esa misma regresión pasó completa en MariaDB. Las pruebas específicas de esta ronda pasaron en MySQL. La migración sigue pendiente para cualquier entorno real. El trabajo se entregó en el árbol local, sin commit ni despliegue.

## Archivos de implementación y pruebas

- `app/Controllers/` — [PersonImportController.php](app/Controllers/PersonImportController.php), [PersonsController.php](app/Controllers/PersonsController.php)
- `app/Repositories/` — [ModuleRepository.php](app/Repositories/ModuleRepository.php), [PermissionRepository.php](app/Repositories/PermissionRepository.php), [PersonIdentityRepository.php](app/Repositories/PersonIdentityRepository.php), [PersonsRepository.php](app/Repositories/PersonsRepository.php)
- `app/Routes/` — [api.php](app/Routes/api.php)
- `app/Services/` — [AdministrationService.php](app/Services/AdministrationService.php), [PersonIdentityService.php](app/Services/PersonIdentityService.php), [PersonImportService.php](app/Services/PersonImportService.php), [PersonsService.php](app/Services/PersonsService.php), [StructureService.php](app/Services/StructureService.php)
- `app/Services/Jornada/` — [Context.php](app/Services/Jornada/Context.php)
- `app/Services/Resources/` — [Context.php](app/Services/Resources/Context.php)
- `app/Views/persons/` — [index.php](app/Views/persons/index.php), [phase2.php](app/Views/persons/phase2.php)
- `database/migrations/` — [20261117_015_indices_mapa_personas.php](database/migrations/20261117_015_indices_mapa_personas.php)
- `public/assets/css/` — [dashboard.css](public/assets/css/dashboard.css), [persons-context.css](public/assets/css/persons-context.css)
- `public/assets/js/administration/` — [index.js](public/assets/js/administration/index.js)
- `public/assets/js/dashboard/` — [index.js](public/assets/js/dashboard/index.js)
- `public/assets/js/jornada/` — [index.js](public/assets/js/jornada/index.js)
- `public/assets/js/persons/` — [activity.js](public/assets/js/persons/activity.js), [identity.js](public/assets/js/persons/identity.js), [import.js](public/assets/js/persons/import.js), [index.js](public/assets/js/persons/index.js), [map.js](public/assets/js/persons/map.js), [overview-map.js](public/assets/js/persons/overview-map.js), [structure-assignment.js](public/assets/js/persons/structure-assignment.js)
- `public/assets/js/resources/` — [index.js](public/assets/js/resources/index.js)
- `public/assets/js/structure/` — [index.js](public/assets/js/structure/index.js)
- `public/` — [pwa-assets.js](public/pwa-assets.js)
- `tests/` — [frontend.js](tests/frontend.js), [own-responsible-level-browser.js](tests/own-responsible-level-browser.js), [person-context-browser.js](tests/person-context-browser.js), [person-context.php](tests/person-context.php), [person-map-browser.js](tests/person-map-browser.js), [person-map.php](tests/person-map.php), [persons-structure-browser.js](tests/persons-structure-browser.js), [phase2-frontend.js](tests/phase2-frontend.js), [phase2.php](tests/phase2.php), [phase6-browser.js](tests/phase6-browser.js), [phase6-coverage.php](tests/phase6-coverage.php), [phase6-services.php](tests/phase6-services.php), [phase7-coverage.php](tests/phase7-coverage.php), [structure-interface-browser.js](tests/structure-interface-browser.js), [structure-navigation.php](tests/structure-navigation.php), [uiux-browser.js](tests/uiux-browser.js), [ux-browser.js](tests/ux-browser.js)
