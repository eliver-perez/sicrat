CREATE TABLE organizaciones (
    id                                      INT AUTO_INCREMENT PRIMARY KEY,
    uuid                                    BINARY(16) NOT NULL UNIQUE,
    organizacion                            VARCHAR(150) NOT NULL,
    contacto                                VARCHAR(255) DEFAULT NULL,
    telefono                                VARCHAR(20) DEFAULT NULL,
    email                                   VARCHAR(255) DEFAULT NULL,
    activo                                  TINYINT NOT NULL DEFAULT 1,
    registro                                INT DEFAULT NULL,
    f_registro                              DATETIME NOT NULL,
    f_actualizacion                         DATETIME NULL,
    INDEX IDX_organizaciones(organizacion)
);

CREATE TABLE tipos_eleccion (
    id              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    clave           VARCHAR(30) NOT NULL,
    tipo            VARCHAR(100) NOT NULL,
    ambito          ENUM('federal', 'estatal', 'municipal') NOT NULL,
    activo          TINYINT NOT NULL DEFAULT 1,

    CONSTRAINT UK_tiposeleccion_clave UNIQUE(clave)
);

INSERT INTO tipos_eleccion
    (clave, tipo, ambito)
VALUES
    ('PRESIDENCIA', 'Presidencia de la República', 'federal'),
    ('SENADURIA', 'Senaduría', 'federal'),
    ('DIPUTACION_FEDERAL', 'Diputación Federal', 'federal'),

    ('GUBERNATURA', 'Gubernatura', 'estatal'),
    ('JEFATURA_GOBIERNO', 'Jefatura de Gobierno', 'estatal'),
    ('DIPUTACION_LOCAL', 'Diputación Local', 'estatal'),

    ('AYUNTAMIENTO', 'Ayuntamiento', 'municipal'),
    ('ALCALDIA', 'Alcaldía', 'municipal'),

    ('PODER_JUDICIAL_FEDERAL', 'Poder Judicial Federal', 'federal'),
    ('PODER_JUDICIAL_LOCAL', 'Poder Judicial Local', 'estatal');

CREATE TABLE caracter_eleccion (
    id              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    clave           VARCHAR(30) NOT NULL,
    caracter         VARCHAR(100) NOT NULL,
    activo          TINYINT NOT NULL DEFAULT 1,

    CONSTRAINT UK_caractereleccion_clave UNIQUE(clave)
);

INSERT INTO caracter_eleccion
    (clave, caracter)
VALUES
    ('ordinaria', 'Ordinaria'),
    ('extraordinaria', 'Extraordinaria');

CREATE TABLE procesos_electorales (
    id                                      INT AUTO_INCREMENT PRIMARY KEY,
    uuid                                    BINARY(16) NOT NULL UNIQUE,
    organizacion                            INT NOT NULL,
    proceso                                 VARCHAR(150) NOT NULL,
    tipo                                    SMALLINT NOT NULL,
    caracter                                SMALLINT NOT NULL,
    f_eleccion                              DATE NULL,
    f_inicio                                DATE NULL,
    f_fin                                   DATE NULL,
    estatus                                 TINYINT NOT NULL DEFAULT 1,
    registro                                INT NOT NULL,
    f_registro                              DATETIME NOT NULL,
    f_actualizacion                         DATETIME DEFAULT NULL,

    CONSTRAINT FK_procesoselectorales_organizacion FOREIGN KEY (organizacion) REFERENCES organizaciones(id),
    CONSTRAINT FK_procesoselectorales_tipo FOREIGN KEY (tipo) REFERENCES tipos_eleccion(id),
    CONSTRAINT FK_procesoselectorales_caracter FOREIGN KEY (caracter) REFERENCES caracter_eleccion(id)
);

CREATE TABLE usuarios_tipos (
    id                                      SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                                  VARCHAR(20) NOT NULL UNIQUE,
    tipo                                    VARCHAR(30) NOT NULL
);

INSERT INTO usuarios_tipos(codigo, tipo) VALUES('superadmin', 'Super Administrador'),
                                                    ('administrador', 'Administrador'),
                                                    ('coordinador-general', 'Coordinador General'),
                                                    ('coordinador-zona', 'Coordinador de Zona'),
                                                    ('coordinador', 'Coordinador'),
                                                    ('supervisor', 'Supervisor'),
                                                    ('promotor', 'Promotor'),
                                                    ('capturador', 'Capturador');

CREATE TABLE usuarios (
    id                                      INT AUTO_INCREMENT PRIMARY KEY,
    uuid                                    BINARY(16) NOT NULL UNIQUE,
    organizacion                            INT DEFAULT NULL,
    usuario                                 VARCHAR(60) NOT NULL,
    email                                   VARCHAR(150) DEFAULT NULL,
    nombre                                  VARCHAR(40) NOT NULL,
    paterno                                 VARCHAR(40) DEFAULT NULL,
    materno                                 VARCHAR(40) DEFAULT NULL,
    password_hash                           VARCHAR(255) NOT NULL,
    tipo_usuario                            SMALLINT NOT NULL,
    activo                                  SMALLINT NOT NULL DEFAULT 1,
    registro                                INT DEFAULT NULL,
    f_registro                              DATETIME NOT NULL,
    f_ultima_conexion                       DATETIME DEFAULT NULL,
    f_actualizacion                         DATETIME DEFAULT NULL,
    CONSTRAINT UK_usuarios_usuario UNIQUE(usuario),
    CONSTRAINT FK_usuarios_organizacion FOREIGN KEY(organizacion) REFERENCES organizaciones(id),
    CONSTRAINT FK_usuarios_tipo FOREIGN KEY(tipo_usuario) REFERENCES usuarios_tipos(id),
    CONSTRAINT FK_usuarios_registro FOREIGN KEY(registro) REFERENCES usuarios(id)
);

