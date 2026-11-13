<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\PdoConnection;
use DomainException;
use Generator;
use PDO;

final class MysqlMetricsDashboardRepository
{
    private const VALID_RESULTS = "('recognized','unknown','inconclusive')";
    private const STAGE_METRICS = [
        'detection_ms', 'extraction_ms', 'comparison_ms', 'pipeline_total_ms',
        'mysql_query_ms', 'candidate_read_ms', 'embedding_decrypt_ms', 'candidate_prepare_ms',
        'candidate_selection_ms', 'matched_name_query_ms', 'matched_name_decrypt_ms',
        'comparison_overhead_ms', 'comparison_total_ms',
        'image_decode_ms', 'facial_processing_ms', 'php_python_roundtrip_ms',
        'php_python_overhead_ms', 'image_encode_ms', 'browser_upload_ms', 'end_to_end_ms',
    ];
    private const EXPORT_METRICS = [...self::STAGE_METRICS, 'browser_download_ms', 'http_roundtrip_ms', 'request_payload_bytes'];
    private const LIFECYCLE_METRICS = ['initialization_ms', 'model_load_ms', 'runtime_ready_ms', 'first_inference_ms', 'warmup_ms'];

    public function __construct(private readonly PdoConnection $connection) {}

    /** @return array<string,list<float>> */
    public function latencyValues(?string $sessionToken = null): array
    {
        $sql = 'SELECT re.architecture_id, em.metric_value FROM recognition_events re
            INNER JOIN video_sessions vs ON vs.id = re.video_session_id
            INNER JOIN execution_metrics em ON em.recognition_event_id = re.id AND em.metric_name = "end_to_end_ms"
            WHERE re.result IN '.self::VALID_RESULTS.' AND em.metric_value >= 0';
        $params = [];
        if ($sessionToken !== null) {
            $sql .= ' AND vs.session_group_id = :session_group_id';
            $params['session_group_id'] = $this->tokenBytes($sessionToken);
        }
        $statement = $this->connection->pdo()->prepare($sql.' ORDER BY re.architecture_id, em.metric_value');
        $this->executeBinary($statement, $params);
        $values = ['A' => [], 'B' => []];
        foreach ($statement as $row) {
            $architecture = (string) $row['architecture_id'];
            if (isset($values[$architecture])) {
                $values[$architecture][] = (float) $row['metric_value'];
            }
        }
        return $values;
    }

    /** @return array<string,array{executions:int,failures:int,recognized:int,unknown:int,inconclusive:int}> */
    public function resultCounts(?string $sessionToken = null): array
    {
        $sql = 'SELECT re.architecture_id, COUNT(*) executions, SUM(re.result = "error") failures,
            SUM(re.result = "recognized") recognized, SUM(re.result = "unknown") unknown,
            SUM(re.result = "inconclusive") inconclusive,
            SUM(re.result = "error" AND JSON_UNQUOTE(JSON_EXTRACT(re.metadata, "$.failure")) IN ("no_face", "multiple_faces", "facial_input_failure")) biometric_failures,
            SUM(re.result = "error" AND JSON_UNQUOTE(JSON_EXTRACT(re.metadata, "$.failure")) = "http_failure") service_http_failures
            FROM recognition_events re INNER JOIN video_sessions vs ON vs.id = re.video_session_id';
        $params = [];
        if ($sessionToken !== null) {
            $sql .= ' WHERE vs.session_group_id = :session_group_id';
            $params['session_group_id'] = $this->tokenBytes($sessionToken);
        }
        $sql .= ' GROUP BY re.architecture_id';
        $statement = $this->connection->pdo()->prepare($sql);
        $this->executeBinary($statement, $params);
        $counts = [];
        foreach ($statement as $row) {
            $counts[(string) $row['architecture_id']] = [
                'executions' => (int) $row['executions'],
                'failures' => (int) $row['failures'],
                'recognized' => (int) $row['recognized'],
                'unknown' => (int) $row['unknown'],
                'inconclusive' => (int) $row['inconclusive'],
                'biometric_failures' => (int) $row['biometric_failures'],
                'service_http_failures' => (int) $row['service_http_failures'],
                'internal_failures' => (int) $row['failures'] - (int) $row['biometric_failures'] - (int) $row['service_http_failures'],
            ];
        }
        $transport = $this->connection->pdo()->prepare('SELECT vs.architecture_id, COUNT(*) http_failures,
            SUM(NOT EXISTS (SELECT 1 FROM recognition_events re WHERE re.video_session_id=vs.id
                AND em.metric_name=CONCAT("http_failure_frame_", re.frame_sequence))) unresolved_http
            FROM execution_metrics em INNER JOIN video_sessions vs ON vs.id=em.video_session_id
            WHERE em.recognition_event_id IS NULL AND em.metric_name REGEXP "^http_failure_frame_[0-9]+$"'.
            ($sessionToken === null ? '' : ' AND vs.session_group_id=:session_group_id').' GROUP BY vs.architecture_id');
        $this->executeBinary($transport, $params);
        foreach ($transport as $row) {
            $architecture = (string) $row['architecture_id'];
            $counts[$architecture] ??= ['executions' => 0, 'failures' => 0, 'recognized' => 0, 'unknown' => 0, 'inconclusive' => 0, 'biometric_failures' => 0, 'service_http_failures' => 0, 'internal_failures' => 0];
            $counts[$architecture]['http_failures'] = (int) $row['http_failures'];
            $counts[$architecture]['unresolved_http'] = (int) $row['unresolved_http'];
        }
        foreach ($counts as &$count) $count += ['http_failures' => 0, 'unresolved_http' => 0];
        return $counts;
    }

