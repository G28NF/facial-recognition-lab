<?php
declare(strict_types=1);

$root = require __DIR__.'/bootstrap.php';
$config = require $root.'/config/import.php';
$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

foreach (['maximum_files', 'maximum_image_bytes', 'maximum_archive_bytes', 'maximum_uncompressed_bytes', 'maximum_pixels'] as $key) {
    $check(isset($config[$key]) && is_int($config[$key]) && $config[$key] > 0, "Limite {$key} ausente ou invalido.");
}

$lfwGalleryBytes = 107_463_644;
$check($config['maximum_archive_bytes'] === 120_000_000, 'O limite ZIP configurado nao corresponde ao valor aprovado.');
$check($lfwGalleryBytes <= $config['maximum_archive_bytes'], 'O ZIP LFW informado continua acima do limite configurado.');
$lfwGalleryEntries = 7_484;
$lfwProbeEntries = 1_680;
$check($config['maximum_files'] === 10_000, 'O limite de entradas ZIP nao corresponde ao valor aprovado.');
$check($lfwGalleryEntries <= $config['maximum_files'] && $lfwProbeEntries <= $config['maximum_files'], 'Os ZIPs LFW conhecidos continuam acima do limite de entradas.');
$check($config['maximum_people'] >= 1_680 && $config['maximum_images_per_person'] >= 1_000, 'Os limites locais nao aceitam a configuracao LFW solicitada.');
$check($config['maximum_uncompressed_bytes'] === 200_000_000, 'O limite descompactado foi alterado fora do escopo.');
$check($config['maximum_compression_ratio'] === 100, 'A protecao de taxa de compressao foi alterada fora do escopo.');

echo "OK - configuracao de importacao valida; limite ZIP atual: {$config['maximum_archive_bytes']} bytes\n";
