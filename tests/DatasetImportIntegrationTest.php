<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Persistence\MysqlDatasetImportRepository;
use App\Infrastructure\Persistence\MysqlIdentityRepository;
use App\Infrastructure\Security\AuthenticatedCipher;
use App\Services\DatasetImportService;
use App\Services\PythonFaceClient;

$root = require __DIR__.'/bootstrap.php';
$connection = new PdoConnection(require $root.'/config/database.php');
$pdo = $connection->pdo();
$security = require $root.'/config/security.php';
$cipher = new AuthenticatedCipher($security['data_key'], $security['data_key_id']);
$identities = new MysqlIdentityRepository($connection, $cipher);
$repository = new MysqlDatasetImportRepository($connection, $cipher, $identities);
$config = require $root.'/config/import.php';
$config['temporary_root'] = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rfv2-import-test-'.bin2hex(random_bytes(6));
$service = new DatasetImportService($repository, new PythonFaceClient(require $root.'/config/python.php'), require $root.'/config/biometrics.php', $config);

$people = $identities->list();
if ($people === []) throw new RuntimeException('O teste estrutural requer uma identidade local autorizada ja cadastrada.');
$identityId = $people[0]['id'];
$identityName = $people[0]['name'];
$vectors = [];
foreach (['faceapi-recognition', 'opencv-sface'] as $modelKey) {
    foreach ($identities->candidates($modelKey) as $candidate) {
        if ($candidate['identity_id'] === $identityId) { $vectors[$modelKey] = $candidate['embedding']; break; }
    }
}
if (count($vectors) !== 2) throw new RuntimeException('A identidade autorizada precisa dos dois modelos biometricos.');

$datasetName = 'Teste importacao transacional '.bin2hex(random_bytes(6));
$sourceNamespace = 'integration-test-'.$datasetName;
$sourceIdentityKey = 'existing-person';
$manifest = [
    ['identity' => $identityName, 'source_namespace' => $sourceNamespace, 'source_identity_key' => $sourceIdentityKey, 'source_key' => 'lfw/person/gallery.jpg', 'fingerprint' => str_repeat('11', 32), 'split' => 'gallery', 'mime_type' => 'image/jpeg', 'byte_size' => 1000],
    ['identity' => $identityName, 'source_namespace' => $sourceNamespace, 'source_identity_key' => $sourceIdentityKey, 'source_key' => 'lfw/person/probe.jpg', 'fingerprint' => str_repeat('22', 32), 'split' => 'probe', 'mime_type' => 'image/jpeg', 'byte_size' => 1000],
    ['identity' => $identityName, 'source_namespace' => $sourceNamespace, 'source_identity_key' => $sourceIdentityKey, 'source_key' => 'lfw/person/invalid.txt', 'fingerprint' => str_repeat('33', 32), 'split' => 'probe', 'mime_type' => 'text/plain', 'byte_size' => 10, 'error' => 'unsupported_format'],
    ['identity' => $identityName, 'source_namespace' => $sourceNamespace, 'source_identity_key' => $sourceIdentityKey, 'source_key' => 'lfw/person/repeated.jpg', 'fingerprint' => str_repeat('22', 32), 'split' => 'gallery', 'mime_type' => 'image/jpeg', 'byte_size' => 1000],
];

