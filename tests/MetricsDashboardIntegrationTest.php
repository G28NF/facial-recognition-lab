<?php
declare(strict_types=1);

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Persistence\MysqlMetricsDashboardRepository;
use App\Infrastructure\Persistence\MysqlVideoSessionRepository;
use App\Services\BenchmarkDashboardService;
use App\Services\StatisticsCalculator;

$root = require __DIR__.'/bootstrap.php';
$connection = new PdoConnection(require $root.'/config/database.php');
$pdo = $connection->pdo();
$sessions = new MysqlVideoSessionRepository($connection);
$repository = new MysqlMetricsDashboardRepository($connection);
$dashboard = new BenchmarkDashboardService($repository, new StatisticsCalculator());
$identitiesBefore = (int) $pdo->query('SELECT COUNT(*) FROM identities')->fetchColumn();
$embeddingsBefore = (int) $pdo->query('SELECT COUNT(*) FROM embeddings')->fetchColumn();
$modelsBefore = (int) $pdo->query('SELECT COUNT(*) FROM biometric_models')->fetchColumn();
$sessionRowsBefore = (int) $pdo->query('SELECT COUNT(*) FROM video_sessions')->fetchColumn();
$eventRowsBefore = (int) $pdo->query('SELECT COUNT(*) FROM recognition_events')->fetchColumn();
$metricRowsBefore = (int) $pdo->query('SELECT COUNT(*) FROM execution_metrics')->fetchColumn();
$dashboardBefore = $dashboard->dashboard(1, 20, null);
$sessionGroupsBefore = (int) $dashboardBefore['sessions']['total'];
$validABefore = (int) $dashboardBefore['overall']['A']['count'];
$tokens = [];

$result = static fn(string $status, float $total, ?string $failure = null, bool $withAmbiguity = true): array => [
    'status' => $status, 'id' => null, 'distance_squared' => $status === 'error' ? null : .5,
    'compared_embeddings' => $status === 'error' ? 0 : 1, 'error' => $failure,
    'metrics' => ['detection_ms' => $total / 4, 'extraction_ms' => $total / 4, 'comparison_ms' => $total / 10, 'pipeline_total_ms' => $total, 'end_to_end_ms' => $total],
] + ($withAmbiguity ? [
    'best_candidate_identity_id' => $status === 'error' ? null : 10,
    'best_candidate_distance_squared' => $status === 'error' ? null : .5,
    'second_candidate_identity_id' => $status === 'error' ? null : 11,
    'second_candidate_distance_squared' => $status === 'error' ? null : .508,
    'candidate_distance_delta_squared' => $status === 'error' ? null : .008,
    'ambiguity_margin_squared' => $status === 'error' ? null : .01,
    'decision_reason' => $status === 'error' ? 'technical_failure' : $status,
] : []);

