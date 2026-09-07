CREATE TABLE ubicacion (
    id_ubicacion  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo        VARCHAR(50)  NOT NULL,
    descripcion   TEXT         NULL,
    estado_activo TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at    DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
    PRIMARY KEY (id_ubicacion),
    CONSTRAINT uk_ubicacion_codigo UNIQUE (codigo),
    CONSTRAINT chk_ubicacion_activo CHECK (estado_activo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
