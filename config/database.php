<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'host' => Env::require('RFV2_DB_HOST'),
    'port' => Env::require('RFV2_DB_PORT'),
    'database' => Env::require('RFV2_DB_NAME'),
    'charset' => Env::get('RFV2_DB_CHARSET', 'utf8mb4'),
    'user' => Env::require('RFV2_DB_USER'),
    'password' => Env::require('RFV2_DB_PASSWORD'),
];
