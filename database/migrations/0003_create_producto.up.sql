CREATE TABLE producto (
    id_producto   INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    id_categoria  INT UNSIGNED   NULL,
    nombre        VARCHAR(150)   NOT NULL,
    descripcion   TEXT           NULL,
    precio_actual DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
    estado_activo TINYINT(1)     NOT NULL DEFAULT 1,
    created_at    DATETIME       NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at    DATETIME       NOT NULL DEFAULT (UTC_TIMESTAMP()),
    PRIMARY KEY (id_producto),
    CONSTRAINT fk_producto_categoria FOREIGN KEY (id_categoria) REFERENCES categoria (id_categoria) ON DELETE RESTRICT,
    CONSTRAINT chk_producto_precio CHECK (precio_actual >= 0.00),
    CONSTRAINT chk_producto_activo CHECK (estado_activo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
