<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\PdoConnection;
use PDO;

final class MysqlDatasetIntegrityRepository
{
    public function __construct(private readonly PdoConnection $connection) {}

    public function recordPossibleDuplicate(int $identityId, int $matchedIdentityId, string $architecture, float $distance): void
    {
        [$left, $right] = $identityId < $matchedIdentityId ? [$identityId, $matchedIdentityId] : [$matchedIdentityId, $identityId];
        $statement = $this->connection->pdo()->prepare('INSERT INTO dataset_integrity_findings
            (identity_id, matched_identity_id, architecture_id, distance_squared, status, created_at)
            VALUES (:identity_id, :matched_identity_id, :architecture, :distance, "open", UTC_TIMESTAMP(3))
            ON DUPLICATE KEY UPDATE distance_squared = VALUES(distance_squared), updated_at = CURRENT_TIMESTAMP');
        $statement->execute([
            'identity_id' => $left, 'matched_identity_id' => $right,
            'architecture' => $architecture, 'distance' => $distance,
        ]);
    }
}
