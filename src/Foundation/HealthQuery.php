<?php

declare(strict_types=1);

namespace App\Foundation;

/**
 * Concept-free database availability proof.
 *
 * Issues a single parameterized SELECT to verify that the connection is live.
 * No application tables are touched. No SQL string concatenation is used.
 */
final class HealthQuery
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Returns true when the database responds to a parameterized probe query.
     */
    public function ping(): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT :probe AS probe');
        $stmt->bindValue(':probe', '1');
        $stmt->execute();

        $row = $stmt->fetch();
        return is_array($row) && isset($row['probe']);
    }
}
