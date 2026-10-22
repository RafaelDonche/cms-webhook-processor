<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | CMS Webhook
    |--------------------------------------------------------------------------
    |
    | HTTP Basic credentials the CMS must present when delivering events, and
    | the number of deliveries accepted per minute from a single IP address.
    | Requests are rejected while the credentials are not configured.
    |
    */

    'webhook' => [
        'username' => (string) env('CMS_WEBHOOK_USERNAME', ''),
        'password' => (string) env('CMS_WEBHOOK_PASSWORD', ''),
        'rate_limit_per_minute' => (int) env('CMS_WEBHOOK_RATE_LIMIT_PER_MINUTE', 120),
    ],

];
