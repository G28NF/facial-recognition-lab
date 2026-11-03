<?php
declare(strict_types=1);

use App\Core\Env;

return ['access_key' => Env::require('RFV2_ACCESS_KEY')];
