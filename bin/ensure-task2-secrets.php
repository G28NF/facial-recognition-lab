<?php
declare(strict_types=1);

$path = dirname(__DIR__).'/.env';
if (!is_file($path)) {
    throw new RuntimeException('Crie o .env antes de executar este utilitario.');
}
$contents = (string) file_get_contents($path);
$additions = [];
if (preg_match('/^RFV2_ACCESS_KEY=/m', $contents) !== 1) {
    $additions[] = 'RFV2_ACCESS_KEY='.bin2hex(random_bytes(24));
}
if (preg_match('/^RFV2_PYTHON_TOKEN=/m', $contents) !== 1) {
    $additions[] = 'RFV2_PYTHON_TOKEN='.bin2hex(random_bytes(32));
}
foreach ([
    'RFV2_FACEAPI_THRESHOLD_SQUARED=0.36',
    'RFV2_FACEAPI_AMBIGUITY_MARGIN_SQUARED=0.01',
    'RFV2_SFACE_THRESHOLD_SQUARED=1.3225',
    'RFV2_SFACE_AMBIGUITY_MARGIN_SQUARED=0.0064',
    'RFV2_PYTHON_TIMEOUT_SECONDS=15',
    'RFV2_VIDEO_INTERVAL_MS=1000',
] as $setting) {
    [$name] = explode('=', $setting, 2);
    if (preg_match('/^'.preg_quote($name, '/').'=/m', $contents) !== 1) {
        $additions[] = $setting;
    }
}
if ($additions !== [] && file_put_contents($path, PHP_EOL.implode(PHP_EOL, $additions).PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
    throw new RuntimeException('Nao foi possivel atualizar o .env.');
}
echo $additions === [] ? "Configuracao da Tarefa 2 ja presente.\n" : "Segredos e configuracoes da Tarefa 2 adicionados sem exibicao.\n";
