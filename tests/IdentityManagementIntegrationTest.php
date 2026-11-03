<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Persistence\MysqlIdentityRepository;
use App\Infrastructure\Security\AuthenticatedCipher;
use App\Services\RegistrationService;

$root = require __DIR__.'/bootstrap.php';
$connection = new PdoConnection(require $root.'/config/database.php');
$pdo = $connection->pdo();
$security = require $root.'/config/security.php';
$repository = new MysqlIdentityRepository($connection, new AuthenticatedCipher($security['data_key'], $security['data_key_id']));
$registration = new RegistrationService($repository, require $root.'/config/biometrics.php');
$suffix = bin2hex(random_bytes(6));
$originalName = 'Teste identidade '.$suffix;
$renamed = 'Teste renomeado '.$suffix;
$vectorA = array_fill(0, 128, 0.125);
$vectorB = array_fill(0, 128, -0.125);

$before = [
    'identities' => (int) $pdo->query('SELECT COUNT(*) FROM identities')->fetchColumn(),
    'embeddings' => (int) $pdo->query('SELECT COUNT(*) FROM embeddings')->fetchColumn(),
    'events' => (int) $pdo->query('SELECT COUNT(*) FROM recognition_events')->fetchColumn(),
];

$pdo->beginTransaction();
try {
    $created = $repository->importPair($originalName, [
        'faceapi-recognition' => $vectorA,
        'opencv-sface' => $vectorB,
    ]);
    $identityId = $created['identity_id'];
    $details = $repository->details($identityId);
    if ($details['name'] !== $originalName || count($details['samples']) !== 2) {
        throw new RuntimeException('Consulta da pessoa nao retornou os dois modelos biometricos.');
    }

    $registration->rename($identityId, $renamed);
    if ($repository->details($identityId)['name'] !== $renamed) {
        throw new RuntimeException('Renomeacao criptografada nao foi refletida na consulta.');
    }

    $faceSample = array_values(array_filter(
        $details['samples'],
        static fn(array $sample): bool => $sample['model_key'] === 'faceapi-recognition'
    ))[0];
    $updated = $repository->setSampleActive($faceSample['id'], false);
    $inactive = array_values(array_filter($updated['samples'], static fn(array $sample): bool => $sample['id'] === $faceSample['id']))[0];
    if ($inactive['active'] !== false) throw new RuntimeException('Desativacao individual da amostra falhou.');
    $repository->setSampleActive($faceSample['id'], true);

    $event = $pdo->prepare('INSERT INTO recognition_events
        (video_session_id, architecture_id, matched_identity_id, frame_sequence, result, score, threshold, occurred_at)
        VALUES (NULL, "A", :identity_id, NULL, "recognized", 0.1, 0.5, UTC_TIMESTAMP(3))');
    $event->execute(['identity_id' => $identityId]);
    $eventId = (int) $pdo->lastInsertId();

    $impact = $repository->archive($identityId);
    if ($impact['deactivated_embeddings'] !== 2 || $impact['history_events'] !== 1) {
        throw new RuntimeException('Impacto da exclusao logica incorreto.');
    }
    $preserved = $pdo->prepare('SELECT matched_identity_id FROM recognition_events WHERE id = :id');
    $preserved->execute(['id' => $eventId]);
    if ((int) $preserved->fetchColumn() !== $identityId) {
        throw new RuntimeException('A exclusao logica rompeu o historico experimental.');
    }
    $active = $pdo->prepare('SELECT active FROM identities WHERE id = :id');
    $active->execute(['id' => $identityId]);
    if ((int) $active->fetchColumn() !== 0) throw new RuntimeException('A pessoa continuou ativa apos exclusao.');

    try {
        $repository->setSampleActive($faceSample['id'], true);
        throw new RuntimeException('Amostra de pessoa excluida foi reativada.');
    } catch (DomainException $error) {
        if ($error->getCode() !== 409) throw $error;
    }
    try {
        $repository->importPair($renamed, ['faceapi-recognition' => $vectorA, 'opencv-sface' => $vectorB]);
        throw new RuntimeException('Nome de pessoa excluida foi reutilizado.');
    } catch (DomainException $error) {
        if ($error->getCode() !== 409) throw $error;
    }
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

$after = [
    'identities' => (int) $pdo->query('SELECT COUNT(*) FROM identities')->fetchColumn(),
    'embeddings' => (int) $pdo->query('SELECT COUNT(*) FROM embeddings')->fetchColumn(),
    'events' => (int) $pdo->query('SELECT COUNT(*) FROM recognition_events')->fetchColumn(),
];
if ($before !== $after) throw new RuntimeException('O teste alterou dados persistentes.');

echo "OK - consulta, renomeacao, amostras, exclusao logica e historico preservado\n";
