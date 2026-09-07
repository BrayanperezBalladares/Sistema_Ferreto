<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $stmt = $pdo->query("SHOW TABLES LIKE 'infrastructure_probe'");
    if ($stmt === false || $stmt->fetchColumn() === false) {
        throw new RuntimeException("Required table 'infrastructure_probe' does not exist. Run migrations first.");
    }

    $pdo->exec(
        'INSERT INTO infrastructure_probe (id, created_at) '
        . 'VALUES (1, UTC_TIMESTAMP()) '
        . 'ON DUPLICATE KEY UPDATE id = id'
    );
};
