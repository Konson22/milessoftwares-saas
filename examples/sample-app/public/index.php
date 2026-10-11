<?php

/*
|--------------------------------------------------------------------------
| Sample product: the five integration contracts, end to end
|--------------------------------------------------------------------------
|
| A deliberately small, framework-free app that integrates exactly like a
| real product (see docs/integration.md):
|
|   GET  /login?org=<slug>  OIDC authorization code + PKCE + state + nonce
|   GET  /callback          code exchange, id_token verified against JWKS
|   GET  /                  effective access via a client-credentials token
|   POST /webhooks          signed platform webhooks, deduplicated by event_id
|   POST /platform/tenants  signed, idempotent tenant provisioning
|   POST /send-payment      a business event to the Finance module, sent twice
|                           to show it is applied once
|
| Run:  php artisan platform:sample-app        (registers it, writes config)
|       php -S 127.0.0.1:8200 -t examples/sample-app/public
|
| It reuses central-app's vendor directory for lcobucci/jwt; a
| real product would install its own dependencies.
|
*/

require __DIR__.'/../../../central-app/vendor/autoload.php';
require __DIR__.'/../src/Platform.php';

use SampleApp\Platform;

$configFile = __DIR__.'/../config.local.php';
if (! is_file($configFile)) {
    http_response_code(500);
    exit('Not configured. Run: php artisan platform:sample-app');
}

session_name('sample_app');
session_start();

$platform = new Platform(require $configFile, __DIR__.'/../storage');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    match (true) {
        $method === 'GET' && $path === '/login' => $platform->startLogin((string) ($_GET['org'] ?? '')),
        $method === 'GET' && $path === '/callback' => $platform->completeLogin($_GET),
        $method === 'GET' && $path === '/logout' => $platform->logout(),
        $method === 'POST' && $path === '/send-payment' => $platform->sendPayment((string) ($_GET['reference'] ?? '')),
        $method === 'POST' && $path === '/webhooks' => $platform->receiveWebhook(),
        $method === 'POST' && $path === '/platform/tenants' => $platform->provisionTenant(),
        $method === 'GET' && $path === '/' => $platform->home(),
        default => $platform->respond(404, ['error' => 'not_found']),
    };
} catch (Throwable $e) {
    $platform->respond(400, ['error' => $e->getMessage()]);
}
