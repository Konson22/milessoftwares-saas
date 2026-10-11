<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing
    |--------------------------------------------------------------------------
    |
    | No cross-origin browser access anywhere. Products call the platform API
    | from their own backends (BFF, docs/integration.md), and every platform
    | page is same-origin, so no path sends CORS headers and browsers refuse
    | cross-origin reads. The framework default allowed any origin on api/*.
    |
    */

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
