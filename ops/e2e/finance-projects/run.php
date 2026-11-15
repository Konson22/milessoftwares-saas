<?php

/**
 * End-to-end run of the Projects ⇄ Finance integration across the real
 * apps: Central (:8911), Finance (:8912) and Projects (:8913) as separate
 * PHP servers, each on its own PostgreSQL database (e2e_central,
 * e2e_finance, e2e_projects, recreated every run). Events travel over HTTP
 * with event tokens Central signs; organizations are provisioned by Central
 * calling each product, as in production.
 *
 *   php ops/e2e/finance-projects/run.php            set up, run every scenario, stop
 *   php ops/e2e/finance-projects/run.php --keep     leave the servers running
 *
 * Local development only. It never touches the development databases (the
 * drivers refuse to boot on another database) and never runs in production.
 * Results: E2E_RUN_DIR (default: the system temp dir /tatook-e2e)/results.json.
 */

require __DIR__.'/lib.php';
// Collections only (collect()); no app is booted in this process.
require e2e_root().'/project-management/vendor/autoload.php';

$run = e2e_run_dir();
@unlink("{$run}/state.json");
$results = [];
$servers = [];

function say(string $line): void
{
    fwrite(STDOUT, $line."\n");
}

/**
 * Runs a driver (central.php, finance.php, projects.php) and decodes its last JSON line.
 *
 * @param  list<string>  $args
 * @param  array<string, string>  $env
 */
function drive(string $driver, array $args, array $env = []): mixed
{
    $command = array_merge([PHP_BINARY, __DIR__."/{$driver}.php"], $args);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, array_merge(getenv(), $env));
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $code = proc_close($process);
    $lines = array_values(array_filter(explode("\n", trim((string) $out))));
    $decoded = $lines === [] ? null : json_decode((string) end($lines), true);
    if ($code !== 0 || ($lines !== [] && json_last_error() !== JSON_ERROR_NONE)) {
        throw new RuntimeException("{$driver} ".implode(' ', $args)." failed ({$code}):\n".trim((string) $out)."\n".trim((string) $err));
    }

    return $decoded;
}

/** @param list<string> $args */
function artisan(string $app, array $args): string
{
    $command = array_merge([PHP_BINARY, 'artisan'], $args);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, e2e_root().'/'.E2E_FOLDERS[$app], array_merge(getenv(), e2e_env($app)));
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException("{$app}: artisan ".implode(' ', $args)." failed:\n{$out}");
    }

    return (string) $out;
}

function serve(string $app): mixed
{
    $root = e2e_root().'/'.E2E_FOLDERS[$app];
    $log = fopen(e2e_run_dir()."/{$app}-server.log", 'w');
    // Laravel's router script serves from the working directory: public/.
    $process = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.E2E_PORTS[$app], $root.'/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],
        [0 => ['pipe', 'r'], 1 => $log, 2 => $log], $pipes, $root.'/public', array_merge(getenv(), e2e_env($app)));
    for ($i = 0; $i < 50; $i++) {
        if (@fsockopen('127.0.0.1', E2E_PORTS[$app], $errno, $errstr, 0.2)) {
            return $process;
        }
        usleep(100_000);
    }
    throw new RuntimeException("{$app} did not start on ".E2E_PORTS[$app]);
}

function stop(mixed $process): void
{
    $status = proc_get_status($process);
    if ($status['running']) {
        // php -S has no children here; on Windows taskkill ends the tree.
        PHP_OS_FAMILY === 'Windows' ? exec("taskkill /F /T /PID {$status['pid']} 2>NUL") : proc_terminate($process);
    }
    proc_close($process);
}

/**
 * Posts an envelope straight to a product, as a forger (or a second sender) would.
 *
 * @param  array<string, mixed>  $envelope
 * @return array{status: int, body: mixed}
 */
function post(string $app, string $token, array $envelope): array
{
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'ignore_errors' => true, 'timeout' => 20,
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer {$token}\r\n",
        'content' => json_encode($envelope),
    ]]);
    $body = file_get_contents('http://127.0.0.1:'.E2E_PORTS[$app]."/{$app}/events", false, $context);
    preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0] ?? '', $m);

    return ['status' => (int) ($m[1] ?? 0), 'body' => json_decode((string) $body, true) ?? $body];
}

