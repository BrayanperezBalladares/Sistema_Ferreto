CREATE TABLE almacen (
    id_almacen    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_sucursal   INT UNSIGNED NOT NULL,
    codigo        VARCHAR(30)  NOT NULL,
    nombre        VARCHAR(100) NOT NULL,
    tipo          VARCHAR(20)  NOT NULL DEFAULT 'bodega',
    estado_activo TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at    DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
    PRIMARY KEY (id_almacen),
    CONSTRAINT uq_almacen_codigo UNIQUE (codigo),
    CONSTRAINT fk_almacen_sucursal FOREIGN KEY (id_sucursal) REFERENCES sucursal (id_sucursal) ON DELETE RESTRICT,
    CONSTRAINT chk_almacen_tipo CHECK (tipo IN ('bodega', 'mostrador', 'patio', 'merma')),
    CONSTRAINT chk_almacen_activo CHECK (estado_activo IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