try {
    $token = $sessions->start(1000, [
        'A' => ['initialization_ms' => 5.0, 'model_load_ms' => 3.0, 'runtime_ready_ms' => 2.0],
        'B' => ['initialization_ms' => 7.0],
    ]);
    $tokens[] = $token;
    try {
        $dashboard->deleteSession($token);
        throw new RuntimeException('Sessao ativa foi excluida.');
    } catch (DomainException $error) {
        if ($error->getCode() !== 409) throw $error;
    }
    $sessions->recordWarmup($token, 1, 0, ['A' => 8.0, 'B' => 9.0], ['presence_detection_ms' => 2.0], 8.0);
    $sessions->recordRound($token, 2, 0, ['A' => $result('recognized', 10), 'B' => $result('recognized', 20)], [], ['A' => .36, 'B' => 1.3225]);
    $sessions->finalizeRound($token, 2, ['browser_upload_ms' => 3.5, 'http_roundtrip_ms' => 42.0, 'request_payload_bytes' => 512.0], 'A');
    $sessions->recordRound($token, 3, 0, ['A' => $result('unknown', 20), 'B' => $result('error', 30, 'service_failure')], [], ['A' => .36, 'B' => 1.3225]);
    $sessions->recordRound($token, 4, 1, ['A' => $result('inconclusive', 100), 'B' => $result('unknown', 40)], [], ['A' => .36, 'B' => 1.3225]);
    $sessions->end($token);

    $group = hex2bin($token);
    if (!is_string($group)) throw new RuntimeException('Token de teste invalido.');
    $deleteIncomplete = $pdo->prepare('DELETE re FROM recognition_events re INNER JOIN video_sessions vs ON vs.id = re.video_session_id
        WHERE vs.session_group_id = :group_id AND re.frame_sequence = 4 AND re.architecture_id = "B"');
    $deleteIncomplete->bindValue(':group_id', $group, PDO::PARAM_LOB);
    $deleteIncomplete->execute();

    $data = $dashboard->dashboard(1, 20, $token);
    $a = $data['session_statistics']['A'];
    $b = $data['session_statistics']['B'];
    if ($a['count'] !== 3 || abs((float) $a['mean'] - 43.3333) > .0001 || $a['median'] !== 20.0 || $a['maximum'] !== 100.0 || $a['p95'] !== 100.0 || $a['failures'] !== 0) {
        throw new RuntimeException('Estatisticas A incorretas para extremo conhecido.');
    }
    if ($b['count'] !== 1 || $b['mean'] !== 20.0 || $b['failures'] !== 1 || $b['executions'] !== 2) {
        throw new RuntimeException('Falha B entrou na latencia ou contagem incorreta.');
    }
    $lifecycle = $data['lifecycle'];
    if (($lifecycle['A']['model_load_ms']['mean'] ?? null) !== 3.0 || ($lifecycle['A']['runtime_ready_ms']['mean'] ?? null) !== 2.0
        || ($lifecycle['A']['first_inference_ms']['mean'] ?? null) !== 8.0 || ($lifecycle['B']['first_inference_ms']['mean'] ?? null) !== 9.0
        || isset($lifecycle['B']['model_load_ms'])) {
        throw new RuntimeException('Fases de inicializacao, primeira inferencia ou metricas ausentes foram consolidadas incorretamente.');
    }
    $incomplete = array_values(array_filter($data['paired_frames'], static fn(array $frame): bool => $frame['frame_sequence'] === 4));
    if (count($incomplete) !== 1 || $incomplete[0]['complete'] !== false || $incomplete[0]['total_b_ms'] !== null) {
        throw new RuntimeException('Quadro incompleto nao foi identificado.');
    }
    $paired = [];
    foreach ($data['paired_frames'] as $frame) $paired[(int) $frame['frame_sequence']] = $frame;
    if (!array_key_exists('failure_a', $paired[2]) || $paired[2]['failure_a'] !== null || ($paired[2]['result_a'] ?? null) !== 'recognized'
        || ($paired[3]['failure_b'] ?? null) !== 'service_failure' || ($paired[3]['result_b'] ?? null) !== 'error') {
        throw new RuntimeException('Falhas JSON nulas ou falhas tecnicas nao foram representadas corretamente no painel.');
    }
    if (($paired[2]['best_candidate_identity_a'] ?? null) !== 10 || ($paired[2]['second_candidate_identity_a'] ?? null) !== 11
        || abs((float) ($paired[2]['candidate_distance_delta_a'] ?? -1) - .008) > .000001
        || abs((float) ($paired[2]['ambiguity_margin_a'] ?? -1) - .01) > .000001
        || ($paired[2]['decision_reason_a'] ?? null) !== 'recognized'
        || !array_key_exists('best_candidate_identity_b', $paired[3]) || $paired[3]['best_candidate_identity_b'] !== null
        || ($paired[3]['decision_reason_b'] ?? null) !== 'technical_failure') {
        throw new RuntimeException('Instrumentacao de ambiguidade nao foi persistida ou recuperada corretamente.');
    }
    $legacy = $result('unknown', 45, null, false);
    $legacyToken = $sessions->start(1000, ['A' => 1.0, 'B' => 1.0]);
    $tokens[] = $legacyToken;
    $sessions->recordRound($legacyToken, 1, 0, ['A' => $legacy, 'B' => $legacy], [], ['A' => .36, 'B' => 1.3225]);
    $sessions->end($legacyToken);
    $legacyFrames = $dashboard->dashboard(1, 20, $legacyToken)['paired_frames'];
    $legacyFrame = array_values(array_filter($legacyFrames, static fn(array $frame): bool => $frame['frame_sequence'] === 1))[0] ?? null;
    if (!is_array($legacyFrame) || $legacyFrame['best_candidate_identity_a'] !== null || $legacyFrame['candidate_distance_delta_a'] !== null || $legacyFrame['decision_reason_a'] !== 'unknown') {
        throw new RuntimeException('Registro sem metricas de ambiguidade nao permaneceu compativel.');
    }
    $dashboard->deleteSession($legacyToken);
    array_pop($tokens);
    $rows = iterator_to_array($dashboard->exportRows($token));
    $types = array_unique(array_column($rows, 'record_type'));
    $serializedRows = json_encode($rows, JSON_THROW_ON_ERROR);
    $headers = $dashboard->csvHeaders();
    if (!in_array('recognition', $types, true) || !in_array('session_metric', $types, true) || !in_array('browser_upload_ms', $headers, true) || in_array('http_roundtrip_ms', $headers, true) || !in_array('end_to_end_ms', $headers, true) || !in_array('request_payload_bytes', $headers, true) || str_contains($serializedRows, '"http_roundtrip_ms":') || str_contains($serializedRows, '"embedding":') || str_contains($serializedRows, '"image":') || str_contains($serializedRows, 'data:image')) {
        throw new RuntimeException('Exportacao nao separou registros ou incluiu dado biometrico.');
    }
    if (isset($data['stages']['A']['request_payload_bytes']) || isset($data['stages']['B']['request_payload_bytes'])) {
        throw new RuntimeException('Bytes enviados foram incluidos como etapa temporal.');
    }
    if (($data['stages']['A']['browser_upload_ms']['mean'] ?? null) !== 3.5 || isset($data['stages']['B']['browser_upload_ms']) || isset($data['stages']['A']['http_roundtrip_ms']) || isset($data['stages']['B']['http_roundtrip_ms'])) {
        throw new RuntimeException('Painel nao distinguiu upload observado de medicao ausente.');
    }
    $payloadExported = array_values(array_filter($rows, static fn(array $row): bool => $row['record_type'] === 'recognition' && (float) ($row['request_payload_bytes'] ?? 0) === 512.0));
    if (count($payloadExported) !== 1) {
        throw new RuntimeException('Bytes enviados deixaram de estar disponiveis na exportacao.');
    }

    $removed = $dashboard->deleteSession($token);
    array_pop($tokens);
    if ($removed['sessions'] !== 2 || $removed['events'] !== 5 || $removed['metrics'] < 10) {
        throw new RuntimeException('Exclusao por sessao retornou contagens incorretas.');
    }

    $first = $sessions->start(1000, ['A' => 1.0, 'B' => 1.0]);
    $tokens[] = $first;
    $sessions->recordRound($first, 1, 0, ['A' => $result('unknown', 15), 'B' => $result('unknown', 25)], [], ['A' => .36, 'B' => 1.3225]);
    $sessions->end($first);
    $second = $sessions->start(1000, ['A' => 1.0, 'B' => 1.0]);
    $tokens[] = $second;
    $sessions->recordRound($second, 1, 0, ['A' => $result('recognized', 30), 'B' => $result('recognized', 35)], [], ['A' => .36, 'B' => 1.3225]);
    $sessions->end($second);
    $multiple = $dashboard->dashboard(1, 20, $second);
    if ($multiple['sessions']['total'] !== $sessionGroupsBefore + 2 || $multiple['overall']['A']['count'] !== $validABefore + 2 || count($multiple['paired_frames']) !== 1 || $multiple['selected_session'] !== $second) {
        throw new RuntimeException('Historico ou graficos nao consolidaram multiplas sessoes: '.json_encode([
            'sessions' => $multiple['sessions']['total'],
            'count_a' => $multiple['overall']['A']['count'],
            'paired_frames' => count($multiple['paired_frames']),
            'selected_session' => $multiple['selected_session'],
            'expected_session' => $second,
        ], JSON_THROW_ON_ERROR));
    }
    $pdo->beginTransaction();
    $cleared = $dashboard->clearAll();
    if ($cleared['sessions'] !== $sessionRowsBefore + 4 || $cleared['events'] !== $eventRowsBefore + 4 || $cleared['metrics'] < $metricRowsBefore + 20) {
        throw new RuntimeException('Limpeza geral retornou contagens incorretas: '.json_encode($cleared, JSON_THROW_ON_ERROR));
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM identities')->fetchColumn() !== $identitiesBefore
        || (int) $pdo->query('SELECT COUNT(*) FROM embeddings')->fetchColumn() !== $embeddingsBefore
        || (int) $pdo->query('SELECT COUNT(*) FROM biometric_models')->fetchColumn() !== $modelsBefore) {
        throw new RuntimeException('Limpeza alterou identidades, embeddings ou modelos biometricos.');
    }
    $pdo->rollBack();
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($tokens as $token) {
        $group = hex2bin($token);
        if (!is_string($group)) continue;
        $delete = $pdo->prepare('DELETE FROM video_sessions WHERE session_group_id = :group_id');
        $delete->bindValue(':group_id', $group, PDO::PARAM_LOB);
        $delete->execute();
    }
}

echo "OK - painel, falhas, extremos, quadro incompleto, CSV e limpeza segura\n";
