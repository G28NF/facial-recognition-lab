<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Persistence\MysqlDatasetImportRepository;
use App\Infrastructure\Persistence\MysqlDatasetIntegrityRepository;
use App\Infrastructure\Persistence\MysqlIdentityRepository;
use App\Infrastructure\Persistence\MysqlProbeEvaluationRepository;
use App\Infrastructure\Security\AuthenticatedCipher;
use App\Services\DatasetIntegrityService;

$root = require __DIR__.'/bootstrap.php';
$connection = new PdoConnection(require $root.'/config/database.php');
$pdo = $connection->pdo();
$security = require $root.'/config/security.php';
$cipher = new AuthenticatedCipher($security['data_key'], $security['data_key_id']);
$identities = new MysqlIdentityRepository($connection, $cipher);
$imports = new MysqlDatasetImportRepository($connection, $cipher, $identities);
$evaluation = new MysqlProbeEvaluationRepository($connection, $cipher);
$models = require $root.'/config/biometrics.php';

$suffix = bin2hex(random_bytes(6));
$one = array_fill(0, 128, 0.0); $one[0] = 1.0;
$two = array_fill(0, 128, 0.0); $two[1] = 1.0;
$entry = static fn(string $name, string $source, string $key, string $file, string $fingerprint, string $split): array => [
    'identity' => $name, 'source_namespace' => $source, 'source_identity_key' => $key,
    'source_key' => $file, 'fingerprint' => $fingerprint, 'split' => $split,
    'mime_type' => 'image/jpeg', 'byte_size' => 1000,
];

