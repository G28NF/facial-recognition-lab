<?php
declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    public function __construct(private readonly PDO $pdo, private readonly string $directory) {}

    /** @return list<string> */
    public function run(): array
    {
        $lock = $this->pdo->prepare('SELECT GET_LOCK(:name, 10)');
        $lock->execute(['name' => 'rfv2.schema_migrations']);
        if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('Outra execucao de migrations esta em andamento.');
        try {
            $this->prepareHistory();
            $files = glob($this->directory.'/*.sql') ?: [];
            sort($files, SORT_STRING);
            $applied = array_fill_keys($this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN), true);
            $runs = $this->pdo->query('SELECT version, checksum, status FROM migration_runs')->fetchAll();
            $history = [];
            foreach ($runs as $run) $history[(string) $run['version']] = $run;
            $executed = [];
            foreach ($files as $file) {
                $version = pathinfo($file, PATHINFO_FILENAME);
                $sql = file_get_contents($file);
                if (!is_string($sql)) throw new RuntimeException("Nao foi possivel ler {$version}.");
                $checksum = hash('sha256', $sql, true);
                if (isset($history[$version])) {
                    if (!hash_equals((string) $history[$version]['checksum'], $checksum)) throw new RuntimeException("Migration aplicada foi alterada: {$version}.");
                    if ($history[$version]['status'] !== 'applied') throw new RuntimeException("Migration interrompida requer revisao manual: {$version}.");
                    if (!isset($applied[$version])) throw new RuntimeException("Historico inconsistente para migration {$version}.");
                    continue;
                }
                if (isset($applied[$version])) {
                    $this->record($version, $checksum, 'applied');
                    continue;
                }
                $this->record($version, $checksum, 'running');
                try {
                    foreach (array_values(array_filter(array_map('trim', explode(';', $sql)), static fn(string $item): bool => $item !== '')) as $statement) {
                        $this->pdo->exec($statement);
                    }
                    if (!isset(array_fill_keys($this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN), true)[$version])) {
                        throw new RuntimeException("Migration {$version} nao registrou sua versao.");
                    }
                    $this->record($version, $checksum, 'applied');
                    $executed[] = $version;
                } catch (Throwable $error) {
                    throw new RuntimeException("Migration interrompida: {$version}. Revise o esquema antes de nova execucao.", 0, $error);
                }
            }
            return $executed;
        } finally {
            $this->pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => 'rfv2.schema_migrations']);
        }
    }

    private function prepareHistory(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) NOT NULL PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS migration_runs (
            version VARCHAR(64) NOT NULL PRIMARY KEY, checksum BINARY(32) NOT NULL, status VARCHAR(16) NOT NULL,
            started_at DATETIME(3) NOT NULL, finished_at DATETIME(3) NULL,
            CONSTRAINT chk_migration_runs_status CHECK (status IN ("running", "applied"))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function record(string $version, string $checksum, string $status): void
    {
        $statement = $this->pdo->prepare('INSERT INTO migration_runs (version, checksum, status, started_at, finished_at)
            VALUES (:version, :checksum, :status, UTC_TIMESTAMP(3), :finished_at)
            ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), status = VALUES(status),
                finished_at = VALUES(finished_at)');
        $statement->bindValue(':version', $version);
        $statement->bindValue(':checksum', $checksum, PDO::PARAM_LOB);
        $statement->bindValue(':status', $status);
        $statement->bindValue(':finished_at', $status === 'applied' ? gmdate('Y-m-d H:i:s.v') : null);
        $statement->execute();
    }
}
