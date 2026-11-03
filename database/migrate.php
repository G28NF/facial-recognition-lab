<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;

$root = require dirname(__DIR__).'/app/Core/Bootstrap.php';
$pdo = (new PdoConnection(require $root.'/config/database.php'))->pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(64) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci');

$applied = array_fill_keys($pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN), true);
$files = glob($root.'/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);
foreach ($files as $file) {
    $version = pathinfo($file, PATHINFO_FILENAME);
    if (isset($applied[$version])) {
        continue;
    }
    $sql = file_get_contents($file);
    if (!is_string($sql)) {
        throw new RuntimeException("Nao foi possivel ler {$version}.");
    }
    $statements = array_values(array_filter(array_map('trim', explode(';', $sql)), static fn(string $statement): bool => $statement !== ''));
    try {
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        echo "Aplicada: {$version}\n";
    } catch (Throwable $error) {
        throw $error;
    }
}
