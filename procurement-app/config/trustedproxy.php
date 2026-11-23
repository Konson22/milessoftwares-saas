<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Comma-separated addresses or CIDR ranges of the load balancers in front
    | of the application. Only these may set X-Forwarded-* headers, which
    | decide whether a request is HTTPS and which client IP is logged.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
