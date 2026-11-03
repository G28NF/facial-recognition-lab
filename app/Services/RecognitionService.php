<?php
declare(strict_types=1);

namespace App\Services;

final class RecognitionService
{
    public function __construct(
        private readonly ComparisonService $comparison,
        private readonly PythonFaceClient $python
    ) {}

    /** @param array<mixed> $embedding @param array<string,int|float> $frontendMetrics @return array<string,mixed> */
    public function architectureA(array $embedding, array $frontendMetrics = []): array
    {
        $result = $this->comparison->recognize('faceapi-recognition', $embedding);
        $comparisonMetrics = $result['comparison_metrics'];
        unset($result['comparison_metrics']);
        $result['architecture'] = 'A';
        $result['metrics'] = array_merge($this->numericMetrics($frontendMetrics), $comparisonMetrics);
        return $result;
    }

    /** @return array<string,mixed> */
    public function architectureB(string $image): array
    {
        $extraction = $this->python->extract($image);
        $result = $this->comparison->recognize('opencv-sface', $extraction['embedding']);
        $comparisonMetrics = $result['comparison_metrics'];
        unset($result['comparison_metrics']);
        $result['architecture'] = 'B';
        $result['metrics'] = array_merge($extraction['metrics'], $comparisonMetrics);
        return $result;
    }

    /** @param array<string,mixed> $metrics @return array<string,int|float> */
    private function numericMetrics(array $metrics): array
    {
        $valid = [];
        foreach ($metrics as $name => $value) {
            if ((is_int($value) || is_float($value)) && is_finite((float) $value)) {
                $valid[$name] = $value;
            }
        }
        return $valid;
    }
}
