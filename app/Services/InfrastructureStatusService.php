<?php
declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Database\PdoConnection;
use Throwable;

final class InfrastructureStatusService
{
    public function __construct(private readonly PdoConnection $connection) {}

    /** @return array{database:string,schema_version:?string} */
    public function status(): array
    {
        try {
            $pdo = $this->connection->pdo();
            $version = $pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
            return ['database' => 'disponivel', 'schema_version' => is_string($version) ? $version : null];
        } catch (Throwable) {
            return ['database' => 'indisponivel', 'schema_version' => null];
        }
    }
}
