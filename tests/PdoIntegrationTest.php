<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;

$root = require __DIR__.'/bootstrap.php';
$connection = new PdoConnection(require $root.'/config/database.php');
$pdo = $connection->pdo();
$serverVersion = strtolower((string) $pdo->query('SELECT VERSION()')->fetchColumn());
$serverComment = strtolower((string) $pdo->query('SELECT @@version_comment')->fetchColumn());
$isMysqlFamily = str_contains($serverVersion, 'mysql') || str_contains($serverVersion, 'mariadb')
    || str_contains($serverComment, 'mysql') || str_contains($serverComment, 'mariadb');
if (!$isMysqlFamily) {
    throw new RuntimeException('A integracao requer MySQL ou MariaDB.');
}

$tables = ['schema_migrations', 'migration_runs', 'architectures', 'identities', 'biometric_models', 'embeddings', 'datasets', 'experiments', 'video_sessions', 'recognition_events', 'execution_metrics', 'dataset_imports', 'dataset_import_identities', 'dataset_samples', 'dataset_sample_embeddings', 'dataset_identity_source_links', 'dataset_integrity_findings', 'probe_evaluations', 'probe_evaluation_results', 'webcam_evaluation_segments', 'webcam_evaluation_results'];
$placeholders = implode(',', array_fill(0, count($tables), '?'));
$statement = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})");
$statement->execute($tables);
if ((int) $statement->fetchColumn() !== count($tables)) {
    throw new RuntimeException('Esquema MySQL da V2 incompleto.');
}
if ((int) $pdo->query('SELECT COUNT(*) FROM architectures')->fetchColumn() !== 2) {
    throw new RuntimeException('Arquiteturas iniciais inesperadas.');
}
$collations = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ({$placeholders}) AND table_collation = 'utf8mb4_unicode_ci'");
$collations->execute($tables);
if ((int) $collations->fetchColumn() !== count($tables)) {
    throw new RuntimeException('Collation MySQL/MariaDB inesperada no esquema da V2.');
}

$connection->transaction(static function (PDO $pdo): void {
    $pdo->exec("INSERT INTO datasets (name, description) VALUES ('rfv2-transaction-test', 'temporary') ON DUPLICATE KEY UPDATE description = VALUES(description)");
});
$pdo->beginTransaction();
$pdo->exec("DELETE FROM datasets WHERE name = 'rfv2-transaction-test'");
$pdo->rollBack();
if ((int) $pdo->query("SELECT COUNT(*) FROM datasets WHERE name = 'rfv2-transaction-test'")->fetchColumn() !== 1) {
    throw new RuntimeException('Rollback MySQL falhou.');
}
$pdo->exec("DELETE FROM datasets WHERE name = 'rfv2-transaction-test'");

echo "OK - PDO MySQL/MariaDB, esquema, collation, transacao e rollback\n";
