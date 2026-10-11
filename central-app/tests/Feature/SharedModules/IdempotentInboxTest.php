<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Miles\SharedModuleKit\Envelope;
use Miles\SharedModuleKit\EventRejected;
use Miles\SharedModuleKit\EventToken;
use Miles\SharedModuleKit\Inbox;
use Miles\SharedModuleKit\InboxResult;

/*
| The acceptance rule for shared modules: a business event is applied exactly
| once, however many times the product sends it.
*/

beforeEach(function () {
    // The kit's own schema, as a module would create it in its database.
    DB::unprepared((string) file_get_contents(base_path('../packages/shared-module-kit/schema/postgres.sql')));

    $this->organizationId = (string) Str::uuid();
    $this->token = new EventToken('retail', $this->organizationId, (string) Str::uuid(), new DateTimeImmutable('+5 minutes'));
    $this->inbox = new Inbox(DB::connection()->getPdo());
    $this->posted = 0;
    $this->post = function (Envelope $envelope): void {
        $this->posted++;
    };
});

/**
 * @param  array<string, mixed>  $overrides
 */
function invoiceEvent(array $overrides = []): Envelope
{
    return Envelope::fromArray(array_merge([
        'event_id' => (string) Str::uuid(),
        'event_type' => 'finance.invoice.issued',
        'schema_version' => 1,
        'organization_id' => test()->organizationId,
        'source_app' => 'retail',
        'source_transaction_id' => 'SALE-1001',
        'occurred_at' => '2026-10-10T09:30:00+00:00',
        'payload' => ['invoice_number' => 'INV-1001', 'currency' => 'SSP', 'total_minor' => 250000, 'lines' => [['description' => 'Rice 25kg', 'quantity' => 2, 'amount_minor' => 250000]]],
    ], $overrides));
}

it('applies an event the first time', function () {
    $result = $this->inbox->accept(invoiceEvent(), $this->token, $this->post);

    expect($result->status)->toBe(InboxResult::ACCEPTED)
        ->and($result->httpStatus())->toBe(201)
        ->and($this->posted)->toBe(1)
        ->and(DB::table('shared_module_inbox')->whereNotNull('processed_at')->count())->toBe(1);
});

it('never applies a retried event twice, even with a new event id', function () {
    $first = $this->inbox->accept(invoiceEvent(), $this->token, $this->post);

    foreach (range(1, 3) as $_) {
        // Products may give each delivery attempt its own event_id.
        $retry = $this->inbox->accept(invoiceEvent(), $this->token, $this->post);

        expect($retry->status)->toBe(InboxResult::DUPLICATE)
            ->and($retry->httpStatus())->toBe(200)
            ->and($retry->originalEventId)->toBe($first->eventId);
    }

    expect($this->posted)->toBe(1)
        ->and(DB::table('shared_module_inbox')->count())->toBe(1);
});

it('treats the same content in a different key order as the same event', function () {
    $this->inbox->accept(invoiceEvent(), $this->token, $this->post);

    $reordered = invoiceEvent(['payload' => ['lines' => [['amount_minor' => 250000, 'quantity' => 2, 'description' => 'Rice 25kg']], 'total_minor' => 250000, 'currency' => 'SSP', 'invoice_number' => 'INV-1001']]);

    expect($this->inbox->accept($reordered, $this->token, $this->post)->status)->toBe(InboxResult::DUPLICATE)
        ->and($this->posted)->toBe(1);
});

it('flags a different event reusing a transaction id instead of applying it', function () {
    $this->inbox->accept(invoiceEvent(), $this->token, $this->post);

    $result = $this->inbox->accept(invoiceEvent(['payload' => ['invoice_number' => 'INV-1001', 'currency' => 'SSP', 'total_minor' => 999, 'lines' => [['description' => 'x', 'quantity' => 1, 'amount_minor' => 999]]]]), $this->token, $this->post);

    expect($result->status)->toBe(InboxResult::CONFLICT)
        ->and($result->httpStatus())->toBe(409)
        ->and($this->posted)->toBe(1);
});

it('keeps transactions of different sources and organizations apart', function () {
    $otherOrganization = (string) Str::uuid();

    $this->inbox->accept(invoiceEvent(), $this->token, $this->post);
    $this->inbox->accept(invoiceEvent(['source_app' => 'pharmacy']), new EventToken('pharmacy', $this->organizationId, 'j', new DateTimeImmutable), $this->post);
    $this->inbox->accept(invoiceEvent(['organization_id' => $otherOrganization]), new EventToken('retail', $otherOrganization, 'j', new DateTimeImmutable), $this->post);

    expect($this->posted)->toBe(3);
});

it('keeps nothing when processing fails, so the retry succeeds', function () {
    $failing = function () {
        throw new RuntimeException('ledger locked');
    };

    DB::beginTransaction();
    expect(fn () => $this->inbox->accept(invoiceEvent(), $this->token, $failing))->toThrow(RuntimeException::class);
    DB::rollBack();

    expect(DB::table('shared_module_inbox')->count())->toBe(0)
        ->and($this->inbox->accept(invoiceEvent(), $this->token, $this->post)->status)->toBe(InboxResult::ACCEPTED)
        ->and($this->posted)->toBe(1);
});

it('refuses events for an organization or source the token does not vouch for', function (array $override, string $reason) {
    try {
        $this->inbox->accept(invoiceEvent($override), $this->token, $this->post);
        $this->fail('Expected the event to be rejected.');
    } catch (EventRejected $e) {
        expect($e->reason)->toBe($reason);
    }

    expect($this->posted)->toBe(0)
        ->and(DB::table('shared_module_inbox')->count())->toBe(0);
})->with([
    'other organization' => [['organization_id' => '01a1aaaa-0000-7000-8000-000000000000'], 'organization_mismatch'],
    'other source app' => [['source_app' => 'school'], 'source_mismatch'],
]);