ALTER TABLE organizaciones ADD CONSTRAINT FK_organizaciones_registro FOREIGN KEY(registro) REFERENCES usuarios(id);
ALTER TABLE procesos_electorales ADD CONSTRAINT FK_procesoselectorales_registro FOREIGN KEY (registro) REFERENCES usuarios(id);

INSERT INTO usuarios(uuid, usuario, email, nombre, paterno, materno, password_hash, tipo_usuario, activo, f_registro) 
                VALUES(X'C475E751FD1547DDA8500D566F200F24', 'administrador', 'eliverperez90@gmail.com', 'Eliver', 'Perez', 'Villegas', '$2y$11$0zrw9InCEmkiJAKYKcrBo.Y.MQ6eoy60E8sdO/bcdltHcsaa54W4u', 1, 1, NOW());

CREATE TABLE usuarios_procesos (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    proceso                         INT NOT NULL,
    usuario                         INT NOT NULL,
    tipo_usuario                    SMALLINT NOT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1,
    f_registro                      DATETIME NOT NULL,
    f_baja                          DATETIME DEFAULT NULL,
    CONSTRAINT UK_usuarioproceso UNIQUE(proceso, usuario),
    CONSTRAINT FK_usuariosprocesos_proceso FOREIGN KEY (proceso) REFERENCES procesos_electorales(id),
    CONSTRAINT FK_usuariosprocesos_usuario FOREIGN KEY (usuario) REFERENCES usuarios(id),
    CONSTRAINT FK_usuariosprocesos_tipousuario FOREIGN KEY (tipo_usuario) REFERENCES usuarios_tipos(id)
);

CREATE TABLE usuarios_sesiones (
    id                              BINARY(16) PRIMARY KEY,
    usuario                         INT NOT NULL,

    token_hash                      BINARY(32) NOT NULL,

    f_registro                      DATETIME NOT NULL,
    ultima_actividad                DATETIME NOT NULL,
    expira_en                       DATETIME NOT NULL,
    destruida_en                    DATETIME NULL,

    ip                              VARCHAR(255),
    user_agent                      VARCHAR(255),
    dispositivo                     VARCHAR(255),

    motivo_cierre                   VARCHAR(255),

    CONSTRAINT FK_usuariossesiones_usuario FOREIGN KEY (usuario) REFERENCES usuarios(id)
);

CREATE TABLE permisos (
    id                              VARCHAR(30) PRIMARY KEY,
    permiso                         VARCHAR(255) NOT NULL,
    descripcion                     VARCHAR(1024) DEFAULT NULL,
    f_registro                      DATETIME NOT NULL
);

INSERT INTO permisos(id, permiso, f_registro) VALUES('superadmin', 'Administrador con permisos elevados.', NOW()),
                                                    ('admin', 'Usuario administrador', NOW()),
                                                    ('coordinador-general', 'Coordinador general', NOW()),
                                                    ('captura-personas', 'Capturador', NOW());

CREATE TABLE permisos_usuarios (
    uuid                            BINARY(16) NOT NULL UNIQUE,
    permiso                         VARCHAR(30) NOT NULL,
    usuario                         INT NOT NULL,
    organizacion                    INT DEFAULT NULL,
    valor                           SMALLINT NOT NULL DEFAULT 1,
    f_actualizacion                 DATETIME NOT NULL,
    CONSTRAINT FK_permisosusuarios_permiso FOREIGN KEY(permiso) REFERENCES permisos(id),
    CONSTRAINT FK_permisosusuarios_usuario FOREIGN KEY(usuario) REFERENCES usuarios(id),
    CONSTRAINT FK_permisosusuarios_sucursal FOREIGN KEY(organizacion) REFERENCES organizaciones(id)
);

INSERT INTO permisos_usuarios(permiso, usuario, uuid, valor, f_actualizacion) VALUES('superadmin', 1, X'A8DA1C3FF8EB4F4C9AD57350B8984EE1', 1, NOW());

CREATE TABLE permisos_usuarios_tipo (
    uuid                            BINARY(16) NOT NULL UNIQUE,
    permiso                         VARCHAR(30) NOT NULL,
    tipo                            SMALLINT NOT NULL,
    valor                           SMALLINT NOT NULL DEFAULT 1,
    f_actualizacion                 DATETIME NOT NULL,
    CONSTRAINT FK_permisosusuariostipo_permiso FOREIGN KEY(permiso) REFERENCES permisos(id),
    CONSTRAINT FK_permisosusuariostipo_tipo FOREIGN KEY(tipo) REFERENCES usuarios_tipos(id)
);

INSERT INTO permisos_usuarios_tipo(permiso, tipo, uuid, valor, f_actualizacion) VALUES('superadmin', 1, X'E8DABC3FFEEB444C9AD57350B8984EE1', 1, NOW());
INSERT INTO permisos_usuarios_tipo(permiso, tipo, uuid, valor, f_actualizacion) VALUES('coordinador-general', 3, X'b1e75d0d33914a17a795925cf18776a6', 1, NOW());
INSERT INTO permisos_usuarios_tipo(permiso, tipo, uuid, valor, f_actualizacion) VALUES('captura-personas', 8, X'd2e75a31e3914a172bc59259f18776a6', 1, NOW());

CREATE TABLE estructura (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    uuid                            BINARY(16) NOT NULL UNIQUE,
    proceso                         INT NOT NULL,
    usuario                         INT NOT NULL,
    nodo_padre                      INT NULL,
    nivel                           SMALLINT NOT NULL DEFAULT 1,
    nombre_nodo                     VARCHAR(120) NULL,
    activo                          TINYINT NOT NULL DEFAULT 1,
    f_asignacion                    DATETIME NOT NULL,
    f_baja                          DATETIME NULL,
    CONSTRAINT UK_estructura_usuario UNIQUE(proceso, usuario),
    CONSTRAINT FK_estructura_proceso FOREIGN KEY (proceso) REFERENCES procesos_electorales(id),
    CONSTRAINT FK_estructura_usuario FOREIGN KEY (usuario) REFERENCES usuarios(id),
    CONSTRAINT FK_estructura_nodopadre FOREIGN KEY (nodo_padre) REFERENCES estructura(id)
);