    /** @return array<string,array<string,array{mean:float,count:int}>> */
    public function stageAverages(?string $sessionToken = null): array
    {
        $placeholders = implode(',', array_fill(0, count(self::STAGE_METRICS), '?'));
        $sql = "SELECT re.architecture_id, em.metric_name, AVG(em.metric_value) mean_value, COUNT(*) sample_count
            FROM recognition_events re INNER JOIN video_sessions vs ON vs.id = re.video_session_id
            INNER JOIN execution_metrics em ON em.recognition_event_id = re.id
            WHERE re.result IN ".self::VALID_RESULTS." AND em.metric_name IN ({$placeholders}) AND em.metric_value >= 0";
        $values = self::STAGE_METRICS;
        if ($sessionToken !== null) {
            $sql .= ' AND vs.session_group_id = ?';
            $values[] = $this->tokenBytes($sessionToken);
        }
        $sql .= ' GROUP BY re.architecture_id, em.metric_name ORDER BY re.architecture_id, em.metric_name';
        $statement = $this->connection->pdo()->prepare($sql);
        foreach ($values as $index => $value) {
            $statement->bindValue($index + 1, $value, $index === count($values) - 1 && $sessionToken !== null ? PDO::PARAM_LOB : PDO::PARAM_STR);
        }
        $statement->execute();
        $stages = ['A' => [], 'B' => []];
        foreach ($statement as $row) {
            $stages[(string) $row['architecture_id']][(string) $row['metric_name']] = ['mean' => round((float) $row['mean_value'], 4), 'count' => (int) $row['sample_count']];
        }
        return $stages;
    }

    /** @return array<string,array<string,list<float>>> */
    public function lifecycleValues(?string $sessionToken = null): array
    {
        $placeholders = implode(',', array_fill(0, count(self::LIFECYCLE_METRICS), '?'));
        $sql = "SELECT vs.architecture_id, em.metric_name, em.metric_value
            FROM execution_metrics em INNER JOIN video_sessions vs ON vs.id = em.video_session_id
            WHERE em.recognition_event_id IS NULL AND em.metric_name IN ({$placeholders}) AND em.metric_value >= 0";
        $values = self::LIFECYCLE_METRICS;
        if ($sessionToken !== null) {
            $sql .= ' AND vs.session_group_id = ?';
            $values[] = $this->tokenBytes($sessionToken);
        }
        $sql .= ' ORDER BY vs.architecture_id, em.metric_name, em.metric_value';
        $statement = $this->connection->pdo()->prepare($sql);
        foreach ($values as $index => $value) {
            $statement->bindValue($index + 1, $value, $index === count($values) - 1 && $sessionToken !== null ? PDO::PARAM_LOB : PDO::PARAM_STR);
        }
        $statement->execute();
        $result = ['A' => [], 'B' => []];
        foreach ($statement as $row) {
            $architecture = (string) $row['architecture_id'];
            $metric = (string) $row['metric_name'];
            if (isset($result[$architecture])) $result[$architecture][$metric][] = (float) $row['metric_value'];
        }
        return $result;
    }

