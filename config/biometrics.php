<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'faceapi-recognition' => [
        'dimensions' => 128,
        'threshold_squared' => (float) Env::get('RFV2_FACEAPI_THRESHOLD_SQUARED', '0.36'),
        'ambiguity_margin_squared' => (float) Env::get('RFV2_FACEAPI_AMBIGUITY_MARGIN_SQUARED', '0.01'),
    ],
    'opencv-sface' => [
        'dimensions' => 128,
        'threshold_squared' => (float) Env::get('RFV2_SFACE_THRESHOLD_SQUARED', '1.3225'),
        'ambiguity_margin_squared' => (float) Env::get('RFV2_SFACE_AMBIGUITY_MARGIN_SQUARED', '0.0064'),
    ],
];