CREATE TABLE estructura_relaciones (
    ancestro                        INT NOT NULL,
    descendiente                    INT NOT NULL,
    profundidad                     SMALLINT NOT NULL,
    CONSTRAINT PK_estructurarelaciones PRIMARY KEY (ancestro, descendiente),
    CONSTRAINT FK_estructurarelaciones_ancestro FOREIGN KEY (ancestro) REFERENCES estructura(id),
    CONSTRAINT FK_estructurarelaciones_descendiente FOREIGN KEY (descendiente) REFERENCES estructura(id)
);

CREATE TABLE estados (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    estado                          VARCHAR(100) NOT NULL
);

CREATE TABLE municipios (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    estado                          SMALLINT NOT NULL,
    clave                           VARCHAR(10) NULL,
    municipio                       VARCHAR(120) NOT NULL,
    CONSTRAINT FK_municipios_estado FOREIGN KEY (estado)  REFERENCES estados(id)
);

CREATE TABLE localidades (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    municipio                       INT NOT NULL,
    localidad                       VARCHAR(150) NOT NULL,
    codigo_postal                   CHAR(5) NULL,
    CONSTRAINT FK_localidades_municipio FOREIGN KEY (municipio) REFERENCES municipios(id)
);

CREATE TABLE colonias (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    localidad                       INT NOT NULL,
    colonia                         VARCHAR(150) NOT NULL,
    codigo_postal                   CHAR(5) NULL,
    CONSTRAINT FK_colonias_localidad FOREIGN KEY (localidad) REFERENCES localidades(id)
);

CREATE TABLE codigos_postales (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    codigo_postal                   VARCHAR(10) NOT NULL,
    localidad                       INT NOT NULL,
    colonia                         INT NULL,
    CONSTRAINT FK_codigospostales_localidad FOREIGN KEY (localidad) REFERENCES localidades(id),
    CONSTRAINT FK_codigospostales_colonia FOREIGN KEY (colonia) REFERENCES colonias(id)
);

 CREATE TABLE secciones_electorales (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    uuid                            BINARY(16),
    estado                          SMALLINT NOT NULL,
    municipio                       INT NOT NULL,
    seccion                         INT NOT NULL,
    distrito_local                  SMALLINT NULL,
    distrito_federal                SMALLINT NULL,
    registro                        INT NOT NULL,
    f_registro                      DATETIME NOT NULL,
    f_actualizacion                 DATETIME DEFAULT NULL,
    CONSTRAINT UK_seccioneselectorales UNIQUE(uuid),
    CONSTRAINT UK_seccioneselectorales_seccion UNIQUE(estado, seccion),
    CONSTRAINT FK_seccioneselectorales_estado FOREIGN KEY(estado) REFERENCES estados(id),
    CONSTRAINT FK_seccioneselectorales_municipio FOREIGN KEY(municipio) REFERENCES municipios(id),
    CONSTRAINT FK_seccioneselectorales_registro FOREIGN KEY(registro) REFERENCES usuarios(id)
);

CREATE TABLE casillas (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    uuid                            BINARY(16),
    seccion                         INT NOT NULL,
    tipo                            VARCHAR(20) NOT NULL,
    casilla                         VARCHAR(60) NULL,
    ubicacion                       VARCHAR(255) NULL,
    domicilio                       VARCHAR(255) NULL,
    registro                        INT NOT NULL,
    f_registro                      DATETIME NOT NULL,
    f_actualizacion                 DATETIME DEFAULT NULL,
    CONSTRAINT UK_casillas UNIQUE(uuid),
    CONSTRAINT FK_casillas_seccion FOREIGN KEY (seccion) REFERENCES secciones_electorales(id),
    CONSTRAINT FK_casillas_registro FOREIGN KEY(registro) REFERENCES usuarios(id)
);

CREATE TABLE generos (
    id                              VARCHAR(1) PRIMARY KEY,
    codigo                          VARCHAR(20) NOT NULL UNIQUE,
    genero                          VARCHAR(15) NOT NULL
);

INSERT INTO generos(id, codigo, genero) VALUES('N', 'N/D', 'N/D'), ('H', 'hombre', 'Hombre'), ('M', 'mujer', 'Mujer');

CREATE TABLE personas (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    uuid                            BINARY(16) NOT NULL UNIQUE,
    organizacion                    INT NOT NULL,
    seccion                         INT NOT NULL,

    nombre                          VARCHAR(80) NOT NULL,
    paterno                         VARCHAR(80) NULL,
    materno                         VARCHAR(80) NULL,

    f_nacimiento                    DATE NULL,
    genero                          VARCHAR(1) NULL,

    telefono                        VARCHAR(20) NULL,
    telefono_alternativo            VARCHAR(20) NULL,
    email                           VARCHAR(150) NULL,

    ocr                             VARCHAR(30) NULL,
    clave_elector                   VARCHAR(30) NULL,
    curp                            VARCHAR(18) NULL,

    activo                          TINYINT NOT NULL DEFAULT 1,
    f_registro                      DATETIME NOT NULL,
    registrado_por                  INT NOT NULL,
    f_actualizacion                 DATETIME NULL,

    CONSTRAINT FK_personas_organizacion FOREIGN KEY (organizacion) REFERENCES organizaciones(id),
    CONSTRAINT FK_personas_seccion FOREIGN KEY (seccion) REFERENCES secciones_electorales(id),
    CONSTRAINT FK_personas_genero FOREIGN KEY (genero) REFERENCES generos(id),
    CONSTRAINT FK_personas_registradopor FOREIGN KEY (registrado_por) REFERENCES usuarios(id),
    INDEX IDX_personas_telefono(telefono),
    INDEX IDX_personas_nombre(paterno, materno, nombre)
);

