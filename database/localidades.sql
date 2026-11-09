INSERT INTO localidades (municipio, localidad, codigo_postal) VALUES
    (3485, 'Ciudad Miguel Alemán', '88300'),
    (3485, 'Los Guerra', '88310'),
    (3485, 'Los Ángeles', '88321'),
    (3485, 'Guardados de Arriba', '88321'),
    (3485, 'Arcabuz', '88340'),
    (3485, 'San Antonio de Reyna', '88346'),
    (3485, 'El Nogalito', NULL),
    (3485, 'Tres Palmas', NULL),
    (3485, 'Los López', '88338'),
    (3485, 'El Mezquitito', NULL),
    (3485, 'Los Chapotes', NULL),
    (3485, 'Charco Cercado', NULL),
    (3485, 'Los Treviño', '88335'),
    (3485, 'La Ceja', '88336'),
    (3485, 'Los García', NULL),
    (3485, 'Las Minitas', NULL),
    (3485, 'La Peña', NULL),
    (3485, 'Los Solís', NULL),
    (3485, 'Rancho Nuevo', NULL),
    (3485, 'Los Altitos', NULL),
    (3485, 'Los Marroquines', NULL),
    (3485, 'La Laguna', NULL),
    (3485, 'Los Guajillos', '88330'),
    (3485, 'El Barretal', NULL),
    (3485, 'Puerto Oriente', NULL),
    (3485, 'Las Bayas', NULL),
    (3485, 'El Quitrín', NULL),
    (3485, 'Reina Azteca', NULL),
    (3485, 'Cerro Azul', '88349'),
    (3485, 'La Sandia', NULL),
    (3485, 'El Salitrillo', NULL);






SET @localidad_miguel_aleman = (
    SELECT id
    FROM localidades
    WHERE municipio = 3485
      AND localidad = 'Ciudad Miguel Alemán'
    LIMIT 1
);

INSERT INTO colonias (localidad, colonia, codigo_postal) VALUES
    (@localidad_miguel_aleman, 'Ciudad Miguel Alemán Centro', '88300'),

    (@localidad_miguel_aleman, 'Gómez', '88302'),
    (@localidad_miguel_aleman, 'INFONAVIT Arboleda', '88302'),
    (@localidad_miguel_aleman, 'Solidaridad', '88302'),
    (@localidad_miguel_aleman, 'Nuevo Santander', '88302'),
    (@localidad_miguel_aleman, 'Plan de Guadalupe', '88302'),
    (@localidad_miguel_aleman, 'Del Norte', '88302'),
    (@localidad_miguel_aleman, 'ITAVU', '88302'),
    (@localidad_miguel_aleman, 'Jardín', '88302'),
    (@localidad_miguel_aleman, 'Mezquital del Norte', '88302'),
    (@localidad_miguel_aleman, 'Niños Héroes', '88302'),

    (@localidad_miguel_aleman, 'Barrera', '88303'),
    (@localidad_miguel_aleman, 'Cristóbal Colón', '88303'),
    (@localidad_miguel_aleman, 'Independencia', '88303'),
    (@localidad_miguel_aleman, 'Nuevo Amanecer', '88303'),
    (@localidad_miguel_aleman, 'Río Bravo', '88303'),
    (@localidad_miguel_aleman, 'Unidad Revolucionaria', '88303'),

    (@localidad_miguel_aleman, 'El Mirador', '88304'),
    (@localidad_miguel_aleman, 'Guadalupe', '88304'),
    (@localidad_miguel_aleman, 'Los Presidentes', '88304'),
    (@localidad_miguel_aleman, 'Montebello', '88304'),

    (@localidad_miguel_aleman, 'Adolfo López Mateos', '88305'),
    (@localidad_miguel_aleman, 'Arguello', '88305'),
    (@localidad_miguel_aleman, 'Buenos Aires', '88305'),
    (@localidad_miguel_aleman, 'Lindavista', '88305'),
    (@localidad_miguel_aleman, 'Tamaulipas', '88305'),

    (@localidad_miguel_aleman, 'Educación', '88306'),
    (@localidad_miguel_aleman, 'Electricistas', '88306'),
    (@localidad_miguel_aleman, 'Hercilia', '88306'),
    (@localidad_miguel_aleman, 'INFONAVIT Rinconada', '88306'),
    (@localidad_miguel_aleman, 'Mezquital del Río', '88306'),

    (@localidad_miguel_aleman, 'Benito Juárez', '88307'),
    (@localidad_miguel_aleman, 'Jardines de San Germán', '88307'),
    (@localidad_miguel_aleman, 'Lucha Social', '88307'),
    (@localidad_miguel_aleman, 'Rodríguez', '88307'),
    (@localidad_miguel_aleman, 'Sabinitos', '88307'),
    (@localidad_miguel_aleman, 'San Pedro', '88307'),
    (@localidad_miguel_aleman, 'Villa del Mar', '88307'),

    (@localidad_miguel_aleman, 'Américo Villarreal Guerra', '88310'),
    (@localidad_miguel_aleman, 'INFONAVIT Industrial', '88310'),
    (@localidad_miguel_aleman, 'INFONAVIT las Torres', '88310'),
    (@localidad_miguel_aleman, 'INFONAVIT Poniente', '88310'),
    (@localidad_miguel_aleman, 'Parque Industrial Miguel Alemán', '88310'),
    (@localidad_miguel_aleman, 'Santa Luisa', '88310'),
    (@localidad_miguel_aleman, 'Unidos Avanzamos', '88310');


INSERT INTO secciones_electorales
    (
        uuid,
        estado,
        municipio,
        seccion,
        distrito_local,
        distrito_federal,
        registro,
        f_registro
    )
VALUES
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 722, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 723, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 724, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 725, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 726, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 727, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 728, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 729, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 730, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 731, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 732, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 733, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 734, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 735, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 736, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 737, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 738, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 739, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 740, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 741, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 742, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 743, 3, 7, 1, NOW()),
    (UNHEX(REPLACE(UUID(), '-', '')), 28, 3485, 744, 3, 7, 1, NOW());