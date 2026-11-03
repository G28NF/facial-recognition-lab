<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'data_key' => Env::require('RFV2_DATA_KEY'),
    'data_key_id' => Env::get('RFV2_DATA_KEY_ID', 'local-v1'),
];
