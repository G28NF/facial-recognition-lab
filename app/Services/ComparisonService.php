<?php
declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Persistence\MysqlIdentityRepository;
use InvalidArgumentException;

final class ComparisonService
{
    /** @param array<string,array{dimensions:int,threshold_squared:float,ambiguity_margin_squared:float}> $models */
    public function __construct(
        private readonly MysqlIdentityRepository $repository,
        private readonly array $models
    ) {}

    /** @param array<mixed> $input
     *  @return array{status:string,id:?int,name:?string,distance_squared:?float,compared_embeddings:int,best_candidate_identity_id:?int,best_candidate_distance_squared:?float,second_candidate_identity_id:?int,second_candidate_distance_squared:?float,candidate_distance_delta_squared:?float,ambiguity_margin_squared:float,decision_reason:string,comparison_metrics:array<string,float>}
     */
    public function recognize(string $modelKey, array $input): array
    {
        $model = $this->models[$modelKey] ?? null;
        if ($model === null || $model['threshold_squared'] <= 0 || $model['ambiguity_margin_squared'] < 0) {
            throw new InvalidArgumentException('Modelo biometrico invalido.');
        }
        $totalStarted = hrtime(true);
        $started = hrtime(true);
        $query = VectorMath::validate($input, $model['dimensions']);
        $inputPrepareMs = (hrtime(true) - $started) / 1_000_000;
        $bestByIdentity = [];
        $compared = 0;
        $mathNs = $selectionNs = 0;
        $candidates = $this->repository->candidates($modelKey);
        foreach ($candidates as $candidate) {
            $started = hrtime(true);
            $distance = VectorMath::squaredDistance($query, $candidate['embedding']);
            $mathNs += hrtime(true) - $started;
            $compared++;
            $started = hrtime(true);
            $id = $candidate['identity_id'];
            if (!isset($bestByIdentity[$id]) || $distance < $bestByIdentity[$id]) {
                $bestByIdentity[$id] = $distance;
            }
            $selectionNs += hrtime(true) - $started;
        }
        $repositoryMetrics = $candidates->getReturn();
        $started = hrtime(true);
        $best = $second = null;
        foreach ($bestByIdentity as $id => $distance) {
            $candidate = ['id' => $id, 'distance' => $distance];
            if ($best === null || $distance < $best['distance']) {
                $second = $best;
                $best = $candidate;
            } elseif ($second === null || $distance < $second['distance']) {
                $second = $candidate;
            }
        }
        $selectionNs += hrtime(true) - $started;
        $metrics = $repositoryMetrics + [
            'candidate_prepare_ms' => 0.0,
            'comparison_ms' => round($mathNs / 1_000_000, 3),
            'candidate_selection_ms' => round($selectionNs / 1_000_000, 3),
        ];
        $metrics['candidate_prepare_ms'] = round((float) $metrics['candidate_prepare_ms'] + $inputPrepareMs, 3);
        $status = 'recognized';
        $name = null;
        if ($best === null || $best['distance'] > $model['threshold_squared']) {
            $status = 'unknown';
        } elseif ($second !== null && ($second['distance'] - $best['distance']) < $model['ambiguity_margin_squared']) {
            $status = 'inconclusive';
        } else {
            $identity = $this->repository->identityName($best['id']);
            $name = $identity['name'];
            $metrics['matched_name_query_ms'] = $identity['matched_name_query_ms'];
            $metrics['matched_name_decrypt_ms'] = $identity['matched_name_decrypt_ms'];
        }
        $totalMs = (hrtime(true) - $totalStarted) / 1_000_000;
        $components = array_sum($metrics);
        $metrics['comparison_overhead_ms'] = round(max(0.0, $totalMs - $components), 3);
        $metrics['comparison_total_ms'] = round($totalMs, 3);
        return [
            'status' => $status,
            'id' => $status === 'recognized' ? $best['id'] : null,
            'name' => $name,
            'distance_squared' => $best['distance'] ?? null,
            'compared_embeddings' => $compared,
            'best_candidate_identity_id' => $best['id'] ?? null,
            'best_candidate_distance_squared' => $best['distance'] ?? null,
            'second_candidate_identity_id' => $second['id'] ?? null,
            'second_candidate_distance_squared' => $second['distance'] ?? null,
            'candidate_distance_delta_squared' => $best !== null && $second !== null ? $second['distance'] - $best['distance'] : null,
            'ambiguity_margin_squared' => $model['ambiguity_margin_squared'],
            'decision_reason' => $status,
            'comparison_metrics' => $metrics,
        ];
    }
}
