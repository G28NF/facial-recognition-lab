<?php
declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Persistence\MysqlDatasetIntegrityRepository;
use App\Infrastructure\Persistence\MysqlIdentityRepository;

final class DatasetIntegrityService
{
    /** @param array<string,array{dimensions:int,threshold_squared:float,ambiguity_margin_squared:float}> $models */
    public function __construct(
        private readonly MysqlIdentityRepository $identities,
        private readonly MysqlDatasetIntegrityRepository $findings,
        private readonly array $models
    ) {}

    /** @return array{reviewed_identities:int,findings:array{A:int,B:int}} */
    public function reviewPossibleDuplicates(int $maximumIdentities = 50): array
    {
        $maximumIdentities = max(2, min(200, $maximumIdentities));
        $counts = ['A' => 0, 'B' => 0];
        $reviewed = [];
        foreach (['A' => 'faceapi-recognition', 'B' => 'opencv-sface'] as $architecture => $modelKey) {
            $candidates = [];
            foreach ($this->identities->candidates($modelKey) as $candidate) {
                $identityId = $candidate['identity_id'];
                if (!isset($candidates[$identityId]) && count($candidates) >= $maximumIdentities) break;
                $candidates[$identityId] ??= $candidate['embedding'];
                $reviewed[$identityId] = true;
            }
            $ids = array_keys($candidates);
            for ($left = 0; $left < count($ids); $left++) {
                for ($right = $left + 1; $right < count($ids); $right++) {
                    $distance = VectorMath::squaredDistance($candidates[$ids[$left]], $candidates[$ids[$right]]);
                    if ($distance <= $this->models[$modelKey]['threshold_squared']) {
                        $this->findings->recordPossibleDuplicate($ids[$left], $ids[$right], $architecture, $distance);
                        $counts[$architecture]++;
                    }
                }
            }
        }
        return ['reviewed_identities' => count($reviewed), 'findings' => $counts];
    }
}
