<?php
declare(strict_types=1);

namespace App\Models;

final readonly class BiometricModel
{
    public function __construct(
        public int $id,
        public string $key,
        public string $vectorSpace,
        public int $dimensions
    ) {}
}
