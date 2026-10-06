ALTER TABLE ubicacion
    ADD COLUMN id_almacen INT UNSIGNED NULL AFTER id_ubicacion,
    ADD CONSTRAINT fk_ubicacion_almacen FOREIGN KEY (id_almacen) REFERENCES almacen (id_almacen) ON DELETE RESTRICT;