/** @param array<string, mixed> $payload */
function envelope(string $type, string $organizationId, string $source, string $transaction, array $payload): array
{
    return [
        'event_id' => sprintf('%s', bin2hex(random_bytes(4))).'-'.bin2hex(random_bytes(2)).'-4'.substr(bin2hex(random_bytes(2)), 1).'-8'.substr(bin2hex(random_bytes(2)), 1).'-'.bin2hex(random_bytes(6)),
        'event_type' => $type, 'schema_version' => 1, 'organization_id' => $organizationId, 'source_app' => $source,
        'source_transaction_id' => $transaction, 'occurred_at' => gmdate('c'), 'payload' => $payload,
    ];
}

/**
 * Checks a payload against its schema in contracts/events: every required
 * key present, no key the schema does not list (additionalProperties false).
 *
 * @param  array<string, mixed>  $payload
 * @return list<string>
 */
function schemaProblems(string $type, array $payload): array
{
    $schema = json_decode((string) file_get_contents(e2e_root()."/contracts/events/{$type}.v1.schema.json"), true);
    $problems = [];
    foreach ($schema['required'] ?? [] as $key) {
        if (! array_key_exists($key, $payload)) {
            $problems[] = "{$type}: missing {$key}";
        }
    }
    if (($schema['additionalProperties'] ?? true) === false) {
        foreach (array_keys($payload) as $key) {
            if (! array_key_exists($key, $schema['properties'] ?? [])) {
                $problems[] = "{$type}: unexpected {$key}";
            }
        }
    }

    return $problems;
}

$current = null;
function scenario(string $name, callable $body): void
{
    global $results, $current;
    $current = $name;
    $started = microtime(true);
    try {
        $notes = $body();
        $results[] = ['scenario' => $name, 'passed' => true, 'notes' => $notes, 'seconds' => round(microtime(true) - $started, 1)];
        say("  PASS  {$name}");
    } catch (Throwable $e) {
        $results[] = ['scenario' => $name, 'passed' => false, 'error' => $e->getMessage(), 'seconds' => round(microtime(true) - $started, 1)];
        say("  FAIL  {$name}\n        ".str_replace("\n", "\n        ", $e->getMessage()));
    }
}

function check(bool $condition, string $what, mixed $actual = null): void
{
    if (! $condition) {
        throw new RuntimeException("Expected {$what}".($actual === null ? '' : '; got '.json_encode($actual, JSON_UNESCAPED_SLASHES)));
    }
}

