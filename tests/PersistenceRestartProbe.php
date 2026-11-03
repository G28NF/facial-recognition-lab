<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;

$root = require __DIR__.'/bootstrap.php';
$mode = $argv[1] ?? '';
if (!in_array($mode, ['seed', 'verify'], true)) {
    throw new InvalidArgumentException('Use seed ou verify.');
}

$pdo = (new PdoConnection(require $root.'/config/database.php'))->pdo();
$name = 'rfv2-restart-probe';
if ($mode === 'seed') {
    $statement = $pdo->prepare('INSERT INTO datasets (name, description) VALUES (:name, :description) ON DUPLICATE KEY UPDATE description = VALUES(description)');
    $statement->execute(['name' => $name, 'description' => 'persistence validation']);
    echo "OK - registro de verificacao persistido\n";
    exit;
}

$statement = $pdo->prepare('SELECT COUNT(*) FROM datasets WHERE name = :name');
$statement->execute(['name' => $name]);
if ((int) $statement->fetchColumn() !== 1) {
    throw new RuntimeException('Registro nao persistiu apos reinicio do container.');
}
$pdo->prepare('DELETE FROM datasets WHERE name = :name')->execute(['name' => $name]);
echo "OK - persistencia apos reinicio confirmada\n";
