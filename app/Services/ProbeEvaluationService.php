<?php
declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Persistence\MysqlProbeEvaluationRepository;

final class ProbeEvaluationService
{
    /** @param array<string,array{dimensions:int,threshold_squared:float,ambiguity_margin_squared:float}> $models */
    public function __construct(
        private readonly MysqlProbeEvaluationRepository $repository,
        private readonly ComparisonService $comparison,
        private readonly StatisticsCalculator $statistics,
        private readonly array $models
    ) {}

    /** @return array<string,mixed> */
    public function run(string $importToken, int $maximumSamples): array
    {
        $evaluation = $this->repository->start($importToken, $maximumSamples);
        try {
            foreach ($this->repository->probes($evaluation['evaluation_token']) as $probe) {
                $this->evaluate($evaluation['evaluation_token'], $probe, 'A', 'faceapi-recognition', $probe['faceapi'], $probe['faceapi_failure']);
                $this->evaluate($evaluation['evaluation_token'], $probe, 'B', 'opencv-sface', $probe['sface'], $probe['sface_failure']);
            }
            $this->repository->finish($evaluation['evaluation_token']);
        } catch (\Throwable $error) {
            $this->repository->finish($evaluation['evaluation_token'], true);
            throw $error;
        }
        return $this->dashboard($evaluation['evaluation_token']);
    }

    /** @return array<string,mixed> */
    public function dashboard(?string $evaluationToken = null): array
    {
        $data = $this->repository->summary($evaluationToken);
        if ($data['evaluation'] === null) {
            return $data + ['imports' => $this->repository->availableImports(), 'methodology' => $this->methodology()];
        }
        foreach (['A', 'B'] as $architecture) {
            $item = $data['architectures'][$architecture];
            $completed = $item['correct'] + $item['incorrect'] + $item['not_recognized'];
            $selected = min((int) $data['evaluation']['requested_samples'], (int) $data['evaluation']['eligible_samples']);
            $data['architectures'][$architecture] = [
                'eligible' => (int) $data['evaluation']['eligible_samples'],
                'completed' => $completed,
                'correct' => $item['correct'], 'incorrect' => $item['incorrect'], 'not_recognized' => $item['not_recognized'],
                'technical_failure' => $item['technical_failure'],
                'coverage_percent' => $this->percent($completed, $selected),
                'accuracy_percent' => $this->percent($item['correct'], $completed),
                'error_percent' => $this->percent($item['incorrect'] + $item['not_recognized'], $completed),
                'comparison_time' => $this->statistics->calculate($item['times']),
            ];
        }
        $data['evaluation']['selected_samples'] = min((int) $data['evaluation']['requested_samples'], (int) $data['evaluation']['eligible_samples']);
        return $data + ['imports' => $this->repository->availableImports(), 'methodology' => $this->methodology()];
    }

    /** @param array{sample_id:int,expected_identity_id:int} $probe @param ?array<mixed> $embedding */
    private function evaluate(string $token, array $probe, string $architecture, string $modelKey, ?array $embedding, ?string $failure): void
    {
        $threshold = $this->models[$modelKey]['threshold_squared'];
        if ($embedding === null || $failure !== null) {
            $this->repository->record($token, $probe['sample_id'], $architecture, $probe['expected_identity_id'], [
                'outcome' => 'technical_failure', 'recognition_result' => 'error', 'predicted_identity_id' => null,
                'distance_squared' => null, 'threshold_squared' => $threshold, 'compared_embeddings' => 0,
                'comparison_total_ms' => null, 'failure_code' => $failure ?? 'probe_embedding_unavailable',
                ...$this->ambiguity(null, 'technical_failure'),
            ]);
            return;
        }
        try {
            $result = $this->comparison->recognize($modelKey, $embedding);
            $outcome = $result['status'] === 'recognized'
                ? ($result['id'] === $probe['expected_identity_id'] ? 'correct' : 'incorrect')
                : 'not_recognized';
            $this->repository->record($token, $probe['sample_id'], $architecture, $probe['expected_identity_id'], [
                'outcome' => $outcome, 'recognition_result' => $result['status'], 'predicted_identity_id' => $result['id'],
                'distance_squared' => $result['distance_squared'], 'threshold_squared' => $threshold,
                'compared_embeddings' => $result['compared_embeddings'],
                'comparison_total_ms' => $result['comparison_metrics']['comparison_total_ms'] ?? null,
                'failure_code' => null,
                ...$this->ambiguity($result, $result['decision_reason']),
            ]);
        } catch (\Throwable) {
            $this->repository->record($token, $probe['sample_id'], $architecture, $probe['expected_identity_id'], [
                'outcome' => 'technical_failure', 'recognition_result' => 'error', 'predicted_identity_id' => null,
                'distance_squared' => null, 'threshold_squared' => $threshold, 'compared_embeddings' => 0,
                'comparison_total_ms' => null, 'failure_code' => 'comparison_failed',
                ...$this->ambiguity(null, 'technical_failure'),
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function methodology(): array
    {
        return [
            'denominator' => 'Acerto, identificacao incorreta e nao reconhecido; falhas tecnicas ficam fora da taxa e sao mostradas separadamente.',
            'paired' => 'Pareados sao somente probes da mesma avaliacao concluidas sem falha tecnica nas arquiteturas A e B.',
            'time' => 'comparison_total_ms mede somente a comparacao 1:N sobre embeddings Probe ja extraidos; nao mede deteccao nem extracao.',
        ];
    }

    /** @param ?array<string,mixed> $result @return array<string,int|float|string|null> */
    private function ambiguity(?array $result, string $decisionReason): array
    {
        return [
            'best_candidate_identity_id' => isset($result['best_candidate_identity_id']) ? (int) $result['best_candidate_identity_id'] : null,
            'best_candidate_distance_squared' => is_numeric($result['best_candidate_distance_squared'] ?? null) ? (float) $result['best_candidate_distance_squared'] : null,
            'second_candidate_identity_id' => isset($result['second_candidate_identity_id']) ? (int) $result['second_candidate_identity_id'] : null,
            'second_candidate_distance_squared' => is_numeric($result['second_candidate_distance_squared'] ?? null) ? (float) $result['second_candidate_distance_squared'] : null,
            'candidate_distance_delta_squared' => is_numeric($result['candidate_distance_delta_squared'] ?? null) ? (float) $result['candidate_distance_delta_squared'] : null,
            'ambiguity_margin_squared' => is_numeric($result['ambiguity_margin_squared'] ?? null) ? (float) $result['ambiguity_margin_squared'] : null,
            'decision_reason' => $decisionReason,
        ];
    }

    private function percent(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : round($numerator * 100 / $denominator, 2);
    }
}
