<?php
declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Persistence\MysqlVideoSessionRepository;
use Throwable;

final class ContinuousRecognitionService
{
    /** @param array<string,array{dimensions:int,threshold_squared:float,ambiguity_margin_squared:float}> $models */
    public function __construct(
        private readonly MysqlVideoSessionRepository $sessions,
        private readonly RecognitionService $recognition,
        private readonly PythonFaceClient $python,
        private readonly array $models
    ) {}

    /** @param array{A:array<string,float>,B:array<string,float>} $initializationMetrics */
    public function start(int $intervalMs, array $initializationMetrics, ?int $expectedIdentityId = null): array
    {
        return ['session_token' => $this->sessions->start($intervalMs, $initializationMetrics, $expectedIdentityId), 'status' => 'running', 'interval_ms' => $intervalMs];
    }

    public function pause(string $token): array
    {
        $this->sessions->pause($token);
        return ['status' => 'paused'];
    }

    public function resume(string $token): array
    {
        $this->sessions->resume($token);
        return ['status' => 'running'];
    }
    public function changeEvaluationIdentity(string $token, ?int $identityId): array { $this->sessions->changeEvaluationIdentity($token, $identityId); return ['status'=>'updated']; }

    public function end(string $token): array
    {
        $this->sessions->end($token);
        return ['status' => 'ended'];
    }

    /** @param array<string,int|float> $metrics */
    public function monitor(string $token, int $frameSequence, int $missedIntervals, array $metrics): array
    {
        $this->sessions->recordMonitoring($token, $frameSequence, $missedIntervals, $metrics);
        return ['status' => 'monitored', 'frame_sequence' => $frameSequence];
    }

    /** @param array<string,int|float> $metrics */
    public function finalize(string $token, int $frameSequence, array $metrics, ?string $architecture = null): array
    {
        $this->sessions->finalizeRound($token, $frameSequence, $metrics, $architecture);
        return ['status' => 'finalized'];
    }

    /** @param array<mixed>|null $embedding @param array<string,int|float> $metrics @param array<string,int|float> $monitoringMetrics */
    public function processArchitectureA(string $token, int $frameSequence, int $missedIntervals, ?array $embedding, ?string $faceapiFailure, array $metrics, array $monitoringMetrics): array
    {
        $started = hrtime(true);
        if ($embedding === null) $result = $this->frontendFaceApiFailure($faceapiFailure, $started, $metrics);
        else {
            try {
                $result = $this->recognition->architectureA($embedding, $metrics);
                $result['metrics']['pipeline_total_ms'] = round((hrtime(true) - $started) / 1_000_000 + (float) ($metrics['detection_ms'] ?? 0) + (float) ($metrics['extraction_ms'] ?? 0), 3);
            } catch (Throwable $error) { $result = $this->failure('A', $error, $started); }
        }
        $this->sessions->recordArchitectureResult($token, 'A', $frameSequence, $missedIntervals, $result, $monitoringMetrics, $this->thresholds());
        return $result;
    }

    /** @param array<string,int|float> $monitoringMetrics */
    public function processArchitectureB(string $token, int $frameSequence, int $missedIntervals, string $image, array $monitoringMetrics): array
    {
        $started = hrtime(true);
        try { $result = $this->recognition->architectureB($image); $result['metrics']['pipeline_total_ms'] = round((hrtime(true) - $started) / 1_000_000, 3); }
        catch (Throwable $error) { $result = $this->failure('B', $error, $started); }
        $this->sessions->recordArchitectureResult($token, 'B', $frameSequence, $missedIntervals, $result, $monitoringMetrics, $this->thresholds());
        return $result;
    }

