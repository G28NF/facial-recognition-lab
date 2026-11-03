<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'default_interval_ms' => (int) Env::get('RFV2_VIDEO_INTERVAL_MS', '1000'),
    'minimum_interval_ms' => 500,
    'maximum_interval_ms' => 10000,
];
