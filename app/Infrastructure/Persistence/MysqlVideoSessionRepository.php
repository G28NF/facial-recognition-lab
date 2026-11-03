<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\PdoConnection;
use DomainException;
use PDO;

final class MysqlVideoSessionRepository
{
    public function __construct(private readonly PdoConnection $connection) {}

    /** @param array{A:array<string,float>|float,B:array<string,float>|float} $initializationMetrics */
    public function start(int $intervalMs, array $initializationMetrics, ?int $expectedIdentityId = null): string
    {
        $group = random_bytes(16);
        $token = bin2hex($group);
        $this->connection->transaction(function (PDO $pdo) use ($group, $intervalMs, $initializationMetrics, $expectedIdentityId): void {
            $pdo->exec('UPDATE video_sessions SET status = "interrupted", ended_at = UTC_TIMESTAMP(3), paused_at = NULL
                WHERE status IN ("running", "paused") AND JSON_UNQUOTE(JSON_EXTRACT(metadata, "$.mode")) = "continuous_comparative"');
            $insert = $pdo->prepare('INSERT INTO video_sessions
                (session_group_id, public_id, architecture_id, status, interval_ms, started_at, metadata)
                VALUES (:session_group_id, :public_id, :architecture_id, "running", :interval_ms, UTC_TIMESTAMP(3), :metadata)');
            foreach (['A', 'B'] as $architecture) {
                $insert->bindValue(':session_group_id', $group, PDO::PARAM_LOB);
                $insert->bindValue(':public_id', random_bytes(16), PDO::PARAM_LOB);
                $insert->bindValue(':architecture_id', $architecture);
                $insert->bindValue(':interval_ms', $intervalMs, PDO::PARAM_INT);
                $insert->bindValue(':metadata', json_encode(['mode' => 'continuous_comparative'], JSON_THROW_ON_ERROR));
                $insert->execute();
                $sessionId = (int) $pdo->lastInsertId();
                $metrics = is_array($initializationMetrics[$architecture])
                    ? $initializationMetrics[$architecture]
                    : ['initialization_ms' => $initializationMetrics[$architecture]];
                foreach ($this->sanitizeMetrics($metrics) as $name => $value) {
                    $this->insertSessionMetric($pdo, $sessionId, $name, $value, 'ms');
                }
            }
            if ($expectedIdentityId !== null) $this->openEvaluationSegment($pdo, $group, $expectedIdentityId);
        });
        return $token;
    }

    public function changeEvaluationIdentity(string $token, ?int $identityId): void
    {
        $this->connection->transaction(function (PDO $pdo) use ($token, $identityId): void {
            $group = $this->groupBytes($token);
            $sessions = $this->sessions($pdo, $token, true); $this->requireRunningOrPaused($sessions);
            $open = $pdo->prepare('SELECT id, expected_identity_id FROM webcam_evaluation_segments WHERE session_group_id=:group AND ended_at IS NULL FOR UPDATE');
            $open->bindValue(':group', $group, PDO::PARAM_LOB); $open->execute(); $row = $open->fetch();
            if ($identityId === null) { if (is_array($row)) $pdo->prepare('UPDATE webcam_evaluation_segments SET ended_at=UTC_TIMESTAMP(3) WHERE id=:id')->execute(['id'=>$row['id']]); return; }
            if (is_array($row) && (int)$row['expected_identity_id'] === $identityId) return;
            if (is_array($row)) $pdo->prepare('UPDATE webcam_evaluation_segments SET ended_at=UTC_TIMESTAMP(3) WHERE id=:id')->execute(['id'=>$row['id']]);
            $this->openEvaluationSegment($pdo, $group, $identityId);
        });
    }

    public function pause(string $token): void
    {
        $this->transition($token, 'running', 'paused', 'paused_at = UTC_TIMESTAMP(3)');
    }

    public function resume(string $token): void
    {
        $set = 'total_paused_ms = total_paused_ms + ROUND(TIMESTAMPDIFF(MICROSECOND, paused_at, UTC_TIMESTAMP(3)) / 1000), paused_at = NULL';
        $this->transition($token, 'paused', 'running', $set);
    }

    public function end(string $token): void
    {
        $group = $this->groupBytes($token);
        $statement = $this->connection->pdo()->prepare('UPDATE video_sessions SET
            total_paused_ms = total_paused_ms + IF(paused_at IS NULL, 0, ROUND(TIMESTAMPDIFF(MICROSECOND, paused_at, UTC_TIMESTAMP(3)) / 1000)),
            paused_at = NULL, ended_at = UTC_TIMESTAMP(3), status = "ended"
            WHERE session_group_id = :session_group_id AND status IN ("running", "paused")');
        $statement->bindValue(':session_group_id', $group, PDO::PARAM_LOB);
        $statement->execute();
        if ($statement->rowCount() !== 2) {
            throw new DomainException('Sessao nao pode ser encerrada neste estado.', 409);
        }
    }

    /** @param array<string,int|float> $metrics */
    public function recordMonitoring(string $token, int $frameSequence, int $missedIntervals, array $metrics): void
    {
        $this->connection->transaction(function (PDO $pdo) use ($token, $frameSequence, $missedIntervals, $metrics): void {
            $sessions = $this->sessions($pdo, $token, true);
            $this->requireRunning($sessions);
            $this->advance($pdo, $sessions['ids'], $frameSequence, $missedIntervals);
            foreach ($this->sanitizeMetrics($metrics) as $name => $value) {
                $this->insertSessionMetric($pdo, $sessions['ids']['A'], $name, $value, str_ends_with($name, '_count') ? 'count' : 'ms');
            }
        });
    }

    /** @param array{A:float,B:float} $warmupMetrics @param array<string,int|float> $monitoringMetrics */
    public function recordWarmup(string $token, int $frameSequence, int $missedIntervals, array $warmupMetrics, array $monitoringMetrics, ?float $faceapiFirstInferenceMs = null): void
    {
        $this->connection->transaction(function (PDO $pdo) use ($token, $frameSequence, $missedIntervals, $warmupMetrics, $faceapiFirstInferenceMs, $monitoringMetrics): void {
            $sessions = $this->sessions($pdo, $token, true);
            $this->requireRunning($sessions);
            $this->advance($pdo, $sessions['ids'], $frameSequence, $missedIntervals);
            $this->insertSessionMetric($pdo, $sessions['ids']['A'], 'warmup_ms', $warmupMetrics['A'], 'ms');
            $this->insertSessionMetric($pdo, $sessions['ids']['B'], 'warmup_ms', $warmupMetrics['B'], 'ms');
            if ($faceapiFirstInferenceMs !== null) $this->insertSessionMetric($pdo, $sessions['ids']['A'], 'first_inference_ms', $faceapiFirstInferenceMs, 'ms');
            $this->insertSessionMetric($pdo, $sessions['ids']['B'], 'first_inference_ms', $warmupMetrics['B'], 'ms');
            foreach ($this->sanitizeMetrics($monitoringMetrics) as $name => $value) {
                $this->insertSessionMetric($pdo, $sessions['ids']['A'], $name, $value, str_ends_with($name, '_count') ? 'count' : 'ms');
            }
        });
    }

    /** @param array<string,array<string,mixed>> $results @param array<string,float|int> $monitoringMetrics @param array<string,float> $thresholds */
    public function recordRound(string $token, int $frameSequence, int $missedIntervals, array $results, array $monitoringMetrics, array $thresholds): void
    {
        $this->connection->transaction(function (PDO $pdo) use ($token, $frameSequence, $missedIntervals, $results, $monitoringMetrics, $thresholds): void {
            $sessions = $this->sessions($pdo, $token, true);
            $this->requireRunning($sessions);
            $this->advance($pdo, $sessions['ids'], $frameSequence, $missedIntervals);
            foreach ($this->sanitizeMetrics($monitoringMetrics) as $name => $value) {
                $this->insertSessionMetric($pdo, $sessions['ids']['A'], $name, $value, str_ends_with($name, '_count') ? 'count' : 'ms');
            }
            $events = [];
            foreach (['A', 'B'] as $architecture) {
                $result = $results[$architecture];
                $status = in_array($result['status'] ?? null, ['recognized', 'unknown', 'inconclusive', 'error'], true) ? $result['status'] : 'error';
                $event = $pdo->prepare('INSERT INTO recognition_events
                    (video_session_id, architecture_id, matched_identity_id, frame_sequence, result, score, threshold, occurred_at, metadata)
                    VALUES (:session_id, :architecture_id, :identity_id, :frame_sequence, :result, :score, :threshold, UTC_TIMESTAMP(3), :metadata)');
                $event->execute([
                    'session_id' => $sessions['ids'][$architecture],
                    'architecture_id' => $architecture,
                    'identity_id' => $status === 'recognized' ? ($result['id'] ?? null) : null,
                    'frame_sequence' => $frameSequence,
                    'result' => $status,
                    'score' => is_numeric($result['distance_squared'] ?? null) ? $result['distance_squared'] : null,
                    'threshold' => $thresholds[$architecture],
                    'metadata' => json_encode([
                        'compared_embeddings' => (int) ($result['compared_embeddings'] ?? 0),
                        'failure' => $status === 'error' ? (string) ($result['error'] ?? 'processing_failure') : null,
                        'best_candidate_identity_id' => isset($result['best_candidate_identity_id']) ? (int) $result['best_candidate_identity_id'] : null,
                        'best_candidate_distance_squared' => is_numeric($result['best_candidate_distance_squared'] ?? null) ? (float) $result['best_candidate_distance_squared'] : null,
                        'second_candidate_identity_id' => isset($result['second_candidate_identity_id']) ? (int) $result['second_candidate_identity_id'] : null,
                        'second_candidate_distance_squared' => is_numeric($result['second_candidate_distance_squared'] ?? null) ? (float) $result['second_candidate_distance_squared'] : null,
                        'candidate_distance_delta_squared' => is_numeric($result['candidate_distance_delta_squared'] ?? null) ? (float) $result['candidate_distance_delta_squared'] : null,
                        'ambiguity_margin_squared' => is_numeric($result['ambiguity_margin_squared'] ?? null) ? (float) $result['ambiguity_margin_squared'] : null,
                        'decision_reason' => is_string($result['decision_reason'] ?? null) ? $result['decision_reason'] : ($status === 'error' ? 'technical_failure' : $status),
                    ], JSON_THROW_ON_ERROR),
                ]);
                $eventId = (int) $pdo->lastInsertId();
                $events[$architecture] = $eventId;
                foreach ($this->sanitizeMetrics(is_array($result['metrics'] ?? null) ? $result['metrics'] : []) as $name => $value) {
                    $this->insertEventMetric($pdo, $eventId, $sessions['ids'][$architecture], $name, $value);
                }
            }
            $this->recordSupervisedResults($pdo, $sessions['group'], $events, $results);
        });
    }

    /** @param array<string,int|float> $metrics */
    public function finalizeRound(string $token, int $frameSequence, array $metrics): void
    {
        $pdo = $this->connection->pdo();
        $group = $this->groupBytes($token);
        $events = $pdo->prepare('SELECT re.id, re.video_session_id FROM recognition_events re
            INNER JOIN video_sessions vs ON vs.id = re.video_session_id
            WHERE vs.session_group_id = :session_group_id AND re.frame_sequence = :frame_sequence');
        $events->bindValue(':session_group_id', $group, PDO::PARAM_LOB);
        $events->bindValue(':frame_sequence', $frameSequence, PDO::PARAM_INT);
        $events->execute();
        $rows = $events->fetchAll();
        if (count($rows) !== 2) {
            throw new DomainException('Rodada comparativa nao encontrada.', 404);
        }
        foreach ($rows as $row) {
            foreach ($this->sanitizeMetrics($metrics) as $name => $value) {
                $this->insertEventMetric($pdo, (int) $row['id'], (int) $row['video_session_id'], $name, $value);
            }
        }
    }

    /** @return array{status:string,interval_ms:int,missed_intervals:int,last_frame_sequence:int} */
    public function status(string $token): array
    {
        $group = $this->groupBytes($token);
        $statement = $this->connection->pdo()->prepare('SELECT status, interval_ms, missed_intervals, last_frame_sequence
            FROM video_sessions WHERE session_group_id = :session_group_id AND architecture_id = "A"');
        $statement->bindValue(':session_group_id', $group, PDO::PARAM_LOB);
        $statement->execute();
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new DomainException('Sessao nao encontrada.', 404);
        }
        return ['status' => (string) $row['status'], 'interval_ms' => (int) $row['interval_ms'], 'missed_intervals' => (int) $row['missed_intervals'], 'last_frame_sequence' => (int) $row['last_frame_sequence']];
    }

    /** @param array<string,int> $sessions */
    private function advance(PDO $pdo, array $sessions, int $frameSequence, int $missedIntervals): void
    {
        if ($frameSequence < 1 || $missedIntervals < 0 || $missedIntervals > 100000) {
            throw new DomainException('Sequencia ou intervalos perdidos invalidos.', 422);
        }
        $statement = $pdo->prepare('UPDATE video_sessions SET missed_intervals = missed_intervals + :missed_intervals + CASE
                WHEN :sequence_for_gap_condition > last_frame_sequence + 1 THEN :sequence_for_gap_value - last_frame_sequence - 1
                ELSE 0 END,
            last_frame_sequence = :new_frame_sequence
            WHERE id = :id AND last_frame_sequence < :previous_frame_sequence');
        foreach ($sessions as $id) {
            $statement->execute(['new_frame_sequence' => $frameSequence, 'sequence_for_gap_condition' => $frameSequence, 'sequence_for_gap_value' => $frameSequence, 'previous_frame_sequence' => $frameSequence, 'missed_intervals' => $missedIntervals, 'id' => $id]);
            if ($statement->rowCount() !== 1) {
                throw new DomainException('Quadro antigo ou duplicado descartado.', 409);
            }
        }
    }

    private function transition(string $token, string $from, string $to, string $extraSet): void
    {
        $group = $this->groupBytes($token);
        $statement = $this->connection->pdo()->prepare("UPDATE video_sessions SET status = :to_status, {$extraSet}
            WHERE session_group_id = :session_group_id AND status = :from_status");
        $statement->bindValue(':to_status', $to);
        $statement->bindValue(':session_group_id', $group, PDO::PARAM_LOB);
        $statement->bindValue(':from_status', $from);
        $statement->execute();
        if ($statement->rowCount() !== 2) {
            throw new DomainException('Transicao de sessao invalida.', 409);
        }
    }

    /** @return array{ids:array<string,int>,statuses:array<string,string>,group:string} */
    private function sessions(PDO $pdo, string $token, bool $lock): array
    {
        $statement = $pdo->prepare('SELECT id, architecture_id, status FROM video_sessions
            WHERE session_group_id = :session_group_id'.($lock ? ' FOR UPDATE' : ''));
        $statement->bindValue(':session_group_id', $this->groupBytes($token), PDO::PARAM_LOB);
        $statement->execute();
        $ids = [];
        $statuses = [];
        foreach ($statement as $row) {
            $architecture = (string) $row['architecture_id'];
            $ids[$architecture] = (int) $row['id'];
            $statuses[$architecture] = (string) $row['status'];
        }
        if (!isset($ids['A'], $ids['B'], $statuses['A'], $statuses['B'])) {
            throw new DomainException('Sessao nao encontrada.', 404);
        }
        return ['ids' => $ids, 'statuses' => $statuses, 'group' => $this->groupBytes($token)];
    }

    /** @param array{ids:array<string,int>,statuses:array<string,string>} $sessions */
    private function requireRunning(array $sessions): void
    {
        if ($sessions['statuses']['A'] !== 'running' || $sessions['statuses']['B'] !== 'running') {
            throw new DomainException('Sessao nao esta em execucao.', 409);
        }
    }

    private function requireRunningOrPaused(array $sessions): void
    {
        if (!in_array($sessions['statuses']['A'], ['running','paused'], true) || !in_array($sessions['statuses']['B'], ['running','paused'], true)) throw new DomainException('Sessao nao esta disponivel.', 409);
    }

    private function openEvaluationSegment(PDO $pdo, string $group, int $identityId): void
    {
        $identity = $pdo->prepare('SELECT id FROM identities WHERE id=:id AND active=1'); $identity->execute(['id'=>$identityId]);
        if ($identity->fetchColumn() === false) throw new DomainException('Identidade de referencia invalida.', 422);
        $number = $pdo->prepare('SELECT COALESCE(MAX(segment_number),0)+1 FROM webcam_evaluation_segments WHERE session_group_id=:group');
        $number->bindValue(':group',$group,PDO::PARAM_LOB); $number->execute();
        $insert=$pdo->prepare('INSERT INTO webcam_evaluation_segments (session_group_id,expected_identity_id,segment_number,started_at) VALUES (:group,:identity,:number,UTC_TIMESTAMP(3))');
        $insert->bindValue(':group',$group,PDO::PARAM_LOB); $insert->bindValue(':identity',$identityId,PDO::PARAM_INT); $insert->bindValue(':number',(int)$number->fetchColumn(),PDO::PARAM_INT); $insert->execute();
    }

    private function recordSupervisedResults(PDO $pdo, string $group, array $events, array $results): void
    {
        $segment=$pdo->prepare('SELECT id,expected_identity_id FROM webcam_evaluation_segments WHERE session_group_id=:group AND ended_at IS NULL LIMIT 1');
        $segment->bindValue(':group',$group,PDO::PARAM_LOB); $segment->execute(); $row=$segment->fetch(); if (!is_array($row)) return;
        $insert=$pdo->prepare('INSERT INTO webcam_evaluation_results (webcam_evaluation_segment_id,recognition_event_id,expected_identity_id,predicted_identity_id,outcome,created_at) VALUES (:segment,:event,:expected,:predicted,:outcome,UTC_TIMESTAMP(3))');
        foreach (['A','B'] as $architecture) { $result=$results[$architecture]; $status=$result['status'] ?? 'error'; $predicted=$status==='recognized' ? ($result['id'] ?? null) : null;
            $outcome=$status==='error' ? 'technical_failure' : ($status==='recognized' ? ((int)$predicted===(int)$row['expected_identity_id'] ? 'correct':'incorrect') : 'not_recognized');
            $insert->execute(['segment'=>$row['id'],'event'=>$events[$architecture],'expected'=>$row['expected_identity_id'],'predicted'=>$predicted,'outcome'=>$outcome]); }
    }

    private function groupBytes(string $token): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new DomainException('Identificador de sessao invalido.', 422);
        }
        $bytes = hex2bin($token);
        if (!is_string($bytes)) {
            throw new DomainException('Identificador de sessao invalido.', 422);
        }
        return $bytes;
    }

    /** @param array<string,mixed> $metrics @return array<string,float> */
    private function sanitizeMetrics(array $metrics): array
    {
        $safe = [];
        foreach ($metrics as $name => $value) {
            if (preg_match('/^[a-z][a-z0-9_]{1,78}$/D', $name) === 1 && is_numeric($value) && is_finite((float) $value) && (float) $value >= 0) {
                $safe[$name] = round((float) $value, 4);
            }
        }
        return $safe;
    }

    private function insertSessionMetric(PDO $pdo, int $sessionId, string $name, float $value, string $unit): void
    {
        $statement = $pdo->prepare('INSERT INTO execution_metrics
            (recognition_event_id, video_session_id, metric_name, metric_value, unit, recorded_at)
            VALUES (NULL, :session_id, :metric_name, :metric_value, :unit, UTC_TIMESTAMP(3))');
        $statement->execute(['session_id' => $sessionId, 'metric_name' => $name, 'metric_value' => $value, 'unit' => $unit]);
    }

    private function insertEventMetric(PDO $pdo, int $eventId, int $sessionId, string $name, float $value): void
    {
        $statement = $pdo->prepare('INSERT INTO execution_metrics
            (recognition_event_id, video_session_id, metric_name, metric_value, unit, recorded_at)
            VALUES (:event_id, :session_id, :metric_name, :metric_value, "ms", UTC_TIMESTAMP(3))
            ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value), recorded_at = VALUES(recorded_at)');
        $statement->execute(['event_id' => $eventId, 'session_id' => $sessionId, 'metric_name' => $name, 'metric_value' => $value]);
    }
}