try {
    // ── Set up ─────────────────────────────────────────────────────────
    say('Creating the e2e databases…');
    $pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=postgres', e2e_dev_env('DB_HOST'), e2e_dev_env('DB_PORT')), e2e_dev_env('DB_USERNAME'), e2e_dev_env('DB_PASSWORD'));
    foreach (array_keys(E2E_FOLDERS) as $app) {
        $pdo->exec("DROP DATABASE IF EXISTS e2e_{$app} WITH (FORCE)");
        $pdo->exec("CREATE DATABASE e2e_{$app}");
    }
    foreach (array_keys(E2E_FOLDERS) as $app) {
        say("Migrating {$app}…");
        artisan($app, ['migrate', '--force', '--no-interaction']);
    }

    say('Setting up Central (catalogs, clients, organizations)…');
    $setup = drive('central', ['setup']);
    $orgs = $setup['organizations'];

    foreach (array_keys(E2E_FOLDERS) as $app) {
        $servers[$app] = serve($app);
    }
    say('Servers up: '.implode(', ', array_map(fn ($a) => "{$a} :".E2E_PORTS[$a], array_keys($servers))));

    foreach (['acme', 'globex'] as $slug) {
        foreach (['finance', 'projects'] as $app) {
            drive('central', ['entitle', $slug, $app]);
        }
        drive('finance', ['staff', $slug]);
    }
    foreach (['reconciliation_required', 'ready', 'integrated'] as $mode) {
        $changed = drive('projects', ['mode', 'acme', $mode, "E2E: {$mode}"]);
        check($changed['mode'] === $mode, "acme to switch to {$mode}", $changed);
    }
    say('acme is integrated (through projects:finance-mode\'s checks, Finance connected via Central).');
    say('');

    // ── Scenarios ──────────────────────────────────────────────────────
    $spend = fn (string $code, int $amount = 120_000, ?string $currency = null) => drive('projects', array_values(array_filter(['expense', 'acme', $code, (string) $amount, $currency])));
    $p = fn (string $expenseId) => drive('projects', ['request', 'acme', $expenseId]);
    $f = fn (string $action, string $requestId, string ...$more) => drive('finance', [$action, 'acme', $requestId, ...$more]);
    $outcomes = fn () => drive('finance', ['outbox', 'acme']);

    scenario('01 submission: approved spending reaches Finance as one request', function () use ($spend, $f, &$main) {
        $main = $spend('WASH');
        check($main['status'] === 'submitted', 'Projects request submitted', $main);
        $sent = collect(drive('projects', ['outbox', 'acme']))->where('event_type', 'finance.spending.submitted')->values();
        check(count($sent) === 1 && $sent[0]['status'] === 'delivered', 'the submitted event delivered (201)', $sent);
        check(schemaProblems('finance.spending.submitted', $sent[0]['payload']) === [], 'submitted payload to match its schema', schemaProblems('finance.spending.submitted', $sent[0]['payload']));
        $row = $f('row', $main['request_id']);
        check($row['status'] === 'received' && $row['amount_minor'] === 120_000 && $row['payment_request'] === null, 'Finance to hold it as received, nothing raised or paid', $row);

        return ['request_id' => $main['request_id'], 'finance' => $row['status']];
    });

    scenario('02 duplicate delivery (Projects → Finance): exactly one Finance request', function () use ($f, &$main) {
        $sent = collect(drive('projects', ['outbox', 'acme']))->firstWhere('event_type', 'finance.spending.submitted');
        $again = drive('projects', ['redeliver', $sent['id']]);
        check($again['status'] === 'duplicate', 'the second delivery answered as duplicate (200)', $again);
        check(drive('finance', ['count', 'acme', '-']) === 1, 'one Finance request');

        return ['second_delivery' => $again['status']];
    });

    scenario('03 Finance accepts: taken in as a payment request, Projects told', function () use ($f, $p, &$main) {
        $taken = $f('prepare', $main['request_id'], '120000');
        check($taken['ok'] && $taken['result']['status'] === 'pending_approval', 'a payment request waiting for approval', $taken);
        $state = $p($main['expense_id']);
        check($state['status'] === 'accepted' && $state['finance_reference'] === $taken['result']['payment_request'], 'Projects accepted with Finance\'s reference', $state);
        check($state['open_commitment'] === 120_000 && $state['outstanding'] === 0, 'nothing authorized yet', $state);

        return ['payment_request' => $taken['result']['payment_request']];
    });

    scenario('04 financial approval is not payment', function () use ($f, $p, &$main) {
        $own = drive('finance', ['approve', 'acme', $main['request_id']]);
        check($own['ok'], 'approval by the approver', $own);
        $state = $p($main['expense_id']);
        check($state['status'] === 'approved' && $state['approved'] === 120_000 && $state['paid'] === 0 && $state['outstanding'] === 120_000, 'approved, nothing paid, all of it outstanding', $state);

        return ['projects' => $state['status'], 'outstanding' => $state['outstanding']];
    });

    scenario('05 payment: disbursed in Finance, paid in Projects', function () use ($f, $p, &$main) {
        $paid = $f('disburse', $main['request_id']);
        check($paid['ok'] && $paid['result'] === 'disbursed', 'disbursed by the cashier', $paid);
        $state = $p($main['expense_id']);
        check($state['status'] === 'paid' && $state['net_paid'] === 120_000 && $state['open_commitment'] === 0, 'Projects paid in full, nothing left committed', $state);
        $figures = drive('projects', ['figures', 'acme', 'WASH']);
        check($figures['paidMinor'] === 120_000 && $figures['committedMinor'] === 0, 'project figures: paid 120,000, committed 0', $figures);
        foreach (drive('finance', ['outbox', 'acme']) as $event) {
            check($event['status'] === 'delivered', "{$event['event_type']} delivered", $event);
            check(schemaProblems($event['event_type'], $event['payload']) === [], "{$event['event_type']} to match its schema", schemaProblems($event['event_type'], $event['payload']));
        }

        return ['history' => $state['history']];
    });

    scenario('06 duplicate delivery (Finance → Projects): applied once', function () use ($p, $orgs, &$main) {
        $last = collect(drive('finance', ['outbox', 'acme']))->last();
        $token = drive('finance', ['token', 'acme', 'projects']);
        $envelope = envelope($last['event_type'], $orgs['acme'], 'finance', $last['source_transaction_id'], $last['payload']);
        $again = post('projects', $token, $envelope);
        check($again['status'] === 200, 'a replayed outcome answered 200 (already applied)', $again);
        $changed = post('projects', $token, envelope($last['event_type'], $orgs['acme'], 'finance', $last['source_transaction_id'], [...$last['payload'], 'amount_minor' => 1]));
        check($changed['status'] === 409, 'the same transaction with other content refused 409', $changed);
        $state = $p($main['expense_id']);
        check($state['net_paid'] === 120_000, 'still paid once', $state);

        return ['replay' => $again['status'], 'conflicting_replay' => $changed['status']];
    });

    scenario('07–08 out-of-order delivery and missing-sequence recovery', function () use ($spend, $p) {
        $spent = $spend('SEED', 90_000);
        $id = $spent['request_id'];
        drive('finance', ['prepare', 'acme', $id, '90000'], ['E2E_HOLD' => '1']);
        drive('finance', ['approve', 'acme', $id], ['E2E_HOLD' => '1']);
        $held = collect(drive('finance', ['outbox', 'acme']))->filter(fn ($e) => ($e['payload']['request_id'] ?? null) === $id)->values();
        check(count($held) === 2 && $held->every(fn ($e) => $e['status'] === 'pending'), 'two outcomes held (accepted, approved)', $held);
        [$first, $second] = [$held->firstWhere('payload.sequence', 1), $held->firstWhere('payload.sequence', 2)];

        $later = drive('finance', ['deliver', $second['id']]);
        check($later[$second['id']]['status'] === 'pending' && str_contains((string) $later[$second['id']]['last_error'], '503'), 'sequence 2 first: answered 503 and kept for retry', $later);
        check($p($spent['expense_id'])['status'] === 'submitted', 'Projects unchanged while 1 is missing');

        drive('finance', ['deliver', $first['id']]);
        drive('finance', ['due']);
        $retried = drive('finance', ['deliver', $second['id']]);
        check($retried[$second['id']]['status'] === 'delivered', 'sequence 2 delivered on retry once 1 arrived', $retried);
        $state = $p($spent['expense_id']);
        check($state['status'] === 'approved' && $state['sequence'] === 2, 'Projects applied both in order', $state);

        return ['history' => $state['history']];
    });

    scenario('09 currency mismatch: a USD request against SSP books', function () use ($spend, $f, $p) {
        $usd = $spend('USDP', 50_000, 'USD');
        $take = $f('prepare', $usd['request_id'], '50000');
        check(! $take['ok'], 'Finance refuses to pay a USD request from an SSP account', $take);
        $refuse = $f('refuse', $usd['request_id'], 'No USD account: request in SSP');
        check($refuse['ok'], 'Finance refuses the request', $refuse);
        $state = $p($usd['expense_id']);
        check($state['status'] === 'rejected' && $state['open_commitment'] === 0, 'Projects rejected, nothing committed', $state);

        return ['finance_refusal' => $take['refused'], 'projects' => $state['history']];
    });

    scenario('10 amount mismatch: more than asked refused in Finance; forged overpayment refused in Projects', function () use ($spend, $f, $p, $orgs, &$main) {
        $big = $spend('BIG', 40_000);
        $over = $f('prepare', $big['request_id'], '40001');
        check(! $over['ok'], 'Finance refuses to raise more than requested', $over);
        // A payment beyond what was approved, under Finance's real token: Projects refuses it.
        $token = drive('finance', ['token', 'acme', 'projects']);
        $state = $p($main['expense_id']);
        $forged = post('projects', $token, envelope('projects.finance_payment.completed', $orgs['acme'], 'finance', 'e2e-forged-overpay', [
            'organization_id' => $orgs['acme'], 'request_id' => $state['request_id'], 'correlation_id' => $state['correlation_id'], 'sequence' => $state['sequence'] + 1,
            'status' => 'paid', 'finance_reference' => $state['finance_reference'], 'payment_id' => 'E2E-EXTRA', 'amount_minor' => 1, 'currency' => 'SSP', 'paid_on' => gmdate('Y-m-d'),
        ]));
        check($forged['status'] === 422 && ($forged['body']['error'] ?? null) === 'amount_mismatch', 'Projects refuses payments beyond the approval (422)', $forged);
        check($p($main['expense_id'])['net_paid'] === 120_000, 'Projects still shows 120,000 paid');

        return ['finance' => $over['refused'], 'projects' => $forged['body']['error']];
    });

    scenario('11 partial approval and payment: Finance pays less than asked', function () use ($spend, $f, $p) {
        $part = $spend('PART', 100_000);
        $f('prepare', $part['request_id'], '80000');
        $f('approve', $part['request_id']);
        $f('disburse', $part['request_id']);
        $state = $p($part['expense_id']);
        check($state['status'] === 'paid' && $state['requested'] === 100_000 && $state['approved'] === 80_000 && $state['net_paid'] === 80_000 && $state['open_commitment'] === 0,
            'requested 100,000, approved and paid 80,000, the unapproved 20,000 not committed', $state);
        $figures = drive('projects', ['figures', 'acme', 'PART']);
        check($figures['paidMinor'] === 80_000 && $figures['committedMinor'] === 0, 'project figures 80,000 paid, 0 committed', $figures);

        return ['requested' => 100_000, 'approved' => 80_000, 'paid' => 80_000];
    });

    scenario('13 reversal, then resubmission as revision 2 under the same correlation id', function () use ($f, $p, &$main) {
        $reversed = $f('reverse', $main['request_id'], 'Paid to the wrong account');
        check($reversed['ok'], 'Finance reverses the payment journal', $reversed);
        $state = $p($main['expense_id']);
        check($state['status'] === 'reversed' && $state['net_paid'] === 0 && $state['open_commitment'] === 0, 'Projects reversed, nothing paid or authorized', $state);
        check((drive('projects', ['reconcile', 'acme'])['unresolved_reversal'] ?? 0) === 1, 'reconciliation flags the unresolved reversal');

        $again = drive('projects', ['resubmit', 'acme', $main['expense_id'], '120000', 'Corrected bank details']);
        check($again['ok'] && $again['result'] === 2, 'revision 2 sent', $again);
        $next = $p($main['expense_id']);
        check($next['revision'] === 2 && $next['correlation_id'] === $state['correlation_id'] && $next['status'] === 'submitted', 'revision 2, same correlation id', $next);
        $row = $f('row', $next['request_id']);
        check($row['status'] === 'received' && $row['revision'] === 2 && $row['correlation_id'] === $state['correlation_id'], 'Finance holds revision 2 under the same correlation', $row);
        $old = $f('row', $state['request_id']);
        check($old['status'] === 'reversed', 'revision 1 stays reversed in Finance', $old);

        return ['revision' => 2];
    });

    scenario('14 cancellation while Finance is processing', function () use ($spend, $f, $p) {
        $c = $spend('CANC', 30_000);
        $f('prepare', $c['request_id'], '30000');
        $cancel = drive('projects', ['cancel', 'acme', $c['expense_id'], 'Supplier withdrew']);
        check($cancel['ok'] && $cancel['result']['status'] === 'cancellation_requested', 'Projects asks Finance to cancel', $cancel);
        // Finance approves before it sees to the cancellation: the cancellation still stands in Projects.
        $f('approve', $c['request_id']);
        $state = $p($c['expense_id']);
        check($state['status'] === 'cancellation_requested' && $state['approved'] === 30_000 && $state['open_commitment'] === 0, 'still cancellation_requested, approval recorded, nothing committed', $state);
        $row = $f('row', $c['request_id']);
        check($row['cancellation_requested'] === true, 'Finance shows Projects asked to cancel', $row);
        $withdraw = $f('withdraw', $c['request_id']);
        check($withdraw['ok'], 'Finance withdraws its payment request', $withdraw);
        $state = $p($c['expense_id']);
        check($state['status'] === 'cancelled', 'Projects cancelled', $state);

        return ['history' => $state['history']];
    });

    scenario('15 cross-tenant attempt: a token for globex carrying acme\'s event', function () use ($orgs, &$main) {
        $globexToken = drive('finance', ['token', 'globex', 'projects']);
        $state = drive('projects', ['request', 'acme', $main['expense_id']]);
        $attempt = post('projects', $globexToken, envelope('projects.finance_request.rejected', $orgs['acme'], 'finance', 'e2e-cross-tenant', [
            'organization_id' => $orgs['acme'], 'request_id' => $state['request_id'], 'correlation_id' => $state['correlation_id'], 'sequence' => 1, 'status' => 'rejected', 'finance_reference' => null, 'reason' => 'x',
        ]));
        check(in_array($attempt['status'], [401, 403], true), 'refused (token bound to globex)', $attempt);
        check(drive('projects', ['request', 'acme', $main['expense_id']])['status'] === 'submitted', 'acme request untouched');

        return ['status' => $attempt['status'], 'error' => $attempt['body']['error'] ?? null];
    });

    scenario('16 forged and expired tokens', function () use ($orgs) {
        $forged = post('projects', 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJmaW5hbmNlIn0.bm90LWEtc2lnbmF0dXJl', envelope('projects.finance_request.rejected', $orgs['acme'], 'finance', 'e2e-forged', ['request_id' => 'x']));
        check($forged['status'] === 401, 'a forged token refused 401', $forged);
        $expired = drive('central', ['sign-expired', 'acme', 'projects', 'finance'])['token'];
        $late = post('projects', $expired, envelope('projects.finance_request.rejected', $orgs['acme'], 'finance', 'e2e-expired', ['request_id' => 'x']));
        check($late['status'] === 401, 'a token signed with Central\'s key but expired refused 401', $late);

        return ['forged' => $forged['status'], 'expired' => $late['status']];
    });

    scenario('17 revoked entitlement: Central refuses the event token', function () {
        drive('central', ['revoke', 'globex', 'projects']);
        try {
            drive('finance', ['token', 'globex', 'projects']);
            $refused = false;
        } catch (RuntimeException $e) {
            $refused = true;
            $why = substr($e->getMessage(), 0, 300);
        }
        check($refused, 'Central refuses an event token for an organization without Projects');

        return ['token_refused' => true, 'why' => $why ?? null];
    });

    scenario('18 delivery failure and recovery: Finance down, then back', function () use (&$servers, $spend, $f, $p) {
        stop($servers['finance']);
        $down = $spend('DOWN', 20_000);
        $sent = collect(drive('projects', ['outbox', 'acme']))->firstWhere('payload.request_id', $down['request_id']);
        check($sent['status'] === 'pending' && $sent['attempts'] === 1, 'the submitted event kept for retry while Finance is down', $sent);
        $servers['finance'] = serve('finance');
        $recovered = drive('projects', ['due']);
        $after = collect(drive('projects', ['outbox', 'acme']))->firstWhere('payload.request_id', $down['request_id']);
        check($after['status'] === 'delivered', 'delivered once Finance is back', $after);
        check($f('row', $down['request_id'])['status'] === 'received', 'Finance has it, once');

        return ['first_error' => $sent['last_error']];
    });

    scenario('10b reconciliation after the run', function () {
        $counts = drive('projects', ['reconcile', 'acme']);
        $rejections = drive('projects', ['rejections']);

        return ['reconcile' => $counts, 'inbox_rejections' => $rejections];
    });
} catch (Throwable $e) {
    $results[] = ['scenario' => 'setup', 'passed' => false, 'error' => $e->getMessage()];
    say("SETUP FAILED: {$e->getMessage()}");
} finally {
    file_put_contents("{$run}/results.json", json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if (! in_array('--keep', $argv, true)) {
        foreach ($servers as $server) {
            stop($server);
        }
    }
    $passed = count(array_filter($results, fn ($r) => $r['passed']));
    say('');
    say("{$passed} of ".count($results).' passed. Results: '.$run.'/results.json');
}