$pdo->beginTransaction();
try {
    $source = 'stable-source-'.$suffix;
    $first = $imports->start('Integridade fonte 1 '.$suffix, 'folder', [
        $entry('Nome original', $source, 'person-1', 'a.jpg', str_repeat('a', 64), 'gallery'),
    ], []);
    $sample = $imports->samples($first['import_token'], ['pending'], 0, 1)['items'][0];
    $imports->store($first['import_token'], $sample['sample_token'], $one, $one);
    $firstIdentity = (int) $pdo->query('SELECT identity_id FROM dataset_import_identities ORDER BY id DESC LIMIT 1')->fetchColumn();

    $clearProbe = $imports->start('Integridade clear '.$suffix, 'folder', [
        $entry('Nome original', $source, 'person-1', 'clear-probe.jpg', str_repeat('f', 64), 'probe'),
    ], []);
    $sample = $imports->samples($clearProbe['import_token'], ['pending'], 0, 1)['items'][0];
    $imports->store($clearProbe['import_token'], $sample['sample_token'], $one, $one);
    $available = array_column($evaluation->availableImports(), 'eligible_samples', 'import_token');
    if (($available[$clearProbe['import_token']] ?? 0) !== 1) throw new RuntimeException('Probe clear nao foi mantida elegivel.');
    $clearImportId = (int) $pdo->query('SELECT id FROM dataset_imports ORDER BY id DESC LIMIT 1')->fetchColumn();
    $pdo->prepare('UPDATE dataset_samples SET integrity_status = :status WHERE dataset_import_id = :import_id')
        ->execute(['status' => 'excluded', 'import_id' => $clearImportId]);
    $available = array_column($evaluation->availableImports(), 'eligible_samples', 'import_token');
    if (isset($available[$clearProbe['import_token']])) throw new RuntimeException('Probe excluida permaneceu elegivel.');
    $pdo->prepare('UPDATE dataset_samples SET integrity_status = :status WHERE dataset_import_id = :import_id')
        ->execute(['status' => 'clear', 'import_id' => $clearImportId]);
    $pdo->prepare('UPDATE dataset_import_identities SET integrity_status = :status WHERE dataset_import_id = :import_id')
        ->execute(['status' => 'ambiguous', 'import_id' => $clearImportId]);
    $available = array_column($evaluation->availableImports(), 'eligible_samples', 'import_token');
    if (isset($available[$clearProbe['import_token']])) throw new RuntimeException('Probe ambigua permaneceu elegivel.');

    $renamed = $imports->start('Integridade fonte 2 '.$suffix, 'folder', [
        $entry('Nome alternativo', $source, 'person-1', 'b.jpg', str_repeat('b', 64), 'gallery'),
    ], []);
    $sample = $imports->samples($renamed['import_token'], ['pending'], 0, 1)['items'][0];
    $imports->store($renamed['import_token'], $sample['sample_token'], $one, $one);
    $sameSourceIdentity = (int) $pdo->query('SELECT identity_id FROM dataset_import_identities ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($sameSourceIdentity !== $firstIdentity) throw new RuntimeException('Identificador estavel nao preservou a identidade entre datasets.');

    $sameNameA = $imports->start('Integridade nome A '.$suffix, 'folder', [
        $entry('Mesmo rotulo', $source, 'person-2', 'c.jpg', str_repeat('c', 64), 'gallery'),
    ], []);
    $sample = $imports->samples($sameNameA['import_token'], ['pending'], 0, 1)['items'][0];
    $imports->store($sameNameA['import_token'], $sample['sample_token'], $one, $one);
    $personTwo = (int) $pdo->query('SELECT identity_id FROM dataset_import_identities ORDER BY id DESC LIMIT 1')->fetchColumn();
    $sameNameB = $imports->start('Integridade nome B '.$suffix, 'folder', [
        $entry('Mesmo rotulo', $source, 'person-3', 'd.jpg', str_repeat('d', 64), 'gallery'),
    ], []);
    $sample = $imports->samples($sameNameB['import_token'], ['pending'], 0, 1)['items'][0];
    $imports->store($sameNameB['import_token'], $sample['sample_token'], $two, $two);
    $personThree = (int) $pdo->query('SELECT identity_id FROM dataset_import_identities ORDER BY id DESC LIMIT 1')->fetchColumn();
    if ($personTwo === $personThree) throw new RuntimeException('Pessoas distintas com o mesmo rotulo foram unificadas.');

    $overlap = $imports->start('Integridade sobreposicao '.$suffix, 'folder', [
        $entry('Probe repetida', 'overlap-'.$suffix, 'person-4', 'overlap.jpg', str_repeat('a', 64), 'probe'),
    ], []);
    $probe = $imports->samples($overlap['import_token'], ['pending'], 0, 1)['items'][0];
    if ($probe['integrity_status'] !== 'excluded' || $probe['integrity_reason'] !== 'cross_dataset_image_overlap') {
        throw new RuntimeException('Imagem repetida entre datasets nao foi excluida por integridade.');
    }
    $imports->store($overlap['import_token'], $probe['sample_token'], $one, $one);
    if ($imports->status($overlap['import_token'])['integrity_excluded_samples'] !== 1) {
        throw new RuntimeException('Resumo nao contabilizou exclusao por integridade.');
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM dataset_sample_embeddings WHERE dataset_sample_id = (SELECT id FROM dataset_samples ORDER BY id DESC LIMIT 1)')->fetchColumn() !== 0) {
        throw new RuntimeException('Probe sobreposta criou embeddings para avaliacao.');
    }
    try {
        $evaluation->start($overlap['import_token'], 1);
        throw new RuntimeException('Probe sobreposta entrou na avaliacao supervisionada.');
    } catch (DomainException $error) {
        if ($error->getCode() !== 409) throw $error;
    }

    $withinDataset = $imports->start('Integridade gallery probe '.$suffix, 'folder', [
        $entry('Galeria', 'same-file-'.$suffix, 'person-5', 'gallery.jpg', str_repeat('e', 64), 'gallery'),
        $entry('Galeria', 'same-file-'.$suffix, 'person-5', 'probe.jpg', str_repeat('e', 64), 'probe'),
    ], []);
    if ($withinDataset['total_samples'] !== 1) throw new RuntimeException('Sobreposicao gallery/probe no mesmo dataset nao foi impedida.');

    $audit = new DatasetIntegrityService($identities, new MysqlDatasetIntegrityRepository($connection), $models);
    $result = $audit->reviewPossibleDuplicates(20);
    if ($result['reviewed_identities'] < 2 || ($result['findings']['A'] + $result['findings']['B']) < 1) {
        throw new RuntimeException('Verificacao controlada de duplicidade biometrica nao registrou achados.');
    }
} finally {
    $pdo->rollBack();
}

echo "OK - fonte estavel, rotulos ambiguos, imagens repetidas, exclusao de probes e triagem biometrica\n";
