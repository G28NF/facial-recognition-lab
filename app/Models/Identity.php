<?php
declare(strict_types=1);

namespace App\Models;

final readonly class Identity
{
    public function __construct(public int $id, public string $publicId, public string $name) {}
}