CREATE TABLE personas_domicilios (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    persona                         INT NOT NULL,
    calle                           VARCHAR(150) NULL,
    numero_exterior                 VARCHAR(20) NULL,
    numero_interior                 VARCHAR(20) NULL,
    colonia                         INT NULL,
    codigo_postal                   VARCHAR(10) NULL,
    referencias                     VARCHAR(255) NULL,
    latitud                         DECIMAL(10,7) NULL,
    longitud                        DECIMAL(10,7) NULL,
    principal                       TINYINT NOT NULL DEFAULT 1,
    CONSTRAINT FK_personasdomicilios_persona FOREIGN KEY (persona) REFERENCES personas(id),
    CONSTRAINT FK_personasdomicilios_colonia FOREIGN KEY (colonia) REFERENCES colonias(id)
);

CREATE TABLE personas_procesos (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    proceso                         INT NOT NULL,
    persona                         INT NOT NULL,

    seccion                         INT NULL,
    casilla                         INT NULL,

    estatus_contacto_id             SMALLINT NULL,
    nivel_apoyo_id                  SMALLINT NULL,

    requiere_transporte             TINYINT NOT NULL DEFAULT 0,
    puede_ser_representante         TINYINT NOT NULL DEFAULT 0,
    observaciones                   TEXT NULL,

    activo                          TINYINT NOT NULL DEFAULT 1,
    f_registro                      DATETIME NOT NULL,
    f_actualizacion                 DATETIME NULL,

    CONSTRAINT UK_personasprocesos_proceso UNIQUE(proceso, persona),
    CONSTRAINT FK_personasprocesos_proceso FOREIGN KEY (proceso) REFERENCES procesos_electorales(id),
    CONSTRAINT FK_personasprocesos_persona FOREIGN KEY (persona) REFERENCES personas(id),
    CONSTRAINT FK_personasprocesos_seccion FOREIGN KEY (seccion) REFERENCES secciones_electorales(id),
    CONSTRAINT FK_personasprocesos_casilla FOREIGN KEY (casilla) REFERENCES casillas(id)
);

CREATE TABLE personas_responsables (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    persona_proceso                 INT NOT NULL,
    estructura                      INT NOT NULL,
    principal                       TINYINT NOT NULL DEFAULT 1,
    activo                          TINYINT NOT NULL DEFAULT 1,
    f_asignacion                    DATETIME NOT NULL,
    f_baja                          DATETIME NULL,
    asignado_por                    INT NOT NULL,

    CONSTRAINT FK_personasresponsables_proceso FOREIGN KEY (persona_proceso) REFERENCES personas_procesos(id),
    CONSTRAINT FK_personasresponsables_estructura FOREIGN KEY (estructura) REFERENCES estructura(id),
    CONSTRAINT FK_personasresponsables_asignadopor FOREIGN KEY (asignado_por) REFERENCES usuarios(id),
    INDEX IDX_responsable(estructura, activo)
);

CREATE TABLE niveles_apoyo (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(30) NOT NULL UNIQUE,
    nivel                           VARCHAR(80) NOT NULL,
    orden                           SMALLINT NOT NULL
);

INSERT INTO niveles_apoyo
    (codigo, nivel, orden)
VALUES
    ('SEGURO', 'Seguro', 1),
    ('PROBABLE', 'Probable', 2),
    ('INDECISO', 'Indeciso', 3),
    ('POCO_PROBABLE', 'Poco probable', 4),
    ('RECHAZO', 'Rechazo', 5),
    ('NO_DEFINIDO', 'No definido', 6);

CREATE TABLE estatus_contacto (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(30) NOT NULL UNIQUE,
    estatus                         VARCHAR(80) NOT NULL
);

INSERT INTO estatus_contacto
    (codigo, estatus)
VALUES
    ('SIN_CONTACTAR', 'Sin contactar'),
    ('CONTACTADO', 'Contactado'),
    ('VISITA_PENDIENTE', 'Visita pendiente'),
    ('SEGUIMIENTO', 'En seguimiento'),
    ('NO_LOCALIZADO', 'No localizado'),
    ('DATOS_INCORRECTOS', 'Datos incorrectos'),
    ('RECHAZO_CONTACTO', 'Rechazó contacto'),
    ('CONFIRMADO', 'Confirmado');

CREATE TABLE personas_apoyo_historial (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    persona_proceso                 INT NOT NULL,
    nivel_apoyo                     SMALLINT NOT NULL,
    observaciones                   VARCHAR(500) NULL,
    registrado_por                  INT NOT NULL,
    f_registro                      DATETIME NOT NULL,
    CONSTRAINT FK_personasapoyohistorial_personaproceso FOREIGN KEY (persona_proceso) REFERENCES personas_procesos(id),
    CONSTRAINT FK_personasapoyohistorial_nivelapoyo FOREIGN KEY (nivel_apoyo) REFERENCES niveles_apoyo(id),
    CONSTRAINT FK_personasapoyohistorial_registradopor FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
);

CREATE TABLE tipos_interaccion (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(30) NOT NULL UNIQUE,
    tipo                            VARCHAR(80) NOT NULL
);

INSERT INTO tipos_interaccion
    (codigo, tipo)
VALUES
    ('LLAMADA', 'Llamada'),
    ('VISITA', 'Visita'),
    ('WHATSAPP', 'WhatsApp'),
    ('MENSAJE', 'Mensaje'),
    ('EVENTO', 'Evento'),
    ('ENCUESTA', 'Encuesta'),
    ('BRIGADA', 'Registro en brigada'),
    ('OTRO', 'Otro');

