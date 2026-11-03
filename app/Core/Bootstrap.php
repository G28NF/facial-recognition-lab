<?php
declare(strict_types=1);

use App\Core\Autoloader;
use App\Core\Env;

$projectRoot = dirname(__DIR__, 2);
require_once __DIR__.'/Autoloader.php';
Autoloader::register($projectRoot);
Env::load($projectRoot.'/.env');

return $projectRoot;
