<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\PdoConnection;
use App\Infrastructure\Security\AuthenticatedCipher;
use DomainException;
use InvalidArgumentException;
use PDO;

final class MysqlProbeEvaluationRepository
{
    public function __construct(
        private readonly PdoConnection $connection,
        private readonly AuthenticatedCipher $cipher
    ) {}

    /** @return list<array{import_token:string,dataset_name:string,eligible_samples:int}> */
    public function availableImports(): array
    {
        $sql = 'SELECT LOWER(HEX(di.public_id)) import_token, d.name dataset_name, COUNT(DISTINCT ds.id) eligible_samples
            FROM dataset_imports di
            INNER JOIN datasets d ON d.id = di.dataset_id
            INNER JOIN dataset_samples ds ON ds.dataset_import_id = di.id AND ds.status = "processed" AND ds.split_name = "probe"
            INNER JOIN dataset_import_identities dii ON dii.id = ds.import_identity_id
            INNER JOIN identities i ON i.id = dii.identity_id AND i.active = 1
            INNER JOIN dataset_sample_embeddings fa ON fa.dataset_sample_id = ds.id AND fa.usage_type = "probe"
            INNER JOIN biometric_models fm ON fm.id = fa.biometric_model_id AND fm.model_key = "faceapi-recognition"
            INNER JOIN dataset_sample_embeddings sf ON sf.dataset_sample_id = ds.id AND sf.usage_type = "probe"
            INNER JOIN biometric_models sm ON sm.id = sf.biometric_model_id AND sm.model_key = "opencv-sface"
            GROUP BY di.id, di.public_id, d.name ORDER BY di.id DESC LIMIT 20';
        $rows = $this->connection->pdo()->query($sql)->fetchAll();
        return array_map(static fn(array $row): array => [
            'import_token' => (string) $row['import_token'],
            'dataset_name' => (string) $row['dataset_name'],
            'eligible_samples' => (int) $row['eligible_samples'],
        ], $rows);
    }

