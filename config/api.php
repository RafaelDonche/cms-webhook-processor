<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Consumer API
    |--------------------------------------------------------------------------
    |
    | Requests accepted per minute from a single authenticated consumer.
    |
    */

    'rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),

];
