<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Persistence\MysqlVideoSessionRepository;
$root = require __DIR__.'/bootstrap.php';
$connection = new PdoConnection(require $root.'/config/database.php');
$repository = new MysqlVideoSessionRepository($connection);
$pdo = $connection->pdo();
$orphanToken = $repository->start(1000, ['A' => 1.0, 'B' => 2.0]);
$token = $repository->start(1000, ['A' => 12.5, 'B' => 21.25]);
$persistedResponseToken = null;
$unpersistedRequestToken = null;
$individualToken = null;
$group = hex2bin($token);
$orphanGroup = hex2bin($orphanToken);
if (!is_string($group)) throw new RuntimeException('Token de sessao invalido.');
if (!is_string($orphanGroup) || $repository->status($orphanToken)['status'] !== 'interrupted') throw new RuntimeException('Recuperacao de sessao interrompida falhou.');

try {
    $state = $repository->status($token);
    if ($state['status'] !== 'running' || $state['interval_ms'] !== 1000) throw new RuntimeException('Inicio da sessao falhou.');
    $repository->recordMonitoring($token, 1, 2, ['presence_detection_ms' => 3.5, 'faces_detected_count' => 0]);
    try {
        $repository->recordMonitoring($token, 1, 0, ['presence_detection_ms' => 2.0]);
        throw new RuntimeException('Quadro duplicado foi aceito.');
    } catch (DomainException $error) {
        if ($error->getCode() !== 409) throw $error;
    }

    $repository->pause($token);
    if ($repository->status($token)['status'] !== 'paused') throw new RuntimeException('Pausa falhou.');
    try {
        $repository->recordMonitoring($token, 2, 0, []);
        throw new RuntimeException('Sessao pausada processou quadro.');
    } catch (DomainException $error) {
        if ($error->getCode() !== 409) throw $error;
    }
    $repository->resume($token);

    $results = [
        'A' => ['status' => 'unknown', 'id' => null, 'distance_squared' => .7, 'compared_embeddings' => 2, 'metrics' => ['detection_ms' => 5.0, 'comparison_ms' => 1.0]],
        'B' => ['status' => 'error', 'id' => null, 'distance_squared' => null, 'compared_embeddings' => 0, 'error' => 'service_failure', 'metrics' => ['pipeline_total_ms' => 15.0]],
    ];
    $repository->recordRound($token, 2, 0, $results, ['presence_detection_ms' => 4.0], ['A' => .36, 'B' => 1.3225]);
    $repository->finalizeRound($token, 2, ['browser_php_roundtrip_ms' => 30.0, 'comparative_round_total_ms' => 42.0]);
    $repository->recordMonitoring($token, 4, 0, ['presence_detection_ms' => 2.0]);
    $repository->end($token);
    $state = $repository->status($token);
    if ($state['status'] !== 'ended' || $state['missed_intervals'] !== 3 || $state['last_frame_sequence'] !== 4) throw new RuntimeException('Encerramento, sequencia ou intervalos perdidos incorretos: '.json_encode($state, JSON_THROW_ON_ERROR));

    // A rodada 1 representa uma gravacao confirmada no servidor cuja resposta se perdeu no cliente.
    $persistedResponseToken = $repository->start(1000, ['A' => 1.0, 'B' => 1.0]);
    $repository->recordMonitoring($persistedResponseToken, 1, 0, []);
    $repository->recordMonitoring($persistedResponseToken, 2, 0, []);
    $persistedState = $repository->status($persistedResponseToken);
    if ($persistedState['last_frame_sequence'] !== 2 || $persistedState['missed_intervals'] !== 0) {
        throw new RuntimeException('Quadro persistido foi contabilizado indevidamente como intervalo perdido.');
    }
    $repository->end($persistedResponseToken);

    // A sequencia 2 nao e enviada ao servidor; a proxima gravacao deve registrar exatamente uma lacuna.
    $unpersistedRequestToken = $repository->start(1000, ['A' => 1.0, 'B' => 1.0]);
    $repository->recordMonitoring($unpersistedRequestToken, 1, 0, []);
    $repository->recordMonitoring($unpersistedRequestToken, 3, 0, []);
    $unpersistedState = $repository->status($unpersistedRequestToken);
    if ($unpersistedState['last_frame_sequence'] !== 3 || $unpersistedState['missed_intervals'] !== 1) {
        throw new RuntimeException('Lacuna de sequencia nao persistida foi contabilizada incorretamente.');
    }
    $unpersistedGroup = hex2bin($unpersistedRequestToken);
    if (!is_string($unpersistedGroup)) throw new RuntimeException('Token de teste invalido.');
    $events = $pdo->prepare('SELECT COUNT(*) FROM recognition_events re INNER JOIN video_sessions vs ON vs.id=re.video_session_id WHERE vs.session_group_id=:group_id');
    $events->bindValue(':group_id', $unpersistedGroup, PDO::PARAM_LOB);
    $events->execute();
    if ((int) $events->fetchColumn() !== 0) throw new RuntimeException('Lacuna sem requisicao persistida criou resultado biometrico ficticio.');
    $repository->end($unpersistedRequestToken);

    $individualToken = $repository->start(1000, ['A' => 1.0, 'B' => 1.0]);
    $individualA = ['status' => 'error', 'id' => null, 'distance_squared' => null, 'compared_embeddings' => 0, 'error' => 'facial_input_failure', 'metrics' => ['faceapi_failure_ms' => 12.0, 'pipeline_total_ms' => 12.0]];
    $individualB = ['status' => 'unknown', 'id' => null, 'distance_squared' => .2, 'compared_embeddings' => 1, 'metrics' => ['pipeline_total_ms' => 20.0]];
    $repository->recordArchitectureResult($individualToken, 'A', 1, 0, $individualA, ['capture_ms' => 2.0], ['A' => .36, 'B' => 1.3225]);
    usleep(20_000); // Atraso artificial posterior de B nao pode alterar as metricas ja gravadas de A.
    $repository->recordArchitectureResult($individualToken, 'B', 1, 0, $individualB, [], ['A' => .36, 'B' => 1.3225]);
    $repository->finalizeRound($individualToken, 1, ['http_roundtrip_ms' => 30.0, 'end_to_end_ms' => 40.0, 'request_payload_bytes' => 512.0], 'A');
    $repository->finalizeRound($individualToken, 1, ['http_roundtrip_ms' => 50.0, 'end_to_end_ms' => 60.0, 'request_payload_bytes' => 4096.0], 'B');
    $individualGroup = hex2bin($individualToken);
    if (!is_string($individualGroup)) throw new RuntimeException('Token independente invalido.');
    $individualEvents = $pdo->prepare('SELECT COUNT(*) FROM recognition_events re INNER JOIN video_sessions vs ON vs.id=re.video_session_id WHERE vs.session_group_id=:group');
    $individualEvents->bindValue(':group', $individualGroup, PDO::PARAM_LOB); $individualEvents->execute();
    if ((int) $individualEvents->fetchColumn() !== 2) throw new RuntimeException('Resultados independentes nao foram persistidos por arquitetura.');
    $individualMetrics = $pdo->prepare('SELECT COUNT(*) FROM execution_metrics em INNER JOIN video_sessions vs ON vs.id=em.video_session_id WHERE vs.session_group_id=:group AND em.metric_name IN ("http_roundtrip_ms", "end_to_end_ms", "request_payload_bytes")');
    $individualMetrics->bindValue(':group', $individualGroup, PDO::PARAM_LOB); $individualMetrics->execute();
    if ((int) $individualMetrics->fetchColumn() !== 6) throw new RuntimeException('Metricas individuais A/B nao foram persistidas.');
    $latencies = $pdo->prepare('SELECT vs.architecture_id, em.metric_value FROM execution_metrics em INNER JOIN video_sessions vs ON vs.id=em.video_session_id WHERE vs.session_group_id=:group AND em.metric_name="end_to_end_ms"');
    $latencies->bindValue(':group', $individualGroup, PDO::PARAM_LOB); $latencies->execute(); $latencyByArchitecture = [];
    foreach ($latencies as $latency) $latencyByArchitecture[$latency['architecture_id']] = (float) $latency['metric_value'];
    if (($latencyByArchitecture['A'] ?? null) !== 40.0 || ($latencyByArchitecture['B'] ?? null) !== 60.0) throw new RuntimeException('Atraso de B contaminou latencias individuais.');
    $repository->end($individualToken);

    $eventQuery = $pdo->prepare('SELECT COUNT(*) FROM recognition_events re INNER JOIN video_sessions vs ON vs.id = re.video_session_id WHERE vs.session_group_id = :group_id');
    $eventQuery->bindValue(':group_id', $group, PDO::PARAM_LOB);
    $eventQuery->execute();
    if ((int) $eventQuery->fetchColumn() !== 2) throw new RuntimeException('Eventos A/B nao foram persistidos.');
    $metricQuery = $pdo->prepare('SELECT COUNT(*) FROM execution_metrics em INNER JOIN video_sessions vs ON vs.id = em.video_session_id WHERE vs.session_group_id = :group_id');
    $metricQuery->bindValue(':group_id', $group, PDO::PARAM_LOB);
    $metricQuery->execute();
    if ((int) $metricQuery->fetchColumn() < 10) throw new RuntimeException('Metricas da sessao incompletas.');
} finally {
    $delete = $pdo->prepare('DELETE FROM video_sessions WHERE session_group_id = :group_id');
    $delete->bindValue(':group_id', $group, PDO::PARAM_LOB);
    $delete->execute();
    $delete->bindValue(':group_id', $orphanGroup, PDO::PARAM_LOB);
    $delete->execute();
    foreach ([$persistedResponseToken, $unpersistedRequestToken, $individualToken] as $temporaryToken) {
        if (!is_string($temporaryToken)) continue;
        $temporaryGroup = hex2bin($temporaryToken);
        if (!is_string($temporaryGroup)) continue;
        $delete->bindValue(':group_id', $temporaryGroup, PDO::PARAM_LOB);
        $delete->execute();
    }
}

echo "OK - sessao comparativa, pausa, retomada, quadros, falhas e metricas\n";
