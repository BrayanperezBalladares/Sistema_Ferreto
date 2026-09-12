CREATE TABLE usuario (
    id_usuario                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username                  VARCHAR(50)  NOT NULL,
    password_hash             VARCHAR(255) NOT NULL,
    rol                       VARCHAR(30)  NOT NULL,
    estado                    VARCHAR(20)  NOT NULL DEFAULT 'creado',
    failed_attempt_count      INT UNSIGNED NOT NULL DEFAULT 0,
    failure_window_started_at DATETIME     NULL,
    locked_at                 DATETIME     NULL,
    created_at                DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at                DATETIME     NOT NULL DEFAULT (UTC_TIMESTAMP()),
    PRIMARY KEY (id_usuario),
    CONSTRAINT uq_usuario_username UNIQUE (username),
    CONSTRAINT chk_usuario_rol CHECK (rol IN ('administrador', 'cajero', 'bodeguero', 'compras')),
    CONSTRAINT chk_usuario_estado CHECK (estado IN ('creado', 'activo', 'bloqueado', 'inactivo')),
    CONSTRAINT chk_usuario_failed_attempts CHECK (failed_attempt_count >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
