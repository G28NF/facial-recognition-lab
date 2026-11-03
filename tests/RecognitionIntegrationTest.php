<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Persistence\MysqlIdentityRepository;
use App\Infrastructure\Security\AuthenticatedCipher;
use App\Services\ComparisonService;

$root = require __DIR__.'/bootstrap.php';
$security = require $root.'/config/security.php';
$cipher = new AuthenticatedCipher($security['data_key'], $security['data_key_id']);
$connection = new PdoConnection(require $root.'/config/database.php');
$repository = new MysqlIdentityRepository($connection, $cipher);
$models = require $root.'/config/biometrics.php';
$comparison = new ComparisonService($repository, $models);
$pdo = $connection->pdo();
$before = $repository->count();
$faceapiBefore = (int) $pdo->query("SELECT COUNT(*) FROM embeddings e INNER JOIN biometric_models m ON m.id = e.biometric_model_id WHERE m.model_key = 'faceapi-recognition' AND e.active = 1")->fetchColumn();
$sfaceBefore = (int) $pdo->query("SELECT COUNT(*) FROM embeddings e INNER JOIN biometric_models m ON m.id = e.biometric_model_id WHERE m.model_key = 'opencv-sface' AND e.active = 1")->fetchColumn();
$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(6));
    $one = array_fill(0, 128, 0.0);
    $one[0] = 1.0;
    $two = array_fill(0, 128, 0.0);
    $two[0] = -1.0;
    $idOne = $repository->register("Teste A {$suffix}", ['faceapi-recognition' => $one, 'opencv-sface' => $one], ['faceapi-recognition' => .36, 'opencv-sface' => 1.3225]);
    $idTwo = $repository->register("Teste B {$suffix}", ['faceapi-recognition' => $two, 'opencv-sface' => $two], ['faceapi-recognition' => .36, 'opencv-sface' => 1.3225]);

    $secondSample = array_fill(0, 128, 0.0);
    $secondSample[0] = 0.999;
    $secondSample[1] = 0.0447;
    $encrypted = $cipher->encryptEmbedding($secondSample, 128);
    $modelId = (int) $pdo->query("SELECT id FROM biometric_models WHERE model_key = 'faceapi-recognition'")->fetchColumn();
    $insert = $pdo->prepare('INSERT INTO embeddings
        (identity_id, biometric_model_id, sample_number, ciphertext, nonce, auth_tag, key_id, dimensions)
        VALUES (:identity_id, :model_id, 2, :ciphertext, :nonce, :auth_tag, :key_id, 128)');
    $insert->execute(['identity_id' => $idOne, 'model_id' => $modelId, 'ciphertext' => $encrypted['ciphertext'], 'nonce' => $encrypted['nonce'], 'auth_tag' => $encrypted['auth_tag'], 'key_id' => $encrypted['key_id']]);

    $known = $comparison->recognize('faceapi-recognition', $secondSample);
    if ($known['status'] !== 'recognized' || $known['id'] !== $idOne || $known['name'] !== "Teste A {$suffix}" || $known['compared_embeddings'] !== $faceapiBefore + 3) {
        throw new RuntimeException('Comparacao 1:N ou multiplas amostras falhou.');
    }
    if ($known['best_candidate_identity_id'] !== $idOne || $known['best_candidate_distance_squared'] !== $known['distance_squared']
        || $known['second_candidate_identity_id'] === null || $known['second_candidate_identity_id'] === $known['best_candidate_identity_id']
        || $known['second_candidate_distance_squared'] === null || $known['candidate_distance_delta_squared'] === null
        || abs($known['candidate_distance_delta_squared'] - ($known['second_candidate_distance_squared'] - $known['best_candidate_distance_squared'])) > 0.00000001
        || $known['ambiguity_margin_squared'] !== .01 || $known['decision_reason'] !== 'recognized') {
        throw new RuntimeException('Instrumentacao do melhor e segundo candidatos invalida.');
    }
    $requiredMetrics = ['mysql_query_ms', 'candidate_read_ms', 'embedding_decrypt_ms', 'candidate_prepare_ms', 'comparison_ms', 'candidate_selection_ms', 'matched_name_query_ms', 'matched_name_decrypt_ms', 'comparison_overhead_ms', 'comparison_total_ms'];
    foreach ($requiredMetrics as $metric) {
        if (!isset($known['comparison_metrics'][$metric]) || !is_float($known['comparison_metrics'][$metric]) || $known['comparison_metrics'][$metric] < 0) {
            throw new RuntimeException("Metrica de comparacao ausente ou invalida: {$metric}.");
        }
    }
    $components = array_sum(array_diff_key($known['comparison_metrics'], ['comparison_total_ms' => true]));
    if (abs($components - $known['comparison_metrics']['comparison_total_ms']) > .02) throw new RuntimeException('Etapas da comparacao possuem dupla contabilizacao ou residuo incorreto.');
    $deleteSample = $pdo->prepare('DELETE FROM embeddings WHERE identity_id = :identity_id AND biometric_model_id = :model_id AND sample_number = 2');
    $deleteSample->execute(['identity_id' => $idOne, 'model_id' => $modelId]);
    $afterDeletion = $comparison->recognize('faceapi-recognition', $secondSample);
    if ($afterDeletion['status'] !== 'recognized' || $afterDeletion['id'] !== $idOne || $afterDeletion['compared_embeddings'] !== $faceapiBefore + 2) {
        throw new RuntimeException('Exclusao de embedding nao foi refletida imediatamente na comparacao.');
    }
    $knownSface = $comparison->recognize('opencv-sface', $one);
    if ($knownSface['status'] !== 'recognized' || $knownSface['id'] !== $idOne || $knownSface['compared_embeddings'] !== $sfaceBefore + 2) {
        throw new RuntimeException('Comparacao no espaco SFace falhou.');
    }
    $ambiguousSample = array_fill(0, 128, 0.0);
    $ambiguousSample[0] = .99;
    $ambiguousSample[1] = .1;
    $encryptedAmbiguous = $cipher->encryptEmbedding($ambiguousSample, 128);
    $insert->execute(['identity_id' => $idTwo, 'model_id' => $modelId, 'ciphertext' => $encryptedAmbiguous['ciphertext'], 'nonce' => $encryptedAmbiguous['nonce'], 'auth_tag' => $encryptedAmbiguous['auth_tag'], 'key_id' => $encryptedAmbiguous['key_id']]);
    $ambiguous = $comparison->recognize('faceapi-recognition', $secondSample);
    if ($ambiguous['status'] !== 'inconclusive' || $ambiguous['id'] !== null || $ambiguous['name'] !== null) {
        throw new RuntimeException('Margem de ambiguidade ou isolamento do nome foi alterado.');
    }
    if ($ambiguous['best_candidate_identity_id'] === null || $ambiguous['second_candidate_identity_id'] === null
        || $ambiguous['best_candidate_identity_id'] === $ambiguous['second_candidate_identity_id']
        || $ambiguous['candidate_distance_delta_squared'] === null
        || $ambiguous['candidate_distance_delta_squared'] >= $ambiguous['ambiguity_margin_squared']
        || $ambiguous['decision_reason'] !== 'inconclusive') {
        throw new RuntimeException('Ambiguidade nao foi instrumentada sem alterar sua decisao.');
    }
    $unknownVector = array_fill(0, 128, 0.0);
    $unknownVector[1] = 1.0;
    $unknown = $comparison->recognize('faceapi-recognition', $unknownVector);
    if ($unknown['status'] !== 'unknown' || $unknown['name'] !== null || isset($unknown['comparison_metrics']['matched_name_query_ms']) || isset($unknown['comparison_metrics']['matched_name_decrypt_ms'])) {
        throw new RuntimeException('Rejeicao de desconhecido falhou.');
    }
    if ($unknown['decision_reason'] !== 'unknown' || $unknown['ambiguity_margin_squared'] !== .01) {
        throw new RuntimeException('Resultado desconhecido nao preservou sua instrumentacao observacional.');
    }
    $pdo->exec('UPDATE embeddings SET active = 0');
    $activateSingleCandidate = $pdo->prepare('UPDATE embeddings SET active = 1 WHERE identity_id = :identity_id AND biometric_model_id = :model_id');
    $activateSingleCandidate->execute(['identity_id' => $idOne, 'model_id' => $modelId]);
    $singleCandidate = $comparison->recognize('faceapi-recognition', $secondSample);
    if ($singleCandidate['status'] !== 'recognized' || $singleCandidate['best_candidate_identity_id'] !== $idOne
        || $singleCandidate['second_candidate_identity_id'] !== null || $singleCandidate['second_candidate_distance_squared'] !== null
        || $singleCandidate['candidate_distance_delta_squared'] !== null || $singleCandidate['decision_reason'] !== 'recognized') {
        throw new RuntimeException('Comparacao com unico candidato nao preservou campos indisponiveis como null.');
    }
} finally {
    $pdo->rollBack();
}
if ($repository->count() !== $before) {
    throw new RuntimeException('Teste de reconhecimento deixou dados persistidos.');
}
echo "OK - Face API.js/SFace, comparacao 1:N, multiplos embeddings e desconhecido\n";