    /** @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int,pages:int} */
    public function sessions(int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = max(5, min(50, $limit));
        $offset = ($page - 1) * $limit;
        $total = (int) $this->connection->pdo()->query('SELECT COUNT(DISTINCT session_group_id) FROM video_sessions WHERE session_group_id IS NOT NULL')->fetchColumn();
        $sql = 'SELECT LOWER(HEX(vs.session_group_id)) session_token,
            CASE WHEN COUNT(DISTINCT vs.status) = 1 THEN MIN(vs.status) ELSE "mixed" END status,
            MIN(vs.started_at) started_at, MAX(vs.ended_at) ended_at, MAX(vs.interval_ms) interval_ms,
            MAX(vs.missed_intervals) missed_intervals, MAX(vs.last_frame_sequence) last_frame_sequence,
            COUNT(re.id) event_count, SUM(re.result = "error") failure_count,
            SUM(re.result = "recognized") recognized_count, GROUP_CONCAT(DISTINCT vs.architecture_id ORDER BY vs.architecture_id) architectures
            FROM video_sessions vs LEFT JOIN recognition_events re ON re.video_session_id = vs.id
            WHERE vs.session_group_id IS NOT NULL GROUP BY vs.session_group_id
            ORDER BY MIN(vs.started_at) DESC LIMIT :limit OFFSET :offset';
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $items = [];
        foreach ($statement as $row) {
            $items[] = [
                'session_token' => (string) $row['session_token'], 'status' => (string) $row['status'],
                'started_at' => $row['started_at'], 'ended_at' => $row['ended_at'], 'interval_ms' => (int) $row['interval_ms'],
                'missed_intervals' => (int) $row['missed_intervals'], 'last_frame_sequence' => (int) $row['last_frame_sequence'],
                'event_count' => (int) $row['event_count'], 'failure_count' => (int) $row['failure_count'],
                'recognized_count' => (int) $row['recognized_count'], 'architectures' => (string) $row['architectures'],
            ];
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => max(1, (int) ceil($total / $limit))];
    }