CREATE TABLE interacciones (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    persona_proceso                 INT NOT NULL,
    tipo                            SMALLINT NOT NULL,
    usuario                         INT NOT NULL,
    resultado                       VARCHAR(150) NULL,
    notas                           TEXT NULL,
    latitud                         DECIMAL(10,7) NULL,
    longitud                        DECIMAL(10,7) NULL,
    f_interaccion                   DATETIME NOT NULL,
    f_registro                      DATETIME NOT NULL,
    CONSTRAINT FK_interacciones_personaproceso FOREIGN KEY (persona_proceso) REFERENCES personas_procesos(id),
    CONSTRAINT FK_interacciones_tipo FOREIGN KEY (tipo) REFERENCES tipos_interaccion(id),
    CONSTRAINT FK_interacciones_usuario FOREIGN KEY (usuario) REFERENCES usuarios(id),
    INDEX IDX_interacciones_persona (persona_proceso, f_interaccion)
);

CREATE TABLE encuestas (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    proceso                         INT NOT NULL,
    encuesta                        VARCHAR(150) NOT NULL,
    descripcion                     VARCHAR(500) NULL,
    activo                          TINYINT NOT NULL DEFAULT 1,
    CONSTRAINT FK_encuestas_proceso FOREIGN KEY (proceso) REFERENCES procesos_electorales(id)
);

CREATE TABLE encuestas_preguntas_tipos (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(30) NOT NULL,
    tipo                            VARCHAR(80) NOT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1,

    UNIQUE KEY uk_encuestas_preguntas_tipos_codigo (codigo)
);

INSERT INTO encuestas_preguntas_tipos
    (codigo, tipo)
VALUES
    ('TEXTO', 'Texto'),
    ('TEXTO_LARGO', 'Texto largo'),
    ('NUMERO', 'Número'),
    ('SI_NO', 'Sí / No'),
    ('SELECCION_UNICA', 'Selección única'),
    ('SELECCION_MULTIPLE', 'Selección múltiple'),
    ('ESCALA', 'Escala'),
    ('FECHA', 'Fecha');

CREATE TABLE encuestas_preguntas (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    encuesta                        INT NOT NULL,
    pregunta                        VARCHAR(500) NOT NULL,
    tipo                            SMALLINT NOT NULL,
    obligatorio                     TINYINT NOT NULL DEFAULT 0,
    orden                           SMALLINT NOT NULL,
    CONSTRAINT FK_encuestaspreguntas_encuesta FOREIGN KEY (encuesta) REFERENCES encuestas(id),
    CONSTRAINT FK_encuestaspreguntas_tipo FOREIGN KEY (tipo) REFERENCES encuestas_preguntas_tipos(id)
);

CREATE TABLE encuestas_opciones (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    pregunta                        INT NOT NULL,
    opcion                          VARCHAR(200) NOT NULL,
    valor                           VARCHAR(100) NULL,
    orden                           SMALLINT NOT NULL,
    CONSTRAINT FK_encuestasopciones_pregunta FOREIGN KEY (pregunta) REFERENCES encuestas_preguntas(id)
);

CREATE TABLE encuestas_aplicaciones (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    encuesta                        INT NOT NULL,
    persona_proceso                 INT NOT NULL,
    aplicado_por                    INT NOT NULL,
    f_aplicacion                    DATETIME NOT NULL,
    CONSTRAINT FK_encuestasaplicaciones_encuesta FOREIGN KEY (encuesta) REFERENCES encuestas(id),
    CONSTRAINT FK_encuestasaplicaciones_personaproceso FOREIGN KEY (persona_proceso) REFERENCES personas_procesos(id),
    CONSTRAINT FK_encuestasaplicaciones_aplicadopor FOREIGN KEY (aplicado_por) REFERENCES usuarios(id)
);

CREATE TABLE encuestas_respuestas (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    aplicacion                      INT NOT NULL,
    pregunta                        INT NOT NULL,
    opcion                          INT NULL,
    respuesta                       TEXT NULL,
    CONSTRAINT FK_encuestasrespuestas_aplicacion FOREIGN KEY (aplicacion) REFERENCES encuestas_aplicaciones(id),
    CONSTRAINT FK_encuestasrespuestas_pregunta FOREIGN KEY (pregunta) REFERENCES encuestas_preguntas(id),
    CONSTRAINT FK_encuestasrespuestas_opcion FOREIGN KEY (opcion) REFERENCES encuestas_opciones(id)
);

CREATE TABLE metas_tipos (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(30) NOT NULL UNIQUE,
    tipo                            VARCHAR(80) NOT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1
);

INSERT INTO metas_tipos (codigo, tipo) VALUES
    ('REGISTROS', 'Personas registradas'),
    ('CONTACTOS', 'Personas contactadas'),
    ('VISITAS', 'Visitas realizadas'),
    ('ENCUESTAS', 'Encuestas aplicadas'),
    ('CONFIRMADOS', 'Personas confirmadas'),
    ('EVENTOS', 'Asistencias a eventos');

CREATE TABLE metas (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    proceso                         INT NOT NULL,
    estructura                      INT NOT NULL,
    tipo                            SMALLINT NOT NULL,
    cantidad                        INT NOT NULL,
    fecha_inicio                    DATE NOT NULL,
    fecha_fin                       DATE NOT NULL,
    asignado_por                    INT NOT NULL,
    CONSTRAINT FK_metas_proceso FOREIGN KEY (proceso) REFERENCES procesos_electorales(id),
    CONSTRAINT FK_metas_estructura FOREIGN KEY (estructura) REFERENCES estructura(id),
    CONSTRAINT FK_metas_tipo FOREIGN KEY (tipo) REFERENCES metas_tipos(id),
    CONSTRAINT FK_metas_asignadopor FOREIGN KEY (asignado_por) REFERENCES usuarios(id)
);

