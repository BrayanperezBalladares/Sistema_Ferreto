CREATE TABLE inventario_stock (
    id_stock     INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    id_producto  INT UNSIGNED   NOT NULL,
    id_ubicacion INT UNSIGNED   NOT NULL,
    cantidad     DECIMAL(12, 3) NOT NULL DEFAULT 0.000,
    created_at   DATETIME       NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at   DATETIME       NOT NULL DEFAULT (UTC_TIMESTAMP()),
    PRIMARY KEY (id_stock),
    CONSTRAINT fk_stock_producto FOREIGN KEY (id_producto) REFERENCES producto (id_producto) ON DELETE RESTRICT,
    CONSTRAINT fk_stock_ubicacion FOREIGN KEY (id_ubicacion) REFERENCES ubicacion (id_ubicacion) ON DELETE RESTRICT,
    CONSTRAINT uq_stock_producto_ubicacion UNIQUE (id_producto, id_ubicacion),
    CONSTRAINT chk_stock_cantidad CHECK (cantidad >= 0.000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