    /** @param array<string,int|float> $monitoringMetrics */
    public function warmup(string $token, string $image, ?float $faceapiWarmupMs, ?float $faceapiFirstInferenceMs, int $frameSequence, int $missedIntervals, array $monitoringMetrics): array
    {
        $started = hrtime(true);
        $this->python->extract($image);
        $pythonWarmupMs = round((hrtime(true) - $started) / 1_000_000, 3);
        $this->sessions->recordWarmup($token, $frameSequence, $missedIntervals, ['A' => $faceapiWarmupMs, 'B' => $pythonWarmupMs], $monitoringMetrics, $faceapiFirstInferenceMs);
        return ['status' => 'warmed', 'warmup' => ['A' => $faceapiWarmupMs, 'B' => $pythonWarmupMs]];
    }

    /** @param array<mixed>|null $faceapiEmbedding @param array<string,int|float> $faceapiMetrics @param array<string,int|float> $monitoringMetrics */
    public function processRound(string $token, int $frameSequence, int $missedIntervals, string $image, ?array $faceapiEmbedding, ?string $faceapiFailure, array $faceapiMetrics, array $monitoringMetrics): array
    {
        $results = [];
        $startedA = hrtime(true);
        if ($faceapiEmbedding === null) {
            $results['A'] = $this->frontendFaceApiFailure($faceapiFailure, $startedA, $faceapiMetrics);
        } else {
            try {
                $results['A'] = $this->recognition->architectureA($faceapiEmbedding, $faceapiMetrics);
                $results['A']['metrics']['pipeline_total_ms'] = round((hrtime(true) - $startedA) / 1_000_000 + (float) ($faceapiMetrics['detection_ms'] ?? 0) + (float) ($faceapiMetrics['extraction_ms'] ?? 0), 3);
            } catch (Throwable $error) {
                $results['A'] = $this->failure('A', $error, $startedA);
            }
        }

        $startedB = hrtime(true);
        try {
            $results['B'] = $this->recognition->architectureB($image);
            $results['B']['metrics']['pipeline_total_ms'] = round((hrtime(true) - $startedB) / 1_000_000, 3);
        } catch (Throwable $error) {
            $results['B'] = $this->failure('B', $error, $startedB);
        }

        $this->sessions->recordRound($token, $frameSequence, $missedIntervals, $results, $monitoringMetrics, [
            'A' => $this->models['faceapi-recognition']['threshold_squared'],
            'B' => $this->models['opencv-sface']['threshold_squared'],
        ]);
        return ['frame_sequence' => $frameSequence, 'results' => array_values($results)];
    }

    /** @return array<string,mixed> */
    /** @param array<string,int|float> $frontendMetrics */
    private function frontendFaceApiFailure(?string $failure, int $started, array $frontendMetrics): array
    {
        $safeFailure = in_array($failure, ['no_face', 'multiple_faces'], true) ? $failure : 'technical_failure';
        return $this->failure('A', new \RuntimeException('Falha na extracao Face API.js.'), $started, $frontendMetrics, $safeFailure);
    }

    private function failure(string $architecture, Throwable $error, int $started, array $frontendMetrics = [], ?string $failure = null): array
    {
        $pipelineTotalMs = (hrtime(true) - $started) / 1_000_000 + (float) ($frontendMetrics['faceapi_failure_ms'] ?? 0);
        return [
            'architecture' => $architecture,
            'status' => 'error',
            'id' => null,
            'name' => null,
            'distance_squared' => null,
            'compared_embeddings' => 0,
            'best_candidate_identity_id' => null,
            'best_candidate_distance_squared' => null,
            'second_candidate_identity_id' => null,
            'second_candidate_distance_squared' => null,
            'candidate_distance_delta_squared' => null,
            'ambiguity_margin_squared' => null,
            'decision_reason' => 'technical_failure',
            'error' => $failure ?? ($error->getCode() === 422 ? 'facial_input_failure' : 'service_failure'),
            'metrics' => array_merge($frontendMetrics, ['pipeline_total_ms' => round($pipelineTotalMs, 3)]),
        ];
    }

    /** @return array{A:float,B:float} */
    private function thresholds(): array
    {
        return ['A' => $this->models['faceapi-recognition']['threshold_squared'], 'B' => $this->models['opencv-sface']['threshold_squared']];
    }
}
