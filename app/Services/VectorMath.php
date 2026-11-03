<?php
declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class VectorMath
{
    /** @param array<mixed> $vector
     *  @return list<float>
     */
    public static function validate(array $vector, int $dimensions): array
    {
        if (!array_is_list($vector) || count($vector) !== $dimensions) {
            throw new InvalidArgumentException("O embedding deve conter {$dimensions} valores.");
        }
        $validated = [];
        $normSquared = 0.0;
        foreach ($vector as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs((float) $value) > 2.0) {
                throw new InvalidArgumentException('Embedding invalido.');
            }
            $numeric = (float) $value;
            $validated[] = $numeric;
            $normSquared += $numeric * $numeric;
        }
        if ($normSquared < 0.25 || $normSquared > 4.0) {
            throw new InvalidArgumentException('Norma do embedding invalida.');
        }
        return $validated;
    }

    /** @param list<float> $left @param list<float> $right */
    public static function squaredDistance(array $left, array $right): float
    {
        if (count($left) !== count($right)) {
            throw new InvalidArgumentException('Espacos biometricos incompativeis.');
        }
        $sum = 0.0;
        foreach ($left as $index => $value) {
            $difference = $value - $right[$index];
            $sum += $difference * $difference;
        }
        return $sum;
    }
}