    /** @return list<array<string,mixed>> */
    public function pairedFrames(?string $sessionToken, int $limit = 100): array
    {
        $sql = 'SELECT LOWER(HEX(vs.session_group_id)) session_token, re.frame_sequence,
            MAX(re.occurred_at) occurred_at,
            MAX(CASE WHEN re.architecture_id = "A" THEN re.result END) result_a,
            MAX(CASE WHEN re.architecture_id = "B" THEN re.result END) result_b,
            MAX(CASE WHEN re.architecture_id = "A" THEN re.matched_identity_id END) identity_a,
            MAX(CASE WHEN re.architecture_id = "B" THEN re.matched_identity_id END) identity_b,
            MAX(CASE WHEN re.architecture_id = "A" THEN total.metric_value END) total_a_ms,
            MAX(CASE WHEN re.architecture_id = "B" THEN total.metric_value END) total_b_ms,
            MAX(CASE WHEN re.architecture_id = "A" THEN NULLIF(JSON_UNQUOTE(JSON_EXTRACT(re.metadata, "$.failure")), "null") END) failure_a,
            MAX(CASE WHEN re.architecture_id = "B" THEN NULLIF(JSON_UNQUOTE(JSON_EXTRACT(re.metadata, "$.failure")), "null") END) failure_b,
            MAX(CASE WHEN re.architecture_id = "A" THEN JSON_EXTRACT(re.metadata, "$.best_candidate_identity_id") END) best_candidate_identity_a,
            MAX(CASE WHEN re.architecture_id = "B" THEN JSON_EXTRACT(re.metadata, "$.best_candidate_identity_id") END) best_candidate_identity_b,
            MAX(CASE WHEN re.architecture_id = "A" THEN JSON_EXTRACT(re.metadata, "$.best_candidate_distance_squared") END) best_candidate_distance_a,
            MAX(CASE WHEN re.architecture_id = "B" THEN JSON_EXTRACT(re.metadata, "$.best_candidate_distance_squared") END) best_candidate_distance_b,
            MAX(CASE WHEN re.architecture_id = "A" THEN JSON_EXTRACT(re.metadata, "$.second_candidate_identity_id") END) second_candidate_identity_a,
            MAX(CASE WHEN re.architecture_id = "B" THEN JSON_EXTRACT(re.metadata, "$.second_candidate_identity_id") END) second_candidate_identity_b,
            MAX(CASE WHEN re.architecture_id = "A" THEN JSON_EXTRACT(re.metadata, "$.second_candidate_distance_squared") END) second_candidate_distance_a,
            MAX(CASE WHEN re.architecture_id = "B" THEN JSON_EXTRACT(re.metadata, "$.second_candidate_distance_squared") END) second_candidate_distance_b,
            MAX(CASE WHEN re.architecture_id = "A" THEN JSON_EXTRACT(re.metadata, "$.candidate_distance_delta_squared") END) candidate_distance_delta_a,
            MAX(CASE WHEN re.architecture_id = "B" THEN JSON_EXTRACT(re.metadata, "$.candidate_distance_delta_squared") END) candidate_distance_delta_b,
            MAX(CASE WHEN re.architecture_id = "A" THEN JSON_EXTRACT(re.metadata, "$.ambiguity_margin_squared") END) ambiguity_margin_a,
            MAX(CASE WHEN re.architecture_id = "B" THEN JSON_EXTRACT(re.metadata, "$.ambiguity_margin_squared") END) ambiguity_margin_b,
            MAX(CASE WHEN re.architecture_id = "A" THEN NULLIF(JSON_UNQUOTE(JSON_EXTRACT(re.metadata, "$.decision_reason")), "null") END) decision_reason_a,
            MAX(CASE WHEN re.architecture_id = "B" THEN NULLIF(JSON_UNQUOTE(JSON_EXTRACT(re.metadata, "$.decision_reason")), "null") END) decision_reason_b,
            COUNT(DISTINCT re.architecture_id) architecture_count
            FROM recognition_events re INNER JOIN video_sessions vs ON vs.id = re.video_session_id
            LEFT JOIN execution_metrics total ON total.recognition_event_id = re.id AND total.metric_name = "end_to_end_ms"';
        $params = [];
        if ($sessionToken !== null) {
            $sql .= ' WHERE vs.session_group_id = :session_group_id';
            $params['session_group_id'] = $this->tokenBytes($sessionToken);
        }
        $sql .= ' GROUP BY vs.session_group_id, re.frame_sequence ORDER BY MAX(re.occurred_at) DESC LIMIT :limit';
        $statement = $this->connection->pdo()->prepare($sql);
        if (isset($params['session_group_id'])) $statement->bindValue(':session_group_id', $params['session_group_id'], PDO::PARAM_LOB);
        $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $frames = [];
        foreach ($statement as $row) {
            $frames[] = [
                'session_token' => (string) $row['session_token'], 'frame_sequence' => (int) $row['frame_sequence'], 'occurred_at' => $row['occurred_at'],
                'result_a' => $row['result_a'], 'result_b' => $row['result_b'],
                'identity_a' => $row['identity_a'] === null ? null : (int) $row['identity_a'], 'identity_b' => $row['identity_b'] === null ? null : (int) $row['identity_b'],
                'total_a_ms' => $row['total_a_ms'] === null ? null : (float) $row['total_a_ms'], 'total_b_ms' => $row['total_b_ms'] === null ? null : (float) $row['total_b_ms'],
                'difference_ms' => $row['total_a_ms'] === null || $row['total_b_ms'] === null ? null : round((float) $row['total_b_ms'] - (float) $row['total_a_ms'], 4),
                'failure_a' => $row['failure_a'], 'failure_b' => $row['failure_b'], 'complete' => (int) $row['architecture_count'] === 2,
                'best_candidate_identity_a' => $this->jsonInteger($row['best_candidate_identity_a']), 'best_candidate_identity_b' => $this->jsonInteger($row['best_candidate_identity_b']),
                'best_candidate_distance_a' => $this->jsonFloat($row['best_candidate_distance_a']), 'best_candidate_distance_b' => $this->jsonFloat($row['best_candidate_distance_b']),
                'second_candidate_identity_a' => $this->jsonInteger($row['second_candidate_identity_a']), 'second_candidate_identity_b' => $this->jsonInteger($row['second_candidate_identity_b']),
                'second_candidate_distance_a' => $this->jsonFloat($row['second_candidate_distance_a']), 'second_candidate_distance_b' => $this->jsonFloat($row['second_candidate_distance_b']),
                'candidate_distance_delta_a' => $this->jsonFloat($row['candidate_distance_delta_a']), 'candidate_distance_delta_b' => $this->jsonFloat($row['candidate_distance_delta_b']),
                'ambiguity_margin_a' => $this->jsonFloat($row['ambiguity_margin_a']), 'ambiguity_margin_b' => $this->jsonFloat($row['ambiguity_margin_b']),
                'decision_reason_a' => $row['decision_reason_a'], 'decision_reason_b' => $row['decision_reason_b'],
            ];
        }
        return $frames;
    }

