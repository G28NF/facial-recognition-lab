<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;

$root = require __DIR__.'/bootstrap.php';
$connection = new PdoConnection(require $root.'/config/database.php');
$pdo = $connection->pdo();
if (!str_starts_with((string) $pdo->query('SELECT VERSION()')->fetchColumn(), '8.4.')) {
    throw new RuntimeException('A integracao requer MySQL 8.4 LTS.');
}

$tables = ['architectures', 'identities', 'biometric_models', 'embeddings', 'datasets', 'experiments', 'video_sessions', 'recognition_events', 'execution_metrics', 'probe_evaluations', 'probe_evaluation_results', 'webcam_evaluation_segments', 'webcam_evaluation_results'];
$placeholders = implode(',', array_fill(0, count($tables), '?'));
$statement = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})");
$statement->execute($tables);
if ((int) $statement->fetchColumn() !== count($tables)) {
    throw new RuntimeException('Esquema MySQL da V2 incompleto.');
}
if ((int) $pdo->query('SELECT COUNT(*) FROM architectures')->fetchColumn() !== 2) {
    throw new RuntimeException('Arquiteturas iniciais inesperadas.');
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

echo "OK - PDO MySQL 8.4, esquema, transacao e rollback\n";
