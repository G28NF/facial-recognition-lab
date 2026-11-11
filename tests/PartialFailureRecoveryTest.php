<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Persistence\{MysqlDatasetImportRepository, MysqlIdentityRepository, MysqlProbeEvaluationRepository};
use App\Infrastructure\Security\AuthenticatedCipher;
use App\Services\{ComparisonService, ProbeEvaluationService, StatisticsCalculator};

$root = require __DIR__.'/bootstrap.php';
$connection = new PdoConnection(require $root.'/config/database.php');
$pdo = $connection->pdo();
$cipher = new AuthenticatedCipher((require $root.'/config/security.php')['data_key'], (require $root.'/config/security.php')['data_key_id']);
$identities = new MysqlIdentityRepository($connection, $cipher);
$imports = new MysqlDatasetImportRepository($connection, $cipher, $identities);
$models = require $root.'/config/biometrics.php';
$assert = static fn(bool $ok, string $message) => $ok ?: throw new RuntimeException($message);
$vectors = [];
foreach (['faceapi-recognition', 'opencv-sface'] as $model) foreach ($identities->candidates($model) as $candidate) { $vectors[$model] ??= $candidate['embedding']; }
$assert(count($vectors) === 2, 'Requer embeddings autorizados A e B.');
$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(6));
    $name = 'Parcial '.$suffix;
    $manifest = [
        ['identity'=>$name, 'source_key'=>"{$suffix}/gallery.jpg", 'fingerprint'=>hash('sha256',"{$suffix}/gallery"), 'split'=>'gallery', 'mime_type'=>'image/jpeg', 'byte_size'=>1000],
        ['identity'=>$name, 'source_key'=>"{$suffix}/probe.jpg", 'fingerprint'=>hash('sha256',"{$suffix}/probe"), 'split'=>'probe', 'mime_type'=>'image/jpeg', 'byte_size'=>1000],
    ];
    $job = $imports->start('Parcial '.$suffix, 'folder', $manifest, []);
    $items = $imports->samples($job['import_token'], ['pending'], 0, 10)['items'];
    $gallery = array_values(array_filter($items, static fn(array $item): bool => $item['split'] === 'gallery'))[0];
    $probe = array_values(array_filter($items, static fn(array $item): bool => $item['split'] === 'probe'))[0];
    $imports->store($job['import_token'], $gallery['sample_token'], null, $vectors['opencv-sface'], ['A'=>'no_face']);
    $imports->store($job['import_token'], $probe['sample_token'], $vectors['faceapi-recognition'], $vectors['opencv-sface']);
    $evaluation = new ProbeEvaluationService(new MysqlProbeEvaluationRepository($connection, $cipher), new ComparisonService($identities, $models), new StatisticsCalculator(), $models);
    $summary = $evaluation->run($job['import_token'], 1);
    $assert($summary['architectures']['A']['technical_failure'] === 1 && $summary['architectures']['A']['completed'] === 0, 'Gallery A ausente foi incluida no denominador de precisao.');
    $assert($summary['architectures']['B']['completed'] === 1, 'Resultado B valido foi descartado pela gallery parcial A.');

    $empty = $imports->start('Vazia '.$suffix, 'folder', [['identity'=>'Vazia '.$suffix, 'source_key'=>"{$suffix}/empty.jpg", 'fingerprint'=>hash('sha256',"{$suffix}/empty"), 'split'=>'gallery', 'mime_type'=>'image/jpeg', 'byte_size'=>1000]], []);
    $sample = $imports->samples($empty['import_token'], ['pending'], 0, 1)['items'][0];
    $empty = $imports->store($empty['import_token'], $sample['sample_token'], null, null, ['A'=>'no_face', 'B'=>'biometric_failure']);
    $assert($empty['rejected_samples'] === 1 && $empty['linked_identities'] === 0, 'Gallery sem embedding criou identidade ou ficou processada.');

    $retry = $imports->start('Retomada '.$suffix, 'folder', [['identity'=>$name, 'source_key'=>"{$suffix}/retry.jpg", 'fingerprint'=>hash('sha256',"{$suffix}/retry"), 'split'=>'probe', 'mime_type'=>'image/jpeg', 'byte_size'=>1000]], []);
    $sample = $imports->samples($retry['import_token'], ['pending'], 0, 1)['items'][0];
    $retry = $imports->store($retry['import_token'], $sample['sample_token'], $vectors['faceapi-recognition'], null, ['B'=>'http_failure']);
    $assert($retry['pending_samples'] === 1, 'Falha HTTP temporaria encerrou amostra parcial.');
    $retry = $imports->store($retry['import_token'], $sample['sample_token'], null, $vectors['opencv-sface']);
    $assert($retry['processed_samples'] === 1, 'Retomada B nao concluiu a amostra.');
    $completed = $imports->samples($retry['import_token'], ['processed'], 0, 1)['items'][0]['completed_architectures'];
    $assert($completed === ['A', 'B'], 'Retomada duplicou ou perdeu embedding parcial.');
} finally { $pdo->rollBack(); }
echo "OK - gallery parcial, denominadores e retomada seletiva\n";
