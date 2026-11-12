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

    // Workspaces are {slug}.<root_domain>.
    'root_domain' => env('PLATFORM_ROOT_DOMAIN'),

    // The product's path on workspace hosts ({slug}.<root>/<path>), the
    // application's route prefix on the platform. Every product route,
    // including this package's, lives under it. Default: the application code.
    'path' => env('PLATFORM_PATH'),

    // Public URL of the auth host: the id_token issuer and where browsers
    // are sent to sign in, e.g. https://auth.milesoftwares.com
    'issuer' => env('PLATFORM_ISSUER'),

    // Optional internal address of the auth host for server-to-server calls
    // (token, JWKS, API). Requests carry the issuer's Host header.
    'backchannel_url' => env('PLATFORM_BACKCHANNEL_URL'),

    // Sign-in client (authorization code + PKCE).
    'sign_in' => [
        'client_id' => env('PLATFORM_SIGN_IN_CLIENT_ID'),
        'client_secret' => env('PLATFORM_SIGN_IN_CLIENT_SECRET'),
    ],

    // Mobile app client (public: authorization code + PKCE in the app). The
    // product's API (platform.api) accepts its access tokens; without it the
    // API answers 404.
    'mobile' => [
        'client_id' => env('PLATFORM_MOBILE_CLIENT_ID'),
    ],

    // Service client (client credentials) for the platform API.
    'service' => [
        'client_id' => env('PLATFORM_SERVICE_CLIENT_ID'),
        'client_secret' => env('PLATFORM_SERVICE_CLIENT_SECRET'),
    ],

    // Events endpoints of the shared modules this product sends to
    // (Events\Outbox), by module code.
    'modules' => [
        'finance' => env('PLATFORM_FINANCE_EVENTS_URL'),
        'hr' => env('PLATFORM_HR_EVENTS_URL'),
        'payroll' => env('PLATFORM_PAYROLL_EVENTS_URL'),
    ],

    // Signs webhooks and provisioning calls (whsec_...).
    'webhook_secret' => env('PLATFORM_WEBHOOK_SECRET'),

    // Further applications served by this deployment (ADR-0003: industry
    // modules of the ERP workspace application). Each is registered on the
    // platform on its own and has its own path, clients and webhook secret;
    // everything else above is shared. Its pages run behind
    // ["web", "platform.app:<code>", "platform"].
    //
    // PLATFORM_HOSTED=retail reads PLATFORM_RETAIL_PATH, _SIGN_IN_CLIENT_ID,
    // _SIGN_IN_CLIENT_SECRET, _SERVICE_CLIENT_ID, _SERVICE_CLIENT_SECRET and
    // _WEBHOOK_SECRET (and the optional _MOBILE_CLIENT_ID) into:
    //
    //   'retail' => [
    //       'path' => 'retail',
    //       'sign_in' => ['client_id' => ..., 'client_secret' => ...],
    //       'service' => ['client_id' => ..., 'client_secret' => ...],
    //       'mobile' => ['client_id' => ...],
    //       'webhook_secret' => ...,
    //   ],
    'hosted' => array_reduce(
        array_filter(array_map('trim', explode(',', (string) env('PLATFORM_HOSTED', '')))),
        function (array $hosted, string $code) {
            $env = 'PLATFORM_'.strtoupper($code).'_';
            $hosted[$code] = [
                'path' => env($env.'PATH'),
                'sign_in' => ['client_id' => env($env.'SIGN_IN_CLIENT_ID'), 'client_secret' => env($env.'SIGN_IN_CLIENT_SECRET')],
                'service' => ['client_id' => env($env.'SERVICE_CLIENT_ID'), 'client_secret' => env($env.'SERVICE_CLIENT_SECRET')],
                'mobile' => ['client_id' => env($env.'MOBILE_CLIENT_ID')],
                'webhook_secret' => env($env.'WEBHOOK_SECRET'),
            ];

            return $hosted;
        },
        [],
    ),

    // Local mirror of platform users: primary key = the platform subject
    // (uuid), with name and email. Updated at every sign-in.
    'user_model' => env('PLATFORM_USER_MODEL', 'App\\Models\\User'),

    // Where a signed-in user lands when there is no intended URL
    // (default: /<path>).
    'home' => null,

    // Register the sign-in, webhook and provisioning routes.
    'routes' => true,

    // Upper bound for caching organization lookups and effective access.
    // Webhooks drop the cache for their organization immediately.
    'cache_seconds' => 60,

    'http_timeout' => 10,

    // Http\Middleware\SecurityHeaders: the platform's browser policy.
    'security_headers' => [
        'csp' => env('PLATFORM_CSP', true),
        'csp_report_only' => env('PLATFORM_CSP_REPORT_ONLY', false),
        'hsts' => env('PLATFORM_HSTS', false),
        'hsts_max_age' => (int) env('PLATFORM_HSTS_MAX_AGE', 63072000),
    ],

];
