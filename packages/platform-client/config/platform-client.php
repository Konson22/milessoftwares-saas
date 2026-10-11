<?php

/*
|--------------------------------------------------------------------------
| Miles Softwares platform connection
|--------------------------------------------------------------------------
|
| Values come from admin → Applications → <product> → Integration on the
| platform (docs/integration.md). Secrets are shown once there.
|
*/

return [

    // The application code registered on the platform, e.g. "pharmacy".
    'application' => env('PLATFORM_APPLICATION'),

    // Workspaces are {slug}.<root_domain>; the product is served under
    // /<application> on those hosts.
    'root_domain' => env('PLATFORM_ROOT_DOMAIN'),

    // Public URL of the auth host: the id_token issuer and where browsers
    // are sent to sign in, e.g. https://auth.milessoftwares.com
    'issuer' => env('PLATFORM_ISSUER'),

    // Optional internal address of the auth host for server-to-server calls
    // (token, JWKS, API). Requests carry the issuer's Host header.
    'backchannel_url' => env('PLATFORM_BACKCHANNEL_URL'),

    // Sign-in client (authorization code + PKCE).
    'sign_in' => [
        'client_id' => env('PLATFORM_SIGN_IN_CLIENT_ID'),
        'client_secret' => env('PLATFORM_SIGN_IN_CLIENT_SECRET'),
    ],

    // Service client (client credentials) for the platform API.
    'service' => [
        'client_id' => env('PLATFORM_SERVICE_CLIENT_ID'),
        'client_secret' => env('PLATFORM_SERVICE_CLIENT_SECRET'),
    ],

    // Signs webhooks and provisioning calls (whsec_...).
    'webhook_secret' => env('PLATFORM_WEBHOOK_SECRET'),

    // Local mirror of platform users: primary key = the platform subject
    // (uuid), with name and email. Updated at every sign-in.
    'user_model' => env('PLATFORM_USER_MODEL', 'App\\Models\\User'),

    // Where a signed-in user lands when there is no intended URL.
    'home' => '/',

    // Register the sign-in, webhook and provisioning routes.
    'routes' => true,

    // Upper bound for caching organization lookups and effective access.
    // Webhooks drop the cache for their organization immediately.
    'cache_seconds' => 60,

    'http_timeout' => 10,

];
