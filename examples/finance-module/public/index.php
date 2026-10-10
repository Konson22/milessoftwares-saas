<?php

/*
|--------------------------------------------------------------------------
| Reference shared module: a minimal "Finance" receiver
|--------------------------------------------------------------------------
|
| Shows what a shared module does with miles/shared-module-kit:
|
|   POST /events   verify the event token (platform JWKS), validate the
|                  envelope, apply it exactly once through the Inbox
|   GET  /ledger   what was posted
|   POST /report-health  sends a health report to the platform
|
| Storage is SQLite so the example needs nothing else; a real module uses
| its own PostgreSQL database with packages/shared-module-kit/schema.
|
| Run: php artisan platform:sample-app      (also registers this module)
|      php -S 127.0.0.1:8300 -t examples/finance-module/public
|
*/

require __DIR__.'/../../../vendor/autoload.php';

use Miles\SharedModuleKit\Envelope;
use Miles\SharedModuleKit\EventRejected;
use Miles\SharedModuleKit\EventTokenVerifier;
use Miles\SharedModuleKit\HealthReporter;
use Miles\SharedModuleKit\Inbox;
use Miles\SharedModuleKit\InvalidEnvelope;
use Miles\SharedModuleKit\InvalidEventToken;
use Miles\SharedModuleKit\Jwks;

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$configFile = __DIR__.'/../config.local.php';
if (! is_file($configFile)) {
    respond(500, ['error' => 'Not configured. Run: php artisan platform:sample-app']);
}
$config = require $configFile;

$storage = __DIR__.'/../storage';
is_dir($storage) || mkdir($storage, 0775, true);
$pdo = new PDO('sqlite:'.$storage.'/finance.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec((string) file_get_contents(__DIR__.'/../../../packages/shared-module-kit/schema/postgres.sql'));
$pdo->exec('CREATE TABLE IF NOT EXISTS ledger (id INTEGER PRIMARY KEY, organization_id TEXT, source_app TEXT, reference TEXT, amount_minor INTEGER, currency TEXT, posted_at TEXT)');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST' && $path === '/events') {
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    try {
        $jwks = (string) file_get_contents(rtrim($config['backchannel'], '/').'/oauth/jwks', false, stream_context_create(['http' => [
            'header' => 'Host: '.parse_url($config['issuer'], PHP_URL_HOST),
            'timeout' => 5,
        ]]));
        $verifier = new EventTokenVerifier($config['issuer'], Jwks::publicKeyPem($jwks, Jwks::kidOf($authorization)), 'finance');
        $token = $verifier->verify($authorization);
        $envelope = Envelope::fromArray((array) json_decode((string) file_get_contents('php://input'), true));
    } catch (InvalidEventToken $e) {
        respond(401, ['error' => 'invalid_token', 'detail' => $e->getMessage()]);
    } catch (InvalidEnvelope $e) {
        respond(422, ['error' => 'invalid_envelope', 'detail' => $e->errors]);
    }

    try {
        $result = (new Inbox($pdo))->accept($envelope, $token, function (Envelope $event) use ($pdo): void {
            // The module's real work, in the same transaction as the inbox row.
            $pdo->prepare('INSERT INTO ledger (organization_id, source_app, reference, amount_minor, currency, posted_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([
                    $event->organizationId,
                    $event->sourceApp,
                    $event->payload['payment_reference'] ?? $event->payload['invoice_number'] ?? $event->sourceTransactionId,
                    (int) ($event->payload['amount_minor'] ?? $event->payload['total_minor'] ?? 0),
                    $event->payload['currency'] ?? null,
                    date(DATE_ATOM),
                ]);
        });
    } catch (EventRejected $e) {
        respond(403, ['error' => $e->reason]);
    }

    respond($result->httpStatus(), $result->toArray());
}

if ($method === 'GET' && $path === '/ledger') {
    respond(200, ['entries' => $pdo->query('SELECT organization_id, source_app, reference, amount_minor, currency FROM ledger ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'POST' && $path === '/report-health') {
    $entries = (int) $pdo->query('SELECT count(*) FROM ledger')->fetchColumn();
    (new HealthReporter($config['backchannel'], $config['service_client_id'], $config['service_client_secret'], parse_url($config['issuer'], PHP_URL_HOST)))
        ->report('ok', ['database' => 'ok'], '0.1.0', "{$entries} ledger entries");
    respond(200, ['reported' => 'ok']);
}

respond(404, ['error' => 'not_found']);