    /** @return array{evaluation_token:string,eligible_samples:int,requested_samples:int} */
    public function start(string $importToken, int $requestedSamples): array
    {
        if ($requestedSamples < 1 || $requestedSamples > 200) throw new InvalidArgumentException('Quantidade de probes para avaliacao invalida.');
        return $this->connection->transaction(function (PDO $pdo) use ($importToken, $requestedSamples): array {
            $import = $this->import($pdo, $importToken, true);
            $eligible = $this->eligibleCount($pdo, (int) $import['id']);
            if ($eligible === 0) throw new DomainException('Nao existem probes validas com embeddings A e B para esta importacao.', 409);
            $publicId = random_bytes(16);
            $statement = $pdo->prepare('INSERT INTO probe_evaluations
                (public_id, dataset_import_id, requested_samples, eligible_samples, status, started_at)
                VALUES (:public_id, :import_id, :requested, :eligible, "running", UTC_TIMESTAMP(3))');
            $statement->bindValue(':public_id', $publicId, PDO::PARAM_LOB);
            $statement->bindValue(':import_id', (int) $import['id'], PDO::PARAM_INT);
            $statement->bindValue(':requested', $requestedSamples, PDO::PARAM_INT);
            $statement->bindValue(':eligible', $eligible, PDO::PARAM_INT);
            $statement->execute();
            return ['evaluation_token' => bin2hex($publicId), 'eligible_samples' => $eligible, 'requested_samples' => min($requestedSamples, $eligible)];
        });
    }

    /** @return list<array{sample_id:int,expected_identity_id:int,faceapi:?array,sface:?array,faceapi_failure:?string,sface_failure:?string}> */
    public function probes(string $evaluationToken): array
    {
        $evaluation = $this->evaluation($this->connection->pdo(), $evaluationToken, false);
        $sql = 'SELECT ds.id sample_id, dii.identity_id expected_identity_id,
                fa.ciphertext faceapi_ciphertext, fa.nonce faceapi_nonce, fa.auth_tag faceapi_auth_tag, fa.dimensions faceapi_dimensions,
                sf.ciphertext sface_ciphertext, sf.nonce sface_nonce, sf.auth_tag sface_auth_tag, sf.dimensions sface_dimensions
            FROM dataset_samples ds
            INNER JOIN dataset_import_identities dii ON dii.id = ds.import_identity_id
            INNER JOIN identities i ON i.id = dii.identity_id AND i.active = 1
            INNER JOIN dataset_sample_embeddings fa ON fa.dataset_sample_id = ds.id AND fa.usage_type = "probe"
            INNER JOIN biometric_models fm ON fm.id = fa.biometric_model_id AND fm.model_key = "faceapi-recognition"
            INNER JOIN dataset_sample_embeddings sf ON sf.dataset_sample_id = ds.id AND sf.usage_type = "probe"
            INNER JOIN biometric_models sm ON sm.id = sf.biometric_model_id AND sm.model_key = "opencv-sface"
            WHERE ds.dataset_import_id = :import_id AND ds.status = "processed" AND ds.split_name = "probe"
            ORDER BY ds.id LIMIT :limit';
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->bindValue(':import_id', (int) $evaluation['dataset_import_id'], PDO::PARAM_INT);
        $statement->bindValue(':limit', (int) $evaluation['requested_samples'], PDO::PARAM_INT);
        $statement->execute();
        $probes = [];
        foreach ($statement as $row) {
            $faceapi = $sface = null;
            $faceapiFailure = $sfaceFailure = null;
            try {
                $faceapi = $this->cipher->decryptEmbedding($row['faceapi_ciphertext'], $row['faceapi_nonce'], $row['faceapi_auth_tag'], (int) $row['faceapi_dimensions']);
            } catch (\Throwable) {
                $faceapiFailure = 'probe_embedding_unavailable';
            }
            try {
                $sface = $this->cipher->decryptEmbedding($row['sface_ciphertext'], $row['sface_nonce'], $row['sface_auth_tag'], (int) $row['sface_dimensions']);
            } catch (\Throwable) {
                $sfaceFailure = 'probe_embedding_unavailable';
            }
            $probes[] = [
                'sample_id' => (int) $row['sample_id'], 'expected_identity_id' => (int) $row['expected_identity_id'],
                'faceapi' => $faceapi, 'sface' => $sface,
                'faceapi_failure' => $faceapiFailure, 'sface_failure' => $sfaceFailure,
            ];
        }
        return $probes;
    }

    /** @param array{outcome:string,recognition_result:string,predicted_identity_id:?int,distance_squared:?float,threshold_squared:float,compared_embeddings:int,comparison_total_ms:?float,failure_code:?string,best_candidate_identity_id:?int,best_candidate_distance_squared:?float,second_candidate_identity_id:?int,second_candidate_distance_squared:?float,candidate_distance_delta_squared:?float,ambiguity_margin_squared:?float,decision_reason:?string} $result */
    public function record(string $evaluationToken, int $sampleId, string $architecture, int $expectedIdentityId, array $result): void
    {
        if (!in_array($architecture, ['A', 'B'], true) || !in_array($result['outcome'], ['correct', 'incorrect', 'not_recognized', 'technical_failure'], true)) {
            throw new InvalidArgumentException('Resultado de avaliacao invalido.');
        }
        $evaluation = $this->evaluation($this->connection->pdo(), $evaluationToken, false);
        $statement = $this->connection->pdo()->prepare('INSERT INTO probe_evaluation_results
            (probe_evaluation_id, dataset_sample_id, architecture_id, expected_identity_id, predicted_identity_id,
             outcome, recognition_result, distance_squared, threshold_squared, compared_embeddings, comparison_total_ms, failure_code,
             best_candidate_identity_id, best_candidate_distance_squared, second_candidate_identity_id, second_candidate_distance_squared,
             candidate_distance_delta_squared, ambiguity_margin_squared, decision_reason, created_at)
            VALUES (:evaluation_id, :sample_id, :architecture, :expected_identity_id, :predicted_identity_id,
             :outcome, :recognition_result, :distance_squared, :threshold_squared, :compared_embeddings, :comparison_total_ms, :failure_code,
             :best_candidate_identity_id, :best_candidate_distance_squared, :second_candidate_identity_id, :second_candidate_distance_squared,
             :candidate_distance_delta_squared, :ambiguity_margin_squared, :decision_reason, UTC_TIMESTAMP(3))');
        $statement->execute([
            'evaluation_id' => (int) $evaluation['id'], 'sample_id' => $sampleId, 'architecture' => $architecture,
            'expected_identity_id' => $expectedIdentityId, 'predicted_identity_id' => $result['predicted_identity_id'],
            'outcome' => $result['outcome'], 'recognition_result' => $result['recognition_result'],
            'distance_squared' => $result['distance_squared'], 'threshold_squared' => $result['threshold_squared'],
            'compared_embeddings' => $result['compared_embeddings'], 'comparison_total_ms' => $result['comparison_total_ms'],
            'failure_code' => $result['failure_code'],
            'best_candidate_identity_id' => $result['best_candidate_identity_id'], 'best_candidate_distance_squared' => $result['best_candidate_distance_squared'],
            'second_candidate_identity_id' => $result['second_candidate_identity_id'], 'second_candidate_distance_squared' => $result['second_candidate_distance_squared'],
            'candidate_distance_delta_squared' => $result['candidate_distance_delta_squared'], 'ambiguity_margin_squared' => $result['ambiguity_margin_squared'],
            'decision_reason' => $result['decision_reason'],
        ]);
    }

    public function finish(string $evaluationToken, bool $failed = false): void
    {
        $statement = $this->connection->pdo()->prepare('UPDATE probe_evaluations SET status = :status, finished_at = UTC_TIMESTAMP(3)
            WHERE public_id = :public_id AND status = "running"');
        $statement->bindValue(':status', $failed ? 'failed' : 'completed');
        $statement->bindValue(':public_id', $this->tokenBytes($evaluationToken), PDO::PARAM_LOB);
        $statement->execute();
    }

    /** @return array<string,mixed> */
    public function summary(?string $evaluationToken = null): array
    {
        $pdo = $this->connection->pdo();
        if ($evaluationToken === null) {
            $row = $pdo->query('SELECT id FROM probe_evaluations ORDER BY id DESC LIMIT 1')->fetch();
            if (!is_array($row)) return ['evaluation' => null, 'architectures' => [], 'paired_completed' => 0, 'results' => []];
            $evaluation = $pdo->prepare('SELECT id, LOWER(HEX(public_id)) evaluation_token, dataset_import_id, requested_samples, eligible_samples, status, started_at, finished_at
                FROM probe_evaluations WHERE id = :id');
            $evaluation->execute(['id' => (int) $row['id']]);
            $entry = $evaluation->fetch();
        } else {
            $entry = $this->evaluation($pdo, $evaluationToken, false);
        }
        if (!is_array($entry)) return ['evaluation' => null, 'architectures' => [], 'paired_completed' => 0, 'results' => []];
        $evaluationId = (int) $entry['id'];
        $counts = $pdo->prepare('SELECT architecture_id, outcome, COUNT(*) amount FROM probe_evaluation_results
            WHERE probe_evaluation_id = :evaluation_id GROUP BY architecture_id, outcome');
        $counts->execute(['evaluation_id' => $evaluationId]);
        $architectures = ['A' => $this->emptyArchitecture(), 'B' => $this->emptyArchitecture()];
        foreach ($counts as $row) {
            $architecture = (string) $row['architecture_id'];
            if (isset($architectures[$architecture])) $architectures[$architecture][(string) $row['outcome']] = (int) $row['amount'];
        }
        $times = $pdo->prepare('SELECT architecture_id, comparison_total_ms FROM probe_evaluation_results
            WHERE probe_evaluation_id = :evaluation_id AND outcome <> "technical_failure" AND comparison_total_ms IS NOT NULL ORDER BY architecture_id, comparison_total_ms');
        $times->execute(['evaluation_id' => $evaluationId]);
        foreach ($times as $row) $architectures[(string) $row['architecture_id']]['times'][] = (float) $row['comparison_total_ms'];
        $paired = $pdo->prepare('SELECT COUNT(*) FROM (
            SELECT dataset_sample_id FROM probe_evaluation_results WHERE probe_evaluation_id = :evaluation_id
            GROUP BY dataset_sample_id HAVING COUNT(DISTINCT architecture_id) = 2 AND SUM(outcome = "technical_failure") = 0
        ) pairs');
        $paired->execute(['evaluation_id' => $evaluationId]);
        $results = $pdo->prepare('SELECT ds.id sample_id, r.architecture_id, r.expected_identity_id, r.predicted_identity_id,
            r.outcome, r.recognition_result, r.distance_squared, r.comparison_total_ms, r.failure_code,
            r.best_candidate_identity_id, r.best_candidate_distance_squared, r.second_candidate_identity_id, r.second_candidate_distance_squared,
            r.candidate_distance_delta_squared, r.ambiguity_margin_squared, r.decision_reason
            FROM probe_evaluation_results r INNER JOIN dataset_samples ds ON ds.id = r.dataset_sample_id
            WHERE r.probe_evaluation_id = :evaluation_id ORDER BY ds.id DESC, r.architecture_id LIMIT 30');
        $results->execute(['evaluation_id' => $evaluationId]);
        return [
            'evaluation' => [
                'evaluation_token' => (string) $entry['evaluation_token'], 'requested_samples' => (int) $entry['requested_samples'],
                'eligible_samples' => (int) $entry['eligible_samples'], 'status' => (string) $entry['status'],
                'started_at' => $entry['started_at'], 'finished_at' => $entry['finished_at'],
            ],
            'architectures' => $architectures,
            'paired_completed' => (int) $paired->fetchColumn(),
            'results' => $results->fetchAll(),
        ];
    }

    /** @return array{correct:int,incorrect:int,not_recognized:int,technical_failure:int,times:list<float>} */
    private function emptyArchitecture(): array
    {
        return ['correct' => 0, 'incorrect' => 0, 'not_recognized' => 0, 'technical_failure' => 0, 'times' => []];
    }

    /** @return array<string,mixed> */
    private function import(PDO $pdo, string $token, bool $lock): array
    {
        $statement = $pdo->prepare('SELECT id FROM dataset_imports WHERE public_id = :public_id LIMIT 1'.($lock ? ' FOR UPDATE' : ''));
        $statement->bindValue(':public_id', $this->tokenBytes($token), PDO::PARAM_LOB);
        $statement->execute();
        $row = $statement->fetch();
        if (!is_array($row)) throw new DomainException('Importacao nao encontrada.', 404);
        return $row;
    }

    /** @return array<string,mixed> */
    private function evaluation(PDO $pdo, string $token, bool $lock): array
    {
        $statement = $pdo->prepare('SELECT id, LOWER(HEX(public_id)) evaluation_token, dataset_import_id, requested_samples, eligible_samples, status, started_at, finished_at
            FROM probe_evaluations WHERE public_id = :public_id LIMIT 1'.($lock ? ' FOR UPDATE' : ''));
        $statement->bindValue(':public_id', $this->tokenBytes($token), PDO::PARAM_LOB);
        $statement->execute();
        $row = $statement->fetch();
        if (!is_array($row)) throw new DomainException('Avaliacao nao encontrada.', 404);
        return $row;
    }

    private function eligibleCount(PDO $pdo, int $importId): int
    {
        $statement = $pdo->prepare('SELECT COUNT(DISTINCT ds.id) FROM dataset_samples ds
            INNER JOIN dataset_import_identities dii ON dii.id = ds.import_identity_id
            INNER JOIN identities i ON i.id = dii.identity_id AND i.active = 1
            INNER JOIN dataset_sample_embeddings fa ON fa.dataset_sample_id = ds.id AND fa.usage_type = "probe"
            INNER JOIN biometric_models fm ON fm.id = fa.biometric_model_id AND fm.model_key = "faceapi-recognition"
            INNER JOIN dataset_sample_embeddings sf ON sf.dataset_sample_id = ds.id AND sf.usage_type = "probe"
            INNER JOIN biometric_models sm ON sm.id = sf.biometric_model_id AND sm.model_key = "opencv-sface"
            WHERE ds.dataset_import_id = :import_id AND ds.status = "processed" AND ds.split_name = "probe"');
        $statement->execute(['import_id' => $importId]);
        return (int) $statement->fetchColumn();
    }

    private function tokenBytes(string $token): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1 || !is_string($bytes = hex2bin($token))) {
            throw new InvalidArgumentException('Token de avaliacao ou importacao invalido.');
        }
        return $bytes;
    }
}
