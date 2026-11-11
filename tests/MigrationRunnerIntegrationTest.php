<?php
declare(strict_types=1);

use App\Infrastructure\Database\MigrationRunner;

$root = require __DIR__.'/bootstrap.php';
$config = require $root.'/config/database.php';
$admin = new PDO(sprintf('mysql:host=%s;port=%s;charset=%s', $config['host'], $config['port'], $config['charset']), 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'rfv2_migration_test_'.bin2hex(random_bytes(5));
$directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.$database;
mkdir($directory, 0700);
foreach (glob($root.'/database/migrations/*.sql') ?: [] as $file) copy($file, $directory.DIRECTORY_SEPARATOR.basename($file));

$created = false;
try {
    $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $config['port'], $database, $config['charset']), 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $runner = new MigrationRunner($pdo, $directory);
    $applied = $runner->run();
    if (count($applied) !== 7 || (int) $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() !== 7) throw new RuntimeException('Instalacao nova nao aplicou todas as migrations.');
    if ($runner->run() !== []) throw new RuntimeException('Segunda execucao alterou banco atualizado.');
    $countApplied = $pdo->prepare('SELECT COUNT(*) FROM migration_runs WHERE status = :status');
    $countApplied->execute(['status' => 'applied']);
    $before = (int) $countApplied->fetchColumn();
    file_put_contents($directory.'/001_initial_schema.sql', "\n-- alteracao de teste", FILE_APPEND);
    try { $runner->run(); throw new RuntimeException('Checksum alterado nao foi bloqueado.'); } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'foi alterada')) throw $error;
    }
    $countApplied->execute(['status' => 'applied']);
    if ((int) $countApplied->fetchColumn() !== $before) throw new RuntimeException('Verificacao de checksum modificou o historico.');

    $interrupted = $directory.'-interrupted'; mkdir($interrupted, 0700);
    file_put_contents($interrupted.'/001_interrupt.sql', "CREATE TABLE interruption_probe (id INT NOT NULL);\nINSERT INTO schema_migrations (version) VALUES ('001_interrupt');\nINVALID SQL;");
    try { (new MigrationRunner($pdo, $interrupted))->run(); throw new RuntimeException('Interrupcao nao foi reportada.'); } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'interrompida')) throw $error;
    }
    $interruptedStatus = $pdo->prepare('SELECT status FROM migration_runs WHERE version = :version');
    $interruptedStatus->execute(['version' => '001_interrupt']);
    if ((string) $interruptedStatus->fetchColumn() !== 'running') throw new RuntimeException('Interrupcao DDL nao foi registrada.');
    try { (new MigrationRunner($pdo, $interrupted))->run(); throw new RuntimeException('Migration interrompida foi reaplicada.'); } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'interrompida requer revisao')) throw $error;
    }
    foreach (glob($interrupted.'/*') ?: [] as $file) unlink($file);
    rmdir($interrupted);
} finally {
    if ($created) $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
    foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
    if (is_dir($directory)) rmdir($directory);
}

echo "OK - migrations novas, historico, checksum, idempotencia e interrupcao DDL\n";
