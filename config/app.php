<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'environment' => Env::get('APP_ENV', 'local'),
    'debug' => filter_var(Env::get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
    'base_url' => rtrim((string) Env::get('APP_BASE_URL', ''), '/'),
];