    /** @return array{sessions:int,events:int,metrics:int} */
    public function deleteSession(string $sessionToken): array
    {
        return $this->connection->transaction(function (PDO $pdo) use ($sessionToken): array {
            $group = $this->tokenBytes($sessionToken);
            $status = $pdo->prepare('SELECT status FROM video_sessions WHERE session_group_id = :group_id FOR UPDATE');
            $status->bindValue(':group_id', $group, PDO::PARAM_LOB);
            $status->execute();
            $statuses = $status->fetchAll(PDO::FETCH_COLUMN);
            if (count($statuses) !== 2) throw new DomainException('Sessao nao encontrada.', 404);
            if (array_intersect($statuses, ['running', 'paused']) !== []) throw new DomainException('Encerre a sessao antes de exclui-la.', 409);
            $counts = $this->groupCounts($pdo, $group);
            $delete = $pdo->prepare('DELETE FROM video_sessions WHERE session_group_id = :group_id');
            $delete->bindValue(':group_id', $group, PDO::PARAM_LOB);
            $delete->execute();
            return $counts;
        });
    }

    /** @return array{sessions:int,events:int,metrics:int} */
    public function clearAll(): array
    {
        return $this->connection->transaction(function (PDO $pdo): array {
            $active = (int) $pdo->query('SELECT COUNT(*) FROM video_sessions WHERE status IN ("running", "paused") FOR UPDATE')->fetchColumn();
            if ($active > 0) throw new DomainException('Encerre as sessoes ativas antes da limpeza.', 409);
            $counts = [
                'sessions' => (int) $pdo->query('SELECT COUNT(*) FROM video_sessions')->fetchColumn(),
                'events' => (int) $pdo->query('SELECT COUNT(*) FROM recognition_events')->fetchColumn(),
                'metrics' => (int) $pdo->query('SELECT COUNT(*) FROM execution_metrics')->fetchColumn(),
            ];
            $pdo->exec('DELETE FROM video_sessions');
            return $counts;
        });
    }

