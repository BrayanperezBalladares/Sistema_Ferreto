CREATE TABLE conteo_inventario (
    id_conteo INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    id_stock INT UNSIGNED NOT NULL,
    cantidad_sistema DECIMAL(12,3) NOT NULL,
    cantidad_contada DECIMAL(12,3) NOT NULL,
    diferencia DECIMAL(12,3) NOT NULL,
    notas TEXT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_conteo_stock FOREIGN KEY (id_stock) REFERENCES inventario_stock (id_stock) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_conteo_cantidad_sistema CHECK (cantidad_sistema >= 0),
    CONSTRAINT chk_conteo_cantidad_contada CHECK (cantidad_contada >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
