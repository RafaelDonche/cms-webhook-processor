<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Consumer API
    |--------------------------------------------------------------------------
    |
    | Requests accepted per minute from a single consumer, and the number of
    | days a newly issued consumer token remains valid when the issuing
    | command does not specify a lifetime of its own.
    |
    */

    'rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 60),

    'token_ttl_days' => (int) env('API_TOKEN_TTL_DAYS', 90),

];