CREATE TABLE tareas_prioridades (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(20) NOT NULL UNIQUE,
    prioridad                       VARCHAR(50) NOT NULL,
    orden                           SMALLINT NOT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1
);

INSERT INTO tareas_prioridades
    (codigo, prioridad, orden)
VALUES
    ('BAJA', 'Baja', 1),
    ('NORMAL', 'Normal', 2),
    ('ALTA', 'Alta', 3),
    ('URGENTE', 'Urgente', 4);

CREATE TABLE tareas_estatus (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(30) NOT NULL UNIQUE,
    estatus                         VARCHAR(60) NOT NULL,
    orden                           SMALLINT NOT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1
);

INSERT INTO tareas_estatus
    (codigo, estatus, orden)
VALUES
    ('PENDIENTE', 'Pendiente', 1),
    ('EN_PROCESO', 'En proceso', 2),
    ('COMPLETADA', 'Completada', 3),
    ('CANCELADA', 'Cancelada', 4);

CREATE TABLE tareas (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    proceso                         INT NOT NULL,
    persona_proceso                 INT NULL,
    asignado_a                      INT NOT NULL,
    creado_por                      INT NOT NULL,
    titulo                          VARCHAR(200) NOT NULL,
    descripcion                     TEXT NULL,
    prioridad                       SMALLINT NULL,
    estatus                         SMALLINT NOT NULL,
    fecha_limite                    DATETIME NULL,
    f_registro                      DATETIME NOT NULL,
    f_actualizacion                 DATETIME NULL,
    f_finalizacion                  DATETIME NULL,
    CONSTRAINT FK_tareas_proceso FOREIGN KEY (proceso) REFERENCES procesos_electorales(id),
    CONSTRAINT FK_tareas_personasproceso FOREIGN KEY (persona_proceso) REFERENCES personas_procesos(id),
    CONSTRAINT FK_tareas_asignadopor FOREIGN KEY (asignado_a) REFERENCES usuarios(id),
    CONSTRAINT FK_tareas_prioridad FOREIGN KEY (prioridad) REFERENCES tareas_prioridades(id),
    CONSTRAINT FK_tareas_estatus FOREIGN KEY (estatus) REFERENCES tareas_estatus(id),
    CONSTRAINT FK_tareas_creadopor FOREIGN KEY (creado_por) REFERENCES usuarios(id)
);

CREATE TABLE conversaciones_tipos (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(30) NOT NULL UNIQUE,
    tipo                            VARCHAR(80) NOT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1
);

INSERT INTO conversaciones_tipos
    (codigo, tipo)
VALUES
    ('DIRECTA', 'Conversación directa'),
    ('GRUPO', 'Conversación grupal'),
    ('ESTRUCTURA', 'Conversación de estructura');

CREATE TABLE conversaciones (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    uuid                            BINARY(16) NOT NULL UNIQUE,
    proceso                         INT NOT NULL,
    tipo                            SMALLINT NOT NULL,
    titulo                          VARCHAR(150) NULL,
    creado_por                      INT NOT NULL,
    f_registro                      DATETIME NOT NULL,
    CONSTRAINT FK_conversaciones_proceso FOREIGN KEY (proceso) REFERENCES procesos_electorales(id),
    CONSTRAINT FK_conversaciones_tipo FOREIGN KEY (tipo) REFERENCES conversaciones_tipos(id),
    CONSTRAINT FK_conversaciones_creadopor FOREIGN KEY (creado_por) REFERENCES usuarios(id)
);

CREATE TABLE conversaciones_usuarios (
    conversacion                    INT NOT NULL,
    usuario                         INT NOT NULL,
    ultimo_mensaje_leido            INT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1,
    CONSTRAINT PK_conversacionesusuarios PRIMARY KEY (conversacion, usuario)
);

CREATE TABLE mensajes (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    conversacion                    INT NOT NULL,
    usuario                         INT NOT NULL,
    mensaje                         TEXT NOT NULL,
    f_envio                         DATETIME NOT NULL,
    editado                         TINYINT NOT NULL DEFAULT 0,
    eliminado                       TINYINT NOT NULL DEFAULT 0,
    CONSTRAINT FK_mensajes_conversacion FOREIGN KEY (conversacion) REFERENCES conversaciones(id),
    CONSTRAINT FK_mensajes_usuario FOREIGN KEY (usuario) REFERENCES usuarios(id),
    INDEX IDX_mensajes_conversacion (conversacion, id)
);

CREATE TABLE canales_contacto_tipos (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(30) NOT NULL UNIQUE,
    tipo                            VARCHAR(80) NOT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1
);

INSERT INTO canales_contacto_tipos
    (codigo, tipo)
VALUES
    ('TELEFONO', 'Teléfono'),
    ('WHATSAPP', 'WhatsApp'),
    ('SMS', 'SMS'),
    ('EMAIL', 'Correo electrónico');

CREATE TABLE canales_contacto (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    persona                         INT NOT NULL,
    tipo                            SMALLINT NOT NULL,
    valor                           VARCHAR(150) NOT NULL,
    principal                       TINYINT NOT NULL DEFAULT 0,
    verificado                      TINYINT NOT NULL DEFAULT 0,
    permite_contacto                TINYINT NOT NULL DEFAULT 0,
    f_consentimiento                DATETIME NULL,
    CONSTRAINT FK_canalescontacto_persona FOREIGN KEY (persona) REFERENCES personas(id),
    CONSTRAINT FK_canalescontacto_tipo FOREIGN KEY (tipo) REFERENCES canales_contacto_tipos(id)
);

CREATE TABLE campanas_mensajes_estatus (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(30) NOT NULL UNIQUE,
    estatus                         VARCHAR(80) NOT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1
);

INSERT INTO campanas_mensajes_estatus
    (codigo, estatus)