    /** @return Generator<int,array<string,mixed>> */
    public function exportRows(?string $sessionToken = null): Generator
    {
        $metrics = self::EXPORT_METRICS;
        $metricColumns = implode(",\n", array_map(static fn(string $name): string => "MAX(CASE WHEN em.metric_name = '{$name}' THEN em.metric_value END) {$name}", $metrics));
        $sql = "SELECT 'recognition' record_type, LOWER(HEX(vs.session_group_id)) session_token, vs.id video_session_id,
            vs.status session_status, vs.interval_ms, vs.started_at session_started_at, vs.ended_at session_ended_at,
            re.frame_sequence, re.occurred_at, re.architecture_id architecture, re.result, re.matched_identity_id,
            re.score distance_squared, re.threshold threshold_squared,
            JSON_UNQUOTE(JSON_EXTRACT(re.metadata, '$.compared_embeddings')) compared_embeddings,
            JSON_UNQUOTE(JSON_EXTRACT(re.metadata, '$.failure')) failure,
            JSON_EXTRACT(re.metadata, '$.best_candidate_identity_id') best_candidate_identity_id,
            JSON_EXTRACT(re.metadata, '$.best_candidate_distance_squared') best_candidate_distance_squared,
            JSON_EXTRACT(re.metadata, '$.second_candidate_identity_id') second_candidate_identity_id,
            JSON_EXTRACT(re.metadata, '$.second_candidate_distance_squared') second_candidate_distance_squared,
            JSON_EXTRACT(re.metadata, '$.candidate_distance_delta_squared') candidate_distance_delta_squared,
            JSON_EXTRACT(re.metadata, '$.ambiguity_margin_squared') ambiguity_margin_squared,
            NULLIF(JSON_UNQUOTE(JSON_EXTRACT(re.metadata, '$.decision_reason')), 'null') decision_reason,
            ((SELECT COUNT(DISTINCT pair.architecture_id) FROM recognition_events pair
                INNER JOIN video_sessions pair_vs ON pair_vs.id = pair.video_session_id
                WHERE pair_vs.session_group_id = vs.session_group_id AND pair.frame_sequence = re.frame_sequence) = 2) pair_complete,
            NULL metric_name, NULL metric_value, NULL metric_unit, {$metricColumns}
            FROM recognition_events re INNER JOIN video_sessions vs ON vs.id = re.video_session_id
            LEFT JOIN execution_metrics em ON em.recognition_event_id = re.id";
        if ($sessionToken !== null) $sql .= ' WHERE vs.session_group_id = :session_group_id';
        $sql .= ' GROUP BY re.id, vs.id ORDER BY vs.started_at, re.frame_sequence, re.architecture_id';
        $statement = $this->connection->pdo()->prepare($sql);
        if ($sessionToken !== null) {
            $statement->bindValue(':session_group_id', $this->tokenBytes($sessionToken), PDO::PARAM_LOB);
        }
        $statement->execute();
        while (($row = $statement->fetch()) !== false) yield $row;

        $sessionSql = "SELECT 'session_metric' record_type, LOWER(HEX(vs.session_group_id)) session_token, vs.id video_session_id,
            vs.status session_status, vs.interval_ms, vs.started_at session_started_at, vs.ended_at session_ended_at,
            NULL frame_sequence, em.recorded_at occurred_at, vs.architecture_id architecture, NULL result,
            NULL matched_identity_id, NULL distance_squared, NULL threshold_squared, NULL compared_embeddings,
            NULL failure, NULL best_candidate_identity_id, NULL best_candidate_distance_squared, NULL second_candidate_identity_id,
            NULL second_candidate_distance_squared, NULL candidate_distance_delta_squared, NULL ambiguity_margin_squared, NULL decision_reason,
            NULL pair_complete, em.metric_name, em.metric_value, em.unit metric_unit";
        foreach ($metrics as $name) $sessionSql .= ", NULL {$name}";
        $sessionSql .= ' FROM execution_metrics em INNER JOIN video_sessions vs ON vs.id = em.video_session_id
            WHERE em.recognition_event_id IS NULL';
        if ($sessionToken !== null) $sessionSql .= ' AND vs.session_group_id = :session_group_id';
        $sessionSql .= ' ORDER BY vs.started_at, em.recorded_at, vs.architecture_id';
        $sessionStatement = $this->connection->pdo()->prepare($sessionSql);
        if ($sessionToken !== null) $sessionStatement->bindValue(':session_group_id', $this->tokenBytes($sessionToken), PDO::PARAM_LOB);
        $sessionStatement->execute();
        while (($row = $sessionStatement->fetch()) !== false) yield $row;
    }

    /** @return array{sessions:int,events:int,metrics:int} */
    private function groupCounts(PDO $pdo, string $group): array
    {
        $query = $pdo->prepare('SELECT
            (SELECT COUNT(*) FROM video_sessions WHERE session_group_id = :group_sessions) sessions,
            (SELECT COUNT(*) FROM recognition_events re INNER JOIN video_sessions vs ON vs.id = re.video_session_id WHERE vs.session_group_id = :group_events) events,
            (SELECT COUNT(*) FROM execution_metrics em INNER JOIN video_sessions vs2 ON vs2.id = em.video_session_id WHERE vs2.session_group_id = :group_metrics) metrics');
        $query->bindValue(':group_sessions', $group, PDO::PARAM_LOB);
        $query->bindValue(':group_events', $group, PDO::PARAM_LOB);
        $query->bindValue(':group_metrics', $group, PDO::PARAM_LOB);
        $query->execute();
        $row = $query->fetch();
        return ['sessions' => (int) $row['sessions'], 'events' => (int) $row['events'], 'metrics' => (int) $row['metrics']];
    }

    private function tokenBytes(string $token): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1 || !is_string($bytes = hex2bin($token))) {
            throw new DomainException('Identificador de sessao invalido.', 422);
        }
        return $bytes;
    }

    private function jsonInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function jsonFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** @param array<string,string> $params */
    private function executeBinary(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) $statement->bindValue(':'.$name, $value, PDO::PARAM_LOB);
        $statement->execute();
    }
}
