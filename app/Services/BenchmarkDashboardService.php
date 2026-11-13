<?php
declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Persistence\MysqlMetricsDashboardRepository;
use Generator;

final class BenchmarkDashboardService
{
    public function __construct(
        private readonly MysqlMetricsDashboardRepository $repository,
        private readonly StatisticsCalculator $statistics
    ) {}

    /** @return array<string,mixed> */
    public function dashboard(int $page, int $limit, ?string $sessionToken): array
    {
        $sessions = $this->repository->sessions($page, $limit);
        if ($sessionToken === null && isset($sessions['items'][0]['session_token'])) {
            $sessionToken = (string) $sessions['items'][0]['session_token'];
        }
        return [
            'methodology' => [
                'latency_metric' => 'end_to_end_ms',
                'included_results' => ['recognized', 'unknown', 'inconclusive'],
                'excluded_results' => ['error'],
                'percentile' => 'nearest-rank P95',
                'standard_deviation' => 'population',
                'note' => 'browser_upload_ms mede somente o envio observado pelo navegador e pode estar ausente; pipeline_total_ms mede processamento interno; end_to_end_ms e individual por arquitetura. Metricas comparativas historicas nao entram no painel.',
                'precision_note' => 'Eventos periodicos medem desempenho operacional; nao sao amostras independentes de precisao.',
            ],
            'overall' => $this->statisticsFor(null),
            'selected_session' => $sessionToken,
            'session_statistics' => $sessionToken === null ? $this->emptyStatistics() : $this->statisticsFor($sessionToken),
            'lifecycle' => $this->lifecycleStatistics($sessionToken),
            'stages' => $this->repository->stageAverages($sessionToken),
            'paired_frames' => $this->repository->pairedFrames($sessionToken, 100),
            'sessions' => $sessions,
        ];
    }

    /** @return array{sessions:int,events:int,metrics:int} */
    public function deleteSession(string $token): array
    {
        return $this->repository->deleteSession($token);
    }

    /** @return array{sessions:int,events:int,metrics:int} */
    public function clearAll(): array
    {
        return $this->repository->clearAll();
    }

    /** @return Generator<int,array<string,mixed>> */
    public function exportRows(?string $token): Generator
    {
        return $this->repository->exportRows($token);
    }

    /** @return list<string> */
    public function csvHeaders(): array
    {
        return [
            'record_type', 'session_token', 'video_session_id', 'session_status', 'interval_ms',
            'session_started_at', 'session_ended_at', 'frame_sequence', 'occurred_at', 'architecture',
            'result', 'matched_identity_id', 'distance_squared', 'threshold_squared', 'compared_embeddings',
            'failure', 'best_candidate_identity_id', 'best_candidate_distance_squared', 'second_candidate_identity_id',
            'second_candidate_distance_squared', 'candidate_distance_delta_squared', 'ambiguity_margin_squared', 'decision_reason',
            'pair_complete', 'metric_name', 'metric_value', 'metric_unit',
            'detection_ms', 'extraction_ms', 'comparison_ms', 'pipeline_total_ms', 'image_decode_ms',
            'mysql_query_ms', 'candidate_read_ms', 'embedding_decrypt_ms', 'candidate_prepare_ms',
            'candidate_selection_ms', 'matched_name_query_ms', 'matched_name_decrypt_ms',
            'comparison_overhead_ms', 'comparison_total_ms',
            'facial_processing_ms', 'php_python_roundtrip_ms', 'php_python_overhead_ms',
            'image_encode_ms', 'browser_upload_ms', 'browser_download_ms', 'http_roundtrip_ms', 'end_to_end_ms', 'request_payload_bytes',
        ];
    }

    /** @return array<string,array<string,int|float|null>> */
    private function statisticsFor(?string $sessionToken): array
    {
        $values = $this->repository->latencyValues($sessionToken);
        $counts = $this->repository->resultCounts($sessionToken);
        $result = [];
        foreach (['A', 'B'] as $architecture) {
            $result[$architecture] = $this->statistics->calculate($values[$architecture] ?? []) + ($counts[$architecture] ?? [
                'executions' => 0, 'failures' => 0, 'recognized' => 0, 'unknown' => 0, 'inconclusive' => 0,
            ]);
        }
        return $result;
    }

    /** @return array<string,array<string,int|float|null>> */
    private function emptyStatistics(): array
    {
        $empty = $this->statistics->calculate([]) + ['executions' => 0, 'failures' => 0, 'recognized' => 0, 'unknown' => 0, 'inconclusive' => 0];
        return ['A' => $empty, 'B' => $empty];
    }

    /** @return array<string,array<string,array{count:int,mean:?float,median:?float,minimum:?float,maximum:?float,p95:?float,population_stddev:?float}>> */
    private function lifecycleStatistics(?string $sessionToken): array
    {
        $values = $this->repository->lifecycleValues($sessionToken);
        $result = ['A' => [], 'B' => []];
        foreach ($values as $architecture => $metrics) {
            foreach ($metrics as $name => $items) $result[$architecture][$name] = $this->statistics->calculate($items);
        }
        return $result;
    }
}