VALUES
    ('BORRADOR', 'Borrador'),
    ('PROGRAMADA', 'Programada'),
    ('ENVIANDO', 'Enviando'),
    ('FINALIZADA', 'Finalizada'),
    ('CANCELADA', 'Cancelada'),
    ('ERROR', 'Error');

CREATE TABLE campanas_mensajes (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    proceso                         INT NOT NULL,
    nombre                          VARCHAR(150) NOT NULL,
    canal                           VARCHAR(30) NOT NULL,
    contenido                       TEXT NOT NULL,
    estatus                         SMALLINT NOT NULL,
    creado_por                      INT NOT NULL,
    programado_para                 DATETIME NULL,
    f_registro                      DATETIME NOT NULL,
    CONSTRAINT FK_campanasmensajes_proceso FOREIGN KEY(proceso) REFERENCES procesos_electorales(id),
    CONSTRAINT FK_campanasmensajes_estatus FOREIGN KEY(estatus) REFERENCES campanas_mensajes_estatus(id)
);

CREATE TABLE campanas_destinatarios_estatus (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(30) NOT NULL UNIQUE,
    estatus                         VARCHAR(80) NOT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1
);

INSERT INTO campanas_destinatarios_estatus
    (codigo, estatus)
VALUES
    ('PENDIENTE', 'Pendiente'),
    ('ENVIADO', 'Enviado'),
    ('ENTREGADO', 'Entregado'),
    ('LEIDO', 'Leído'),
    ('FALLIDO', 'Fallido'),
    ('CANCELADO', 'Cancelado');

CREATE TABLE campanas_destinatarios (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    campana                         INT NOT NULL,
    persona_proceso                 INT NOT NULL,
    estatus                         SMALLINT NOT NULL,
    id_externo                      VARCHAR(150) NULL,
    f_envio                         DATETIME NULL,
    f_entrega                       DATETIME NULL,
    f_lectura                       DATETIME NULL,
    error                           VARCHAR(500) NULL,
    UNIQUE KEY UK_campana_persona (campana, persona_proceso),
    CONSTRAINT FK_campanasdestinatarios_campana FOREIGN KEY(campana) REFERENCES campanas_mensajes(id),
    CONSTRAINT FK_campanasdestinatarios_personaproceso FOREIGN KEY(persona_proceso) REFERENCES personas_procesos(id),
    CONSTRAINT FK_campanasdestinatarios_estatus FOREIGN KEY(estatus) REFERENCES campanas_destinatarios_estatus(id)
);

CREATE TABLE incidencias_tipos (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(30) NOT NULL UNIQUE,
    tipo                            VARCHAR(100) NOT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1
);

INSERT INTO incidencias_tipos (codigo, tipo) VALUES
    ('CASILLA', 'Problema en casilla'),
    ('REPRESENTANTE', 'Problema con representante'),
    ('TRANSPORTE', 'Problema de transporte'),
    ('MATERIAL', 'Falta o problema de material'),
    ('SEGURIDAD', 'Incidente de seguridad'),
    ('PERSONA', 'Incidencia relacionada con una persona'),
    ('TERRITORIAL', 'Problema territorial'),
    ('TECNICA', 'Problema técnico'),
    ('OTRA', 'Otra incidencia');

CREATE TABLE incidencias_estatus (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(30) NOT NULL UNIQUE,
    estatus                         VARCHAR(80) NOT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1
);

INSERT INTO incidencias_estatus (codigo, estatus) VALUES
    ('REPORTADA', 'Reportada'),
    ('ASIGNADA', 'Asignada'),
    ('EN_ATENCION', 'En atención'),
    ('RESUELTA', 'Resuelta'),
    ('DESCARTADA', 'Descartada');

CREATE TABLE incidencias (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    proceso                         INT NOT NULL,
    estructura                      INT NULL,
    seccion                         INT NULL,
    casilla                         INT NULL,
    tipo                            SMALLINT NOT NULL,
    descripcion                     TEXT NOT NULL,
    prioridad                       SMALLINT NOT NULL,
    estatus                         SMALLINT NOT NULL,
    reportado_por                   INT NOT NULL,
    asignado_a                      INT NULL,
    f_reporte                       DATETIME NOT NULL,
    f_resolucion                    DATETIME NULL,
    CONSTRAINT FK_incidencias_proceso FOREIGN KEY(proceso) REFERENCES procesos_electorales(id),
    CONSTRAINT FK_incidencias_estructura FOREIGN KEY(estructura) REFERENCES estructura(id),
    CONSTRAINT FK_incidencias_seccion FOREIGN KEY(seccion) REFERENCES secciones_electorales(id),
    CONSTRAINT FK_incidencias_casilla FOREIGN KEY(casilla) REFERENCES casillas(id),
    CONSTRAINT FK_incidencias_tipo FOREIGN KEY(tipo) REFERENCES incidencias_tipos(id),
    CONSTRAINT FK_incidencias_prioridad FOREIGN KEY(prioridad) REFERENCES tareas_prioridades(id),
    CONSTRAINT FK_incidencias_estatus FOREIGN KEY(estatus) REFERENCES incidencias_estatus(id),
    CONSTRAINT FK_incidencias_reportadopor FOREIGN KEY(reportado_por) REFERENCES usuarios(id),
    CONSTRAINT FK_incidencias_asignadoa FOREIGN KEY(asignado_a) REFERENCES usuarios(id)
);

CREATE TABLE eventos (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    proceso                         INT NOT NULL,
    evento                          VARCHAR(180) NOT NULL,
    descripcion                     TEXT NULL,
    lugar                           VARCHAR(255) NULL,
    latitud                         DECIMAL(10,7) NULL,
    longitud                        DECIMAL(10,7) NULL,
    fecha_inicio                    DATETIME NOT NULL,
    fecha_fin                       DATETIME NULL,
    creado_por                      INT NOT NULL,
    CONSTRAINT FK_eventos_proceso FOREIGN KEY(proceso) REFERENCES procesos_electorales(id),
    CONSTRAINT FK_eventos_creadopor FOREIGN KEY(creado_por) REFERENCES usuarios(id)
);

