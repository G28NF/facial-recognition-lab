<?php
declare(strict_types=1);

namespace App\Services;

final class StatisticsCalculator
{
    /** @param array<mixed> $values
     *  @return array{count:int,mean:?float,median:?float,minimum:?float,maximum:?float,p95:?float,population_stddev:?float}
     */
    public function calculate(array $values): array
    {
        $valid = [];
        foreach ($values as $value) {
            if ((is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) && is_finite((float) $value) && (float) $value >= 0) {
                $valid[] = (float) $value;
            }
        }
        sort($valid, SORT_NUMERIC);
        $count = count($valid);
        if ($count === 0) {
            return ['count' => 0, 'mean' => null, 'median' => null, 'minimum' => null, 'maximum' => null, 'p95' => null, 'population_stddev' => null];
        }
        $mean = array_sum($valid) / $count;
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1 ? $valid[$middle] : ($valid[$middle - 1] + $valid[$middle]) / 2;
        $variance = array_sum(array_map(static fn(float $value): float => ($value - $mean) ** 2, $valid)) / $count;
        $p95Index = max(0, (int) ceil($count * .95) - 1);
        return [
            'count' => $count,
            'mean' => round($mean, 4),
            'median' => round($median, 4),
            'minimum' => round($valid[0], 4),
            'maximum' => round($valid[$count - 1], 4),
            'p95' => round($valid[$p95Index], 4),
            'population_stddev' => round(sqrt($variance), 4),
        ];
    }
}
