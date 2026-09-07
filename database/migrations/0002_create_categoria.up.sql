CREATE TABLE categoria (
    id_categoria INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre       VARCHAR(100) NOT NULL,
    descripcion  TEXT         NULL,
    created_at   DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at   DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
    PRIMARY KEY (id_categoria),
    CONSTRAINT uk_categoria_nombre UNIQUE (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