CREATE TABLE eventos_asistentes (
    evento                          INT NOT NULL,
    persona_proceso                 INT NOT NULL,
    invitado                        TINYINT NOT NULL DEFAULT 1,
    confirmado                      TINYINT NOT NULL DEFAULT 0,
    asistio                         TINYINT NOT NULL DEFAULT 0,
    registrado_por                  INT NOT NULL,
    CONSTRAINT PK_eventosasistentes PRIMARY KEY (evento, persona_proceso),
    CONSTRAINT FK_eventosasistentes_evento FOREIGN KEY(evento) REFERENCES eventos(id),
    CONSTRAINT FK_eventosasistentes_personaproceso FOREIGN KEY(persona_proceso) REFERENCES personas_procesos(id)
);

CREATE TABLE consentimientos_tipos (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(40) NOT NULL UNIQUE,
    tipo                            VARCHAR(120) NOT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1
);

INSERT INTO consentimientos_tipos (codigo, tipo) VALUES
    ('DATOS_PERSONALES', 'Tratamiento de datos personales'),
    ('WHATSAPP', 'Contacto por WhatsApp'),
    ('SMS', 'Contacto por SMS'),
    ('LLAMADAS', 'Contacto por llamadas'),
    ('EMAIL', 'Contacto por correo electrónico'),
    ('GEOLOCALIZACION', 'Uso de geolocalización'),
    ('ENCUESTAS', 'Participación en encuestas');

CREATE TABLE consentimientos_medios (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(30) NOT NULL UNIQUE,
    medio                           VARCHAR(80) NOT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1
);

INSERT INTO consentimientos_medios (codigo, medio) VALUES
    ('APP', 'Aplicación'),
    ('WEB', 'Sitio web'),
    ('PAPEL', 'Documento físico'),
    ('WHATSAPP', 'WhatsApp'),
    ('VERBAL', 'Consentimiento verbal'),
    ('OTRO', 'Otro');

CREATE TABLE consentimientos (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    persona                         INT NOT NULL,
    tipo                            SMALLINT NOT NULL,
    otorgado                        TINYINT NOT NULL,
    medio                           SMALLINT NULL,
    evidencia                       VARCHAR(255) NULL,
    texto_version                   VARCHAR(30) NULL,
    registrado_por                  INT NULL,
    f_registro                      DATETIME NOT NULL,
    CONSTRAINT FK_consentimientos_persona FOREIGN KEY(persona) REFERENCES personas(id),
    CONSTRAINT FK_consentimientos_tipo FOREIGN KEY(tipo) REFERENCES consentimientos_tipos(id),
    CONSTRAINT FK_consentimientos_medio FOREIGN KEY(medio) REFERENCES consentimientos_medios(id),
    CONSTRAINT FK_consentimientos_registradopor FOREIGN KEY(registrado_por) REFERENCES usuarios(id)
);

CREATE TABLE archivos_tipos (
    id                              SMALLINT AUTO_INCREMENT PRIMARY KEY,
    codigo                          VARCHAR(30) NOT NULL UNIQUE,
    tipo                            VARCHAR(80) NOT NULL,
    activo                          TINYINT NOT NULL DEFAULT 1
);

INSERT INTO archivos_tipos (codigo, tipo) VALUES
    ('IMAGEN', 'Imagen'),
    ('DOCUMENTO', 'Documento'),
    ('AUDIO', 'Audio'),
    ('VIDEO', 'Video'),
    ('EVIDENCIA', 'Evidencia'),
    ('OTRO', 'Otro');

CREATE TABLE archivos (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    uuid                            BINARY(16) NOT NULL UNIQUE,
    organizacion                    INT NOT NULL,
    entidad                         VARCHAR(80) NOT NULL,
    entidad_id                      INT NOT NULL,
    tipo                            SMALLINT NULL,
    nombre_original                 VARCHAR(255) NOT NULL,
    ruta                            VARCHAR(500) NOT NULL,
    mime_type                       VARCHAR(100) NULL,
    tamanio                         INT NULL,
    subido_por                      INT NOT NULL,
    f_registro                      DATETIME NOT NULL,
    CONSTRAINT FK_archivos_organization FOREIGN KEY(organizacion) REFERENCES organizaciones(id),
    CONSTRAINT FK_archivos_tipo FOREIGN KEY(tipo) REFERENCES archivos_tipos(id),
    CONSTRAINT FK_archivos_subidopor FOREIGN KEY(subido_por) REFERENCES usuarios(id)
);

CREATE TABLE auditoria (
    id                              INT AUTO_INCREMENT PRIMARY KEY,
    organizacion                    INT NOT NULL,
    proceso                         INT NULL,
    usuario                         INT NULL,
    accion                          VARCHAR(50) NOT NULL,
    entidad                         VARCHAR(80) NOT NULL,
    entidad_id                      INT NULL,
    datos_anteriores                JSON NULL,
    datos_nuevos                    JSON NULL,
    ip                              VARCHAR(45) NULL,
    user_agent                      VARCHAR(500) NULL,
    f_registro                      DATETIME NOT NULL,
    INDEX IDX_auditoria_entidad (entidad, entidad_id),
    INDEX IDX_auditoria_usuario (usuario, f_registro),
    CONSTRAINT FK_auditoria_organizacion FOREIGN KEY(organizacion) REFERENCES organizaciones(id),
    CONSTRAINT FK_auditoria_proceso FOREIGN KEY(proceso) REFERENCES procesos_electorales(id),
    CONSTRAINT FK_auditoria_usuario FOREIGN KEY(usuario) REFERENCES usuarios(id)
);