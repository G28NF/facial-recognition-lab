<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Persistence\MysqlDatasetImportRepository;
use App\Infrastructure\Persistence\MysqlIdentityRepository;
use App\Infrastructure\Persistence\MysqlProbeEvaluationRepository;
use App\Infrastructure\Security\AuthenticatedCipher;
use App\Services\ComparisonService;
use App\Services\ProbeEvaluationService;
use App\Services\StatisticsCalculator;

$root = require __DIR__.'/bootstrap.php';
$connection = new PdoConnection(require $root.'/config/database.php');
$pdo = $connection->pdo();
$security = require $root.'/config/security.php';
$cipher = new AuthenticatedCipher($security['data_key'], $security['data_key_id']);
$identities = new MysqlIdentityRepository($connection, $cipher);
$imports = new MysqlDatasetImportRepository($connection, $cipher, $identities);
$models = require $root.'/config/biometrics.php';
$comparison = new ComparisonService($identities, $models);
$evaluations = new MysqlProbeEvaluationRepository($connection, $cipher);
$service = new ProbeEvaluationService($evaluations, $comparison, new StatisticsCalculator(), $models);

$suffix = bin2hex(random_bytes(6));
$one = array_fill(0, 128, 0.0); $one[0] = 1.0;
$two = array_fill(0, 128, 0.0); $two[0] = -1.0;
$unknown = array_fill(0, 128, 0.0); $unknown[1] = 1.0;
$invalid = [0.0, 1.0];

$pdo->beginTransaction();
try {
    $idOne = $identities->register("Probe esperado {$suffix}", ['faceapi-recognition' => $one, 'opencv-sface' => $one], ['faceapi-recognition' => .36, 'opencv-sface' => 1.3225]);
    $identities->register("Probe incorreto {$suffix}", ['faceapi-recognition' => $two, 'opencv-sface' => $two], ['faceapi-recognition' => .36, 'opencv-sface' => 1.3225]);
    $dataset = 'Teste avaliacao probe '.$suffix;
    $manifest = [];
    foreach (['correct', 'incorrect', 'unknown', 'technical'] as $position => $name) {
        $manifest[] = [
            'identity' => "Probe esperado {$suffix}", 'source_key' => "{$suffix}/{$name}.jpg",
            'fingerprint' => hash('sha256', "{$suffix}/{$name}"), 'split' => 'probe',
            'mime_type' => 'image/jpeg', 'byte_size' => 1000,
        ];
    }
    $job = $imports->start($dataset, 'folder', $manifest, ['maximum_people' => 1, 'maximum_images_per_person' => 4, 'gallery_images_per_person' => 1]);
    $samples = $imports->samples($job['import_token'], ['pending'], 0, 10)['items'];
    foreach ($samples as $index => $sample) {
        $vector = [$one, $two, $unknown, $invalid][$index];
        $job = $imports->store($job['import_token'], $sample['sample_token'], $vector, $vector);
    }
    if ($job['status'] !== 'completed') throw new RuntimeException('As probes de teste nao foram processadas.');

    $summary = $service->run($job['import_token'], 4);
    if (($summary['evaluation']['status'] ?? null) !== 'completed' || ($summary['evaluation']['selected_samples'] ?? null) !== 4 || $summary['paired_completed'] !== 3) {
        throw new RuntimeException('Avaliacao nao registrou as probes pareadas esperadas.');
    }
    foreach (['A', 'B'] as $architecture) {
        $result = $summary['architectures'][$architecture];
        if ($result['correct'] !== 1 || $result['incorrect'] !== 1 || $result['not_recognized'] !== 1 || $result['technical_failure'] !== 1) {
            throw new RuntimeException("Classificacao de {$architecture} incorreta.");
        }
        if ($result['accuracy_percent'] !== 33.33 || $result['error_percent'] !== 66.67 || $result['coverage_percent'] !== 75.0) {
            throw new RuntimeException("Percentuais de {$architecture} nao usam o denominador definido.");
        }
    }
    $stored = $pdo->prepare('SELECT COUNT(*) FROM probe_evaluation_results r INNER JOIN probe_evaluations e ON e.id = r.probe_evaluation_id WHERE e.public_id = :public_id');
    $stored->bindValue(':public_id', hex2bin($summary['evaluation']['evaluation_token']), PDO::PARAM_LOB);
    $stored->execute();
    if ((int) $stored->fetchColumn() !== 8) throw new RuntimeException('Resultados individuais A/B nao foram preservados para auditoria.');
    $ambiguity = $pdo->prepare('SELECT recognition_result, best_candidate_identity_id, best_candidate_distance_squared,
        second_candidate_identity_id, second_candidate_distance_squared, candidate_distance_delta_squared, ambiguity_margin_squared, decision_reason
        FROM probe_evaluation_results r INNER JOIN probe_evaluations e ON e.id=r.probe_evaluation_id
        WHERE e.public_id=:public_id AND outcome <> "technical_failure" LIMIT 1');
    $ambiguity->bindValue(':public_id', hex2bin($summary['evaluation']['evaluation_token']), PDO::PARAM_LOB);
    $ambiguity->execute();
    $row = $ambiguity->fetch();
    if (!is_array($row) || $row['best_candidate_identity_id'] === null || $row['best_candidate_distance_squared'] === null
        || $row['ambiguity_margin_squared'] === null || $row['decision_reason'] !== $row['recognition_result']) {
        throw new RuntimeException('Metricas de ambiguidade da avaliacao Probe nao foram persistidas.');
    }
    $technical = $pdo->prepare('SELECT best_candidate_identity_id, second_candidate_identity_id, candidate_distance_delta_squared, decision_reason
        FROM probe_evaluation_results r INNER JOIN probe_evaluations e ON e.id=r.probe_evaluation_id
        WHERE e.public_id=:public_id AND outcome="technical_failure" LIMIT 1');
    $technical->bindValue(':public_id', hex2bin($summary['evaluation']['evaluation_token']), PDO::PARAM_LOB);
    $technical->execute();
    $row = $technical->fetch();
    if (!is_array($row) || $row['best_candidate_identity_id'] !== null || $row['second_candidate_identity_id'] !== null
        || $row['candidate_distance_delta_squared'] !== null || $row['decision_reason'] !== 'technical_failure') {
        throw new RuntimeException('Falha tecnica Probe recebeu candidatos inexistentes.');
    }
} finally {
    $pdo->rollBack();
}

echo "OK - avaliacao Probe A/B, acerto, incorreta, nao reconhecido, falha tecnica e percentuais\n";