$pdo->beginTransaction();
try {
    $beforeEmbeddings = (int) $pdo->query('SELECT COUNT(*) FROM embeddings')->fetchColumn();
    $job = $repository->start($datasetName, 'folder', $manifest, ['maximum_people' => 1, 'maximum_images_per_person' => 3, 'gallery_images_per_person' => 1]);
    if ($job['total_samples'] !== 3 || $job['rejected_samples'] !== 1 || $job['pending_samples'] !== 2) throw new RuntimeException('Manifesto, duplicidade por fingerprint ou falha inicial incorretos.');
    if (array_key_exists('samples', $job)) throw new RuntimeException('Resumo de progresso carregou amostras individualmente.');
    $firstPage = $repository->samples($job['import_token'], ['pending', 'rejected'], 0, 1);
    $secondPage = $repository->samples($job['import_token'], ['pending', 'rejected'], 1, 1);
    if ($firstPage['total'] !== 3 || count($firstPage['items']) !== 1 || $firstPage['next_offset'] !== 1 || count($secondPage['items']) !== 1) {
        throw new RuntimeException('Paginacao de amostras nao limitou nem preservou o total.');
    }

    $job = $repository->pause($job['import_token']);
    if ($job['status'] !== 'paused') throw new RuntimeException('Pausa da importacao falhou.');
    $job = $repository->resume($job['import_token']);
    $pending = $repository->samples($job['import_token'], ['pending'], 0, 10)['items'];
    $gallery = array_values(array_filter($pending, static fn(array $sample): bool => $sample['split'] === 'gallery'))[0];
    $probe = array_values(array_filter($pending, static fn(array $sample): bool => $sample['split'] === 'probe'))[0];

    $job = $repository->store($job['import_token'], $gallery['sample_token'], $vectors['faceapi-recognition'], $vectors['opencv-sface']);
    if ((int) $pdo->query('SELECT COUNT(*) FROM embeddings')->fetchColumn() !== $beforeEmbeddings + 2) throw new RuntimeException('Gallery nao gravou exatamente os dois modelos.');
    $repository->pause($job['import_token']);
    $job = $repository->resume($job['import_token']);
    $job = $repository->store($job['import_token'], $probe['sample_token'], $vectors['faceapi-recognition'], $vectors['opencv-sface']);
    if ($job['status'] !== 'completed' || $job['processed_samples'] !== 2 || $job['rejected_samples'] !== 1) throw new RuntimeException('Retomada ou falha parcial comprometeu o lote.');
    if ((int) $pdo->query('SELECT COUNT(*) FROM embeddings')->fetchColumn() !== $beforeEmbeddings + 2) throw new RuntimeException('Probe foi inserida indevidamente na galeria.');

    $storage = $pdo->query('SELECT dse.usage_type, dse.gallery_embedding_id, dse.ciphertext, dse.nonce, dse.auth_tag, dse.dimensions
        FROM dataset_sample_embeddings dse ORDER BY dse.id DESC LIMIT 4')->fetchAll();
    $galleryRows = array_values(array_filter($storage, static fn(array $row): bool => $row['usage_type'] === 'gallery'));
    $probeRows = array_values(array_filter($storage, static fn(array $row): bool => $row['usage_type'] === 'probe'));
    if (count($galleryRows) !== 2 || count($probeRows) !== 2) throw new RuntimeException('Separacao gallery/probe incorreta.');
    foreach ($probeRows as $row) {
        if ($row['gallery_embedding_id'] !== null || $row['ciphertext'] === null || count($cipher->decryptEmbedding($row['ciphertext'], $row['nonce'], $row['auth_tag'], (int) $row['dimensions'])) !== 128) {
            throw new RuntimeException('Embedding probe nao esta separado e criptografado.');
        }
    }

    $repeated = $repository->start($datasetName, 'folder', $manifest, ['maximum_people' => 1, 'maximum_images_per_person' => 3, 'gallery_images_per_person' => 1]);
    if ($repeated['import_token'] !== $job['import_token'] || (int) $pdo->query('SELECT COUNT(*) FROM embeddings')->fetchColumn() !== $beforeEmbeddings + 2) {
        throw new RuntimeException('Reimportacao produziu duplicidade.');
    }
    $otherSource = $repository->start($datasetName, 'zip', $manifest, ['maximum_people' => 1, 'maximum_images_per_person' => 3, 'gallery_images_per_person' => 1]);
    if ($otherSource['total_samples'] !== 0 || $otherSource['status'] !== 'completed' || (int) $pdo->query('SELECT COUNT(*) FROM embeddings')->fetchColumn() !== $beforeEmbeddings + 2) {
        throw new RuntimeException('Reimportacao por outra origem duplicou amostras do mesmo dataset.');
    }

    $cancelManifest = [['identity' => $identityName, 'source_key' => 'lfw/person/pending.jpg', 'fingerprint' => str_repeat('44', 32), 'split' => 'gallery', 'mime_type' => 'image/jpeg', 'byte_size' => 1000]];
    $cancel = $repository->start($datasetName.' cancelado', 'folder', $cancelManifest, ['maximum_people' => 1, 'maximum_images_per_person' => 2, 'gallery_images_per_person' => 1]);
    $cancel = $repository->cancel($cancel['import_token']);
    if ($cancel['status'] !== 'cancelled' || $cancel['rejected_samples'] !== 1) throw new RuntimeException('Cancelamento nao encerrou pendencias com seguranca.');

    $badZip = tempnam(sys_get_temp_dir(), 'rfv2-bad-');
    if (!is_string($badZip)) throw new RuntimeException('Falha ao criar ZIP temporario.');
    $zip = new ZipArchive(); $zip->open($badZip, ZipArchive::OVERWRITE); $zip->addFromString('../escape.jpg', 'not-an-image'); $zip->close();
    try {
        $service->startZip($datasetName.' traversal', $badZip, 1, 2, 1);
        throw new RuntimeException('ZIP com path traversal foi aceito.');
    } catch (InvalidArgumentException $error) {
        if (!str_contains($error->getMessage(), 'path traversal')) throw $error;
    } finally { @unlink($badZip); }

    $invalidZip = tempnam(sys_get_temp_dir(), 'rfv2-invalid-');
    if (!is_string($invalidZip)) throw new RuntimeException('Falha ao criar ZIP temporario.');
    $zip = new ZipArchive(); $zip->open($invalidZip, ZipArchive::OVERWRITE); $zip->addFromString('lfw/'.$identityName.'/invalid.jpg', 'not-an-image'); $zip->close();
    $invalid = $service->startZip($datasetName.' invalido', $invalidZip, 1, 2, 2, 'gallery');
    $invalidSample = $repository->samples($invalid['import_token'], ['rejected'], 0, 1)['items'][0];
    if ($invalid['configuration']['source_split'] !== 'gallery' || $invalidSample['split'] !== 'gallery') {
        throw new RuntimeException('ZIP explicitamente gallery foi redistribuido.');
    }
    $probe = $service->startZip($datasetName.' probe', $invalidZip, 1, 2, 1, 'probe');
    @unlink($invalidZip);
    if ($invalid['status'] !== 'completed' || $invalid['rejected_samples'] !== 1 || $invalid['processed_samples'] !== 0) throw new RuntimeException('Falha individual do ZIP nao foi isolada.');
    $probeSample = $repository->samples($probe['import_token'], ['rejected'], 0, 1)['items'][0];
    if ($probe['configuration']['source_split'] !== 'probe' || $probeSample['split'] !== 'probe') {
        throw new RuntimeException('ZIP explicitamente probe foi redistribuido.');
    }

    $failureManifest = [['identity' => $identityName, 'source_key' => 'lfw/person/failure.jpg', 'fingerprint' => str_repeat('55', 32), 'split' => 'gallery', 'mime_type' => 'image/jpeg', 'byte_size' => 1000]];
    $failure = $repository->start($datasetName.' falha python', 'folder', $failureManifest, ['maximum_people' => 1, 'maximum_images_per_person' => 2, 'gallery_images_per_person' => 1]);
    $failureSample = $repository->samples($failure['import_token'], ['pending'], 0, 1)['items'][0];
    $failure = $service->reject($failure['import_token'], $failureSample['sample_token'], 'python_unavailable');
    $failureResult = $repository->samples($failure['import_token'], ['rejected'], 0, 1)['items'][0];
    if ($failureResult['failure_code'] !== 'python_unavailable') throw new RuntimeException('Falha de indisponibilidade Python nao foi preservada.');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (is_dir($config['temporary_root'])) @rmdir($config['temporary_root']);
}

echo "OK - LFW, gallery/probe, criptografia, retomada, idempotencia e ZIP seguro\n";
