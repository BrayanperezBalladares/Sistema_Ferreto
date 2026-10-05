CREATE TABLE sucursal (
    id_sucursal   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo        VARCHAR(30)  NOT NULL,
    nombre        VARCHAR(100) NOT NULL,
    ciudad        VARCHAR(100) NOT NULL,
    direccion     VARCHAR(255) NULL,
    telefono      VARCHAR(30)  NULL,
    estado_activo TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at    DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
    PRIMARY KEY (id_sucursal),
    CONSTRAINT uq_sucursal_codigo UNIQUE (codigo),
    CONSTRAINT chk_sucursal_activo CHECK (estado_activo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
