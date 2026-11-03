<?php
declare(strict_types=1);

use App\Core\Env;

$host = Env::get('RFV2_PYTHON_HOST', '127.0.0.1');
$port = Env::get('RFV2_PYTHON_PORT', '8021');
if (!in_array($host, ['127.0.0.1', 'localhost'], true) || filter_var($port, FILTER_VALIDATE_INT) === false) {
    throw new RuntimeException('Configuracao do servico Python invalida.');
}

return [
    'base_url' => sprintf('http://%s:%d', $host, (int) $port),
    'token' => Env::require('RFV2_PYTHON_TOKEN'),
    'timeout_seconds' => (int) Env::get('RFV2_PYTHON_TIMEOUT_SECONDS', '15'),
];
