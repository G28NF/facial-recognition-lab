<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Database\MigrationRunner;

$root = require dirname(__DIR__).'/app/Core/Bootstrap.php';
$runner = new MigrationRunner((new PdoConnection(require $root.'/config/database.php'))->pdo(), $root.'/database/migrations');
foreach ($runner->run() as $version) echo "Aplicada: {$version}\n";
