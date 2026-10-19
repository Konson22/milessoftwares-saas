<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Miles\PlatformClient\Events\Outbox;
use Miles\PlatformClient\Testing\InteractsWithPlatform;

uses(InteractsWithPlatform::class);

beforeEach(function () {
    config(['platform-client.modules.finance' => 'https://finance.internal/events']);
    $this->platform()->organization('acme');
    $this->outbox = app(Outbox::class);
});

function recordSale(string $reference = 'S-0001'): string
{
    return test()->asTenant('acme', fn () => test()->outbox->record('finance', 'finance.invoice.issued', 1, $reference, [
        'invoice_number' => $reference, 'currency' => 'SSP', 'total_minor' => 1250,
        'lines' => [['description' => 'Paracetamol', 'quantity' => 1, 'amount_minor' => 1250]],
    ]));
}

function outboxRow(string $id): object
{
    return DB::table('platform_outbox')->where('id', $id)->first();
}

it('records the event with the business change, never without it', function () {
    // Real (sync) queue: delivery is queued after commit, so a rolled-back
    // sale never reaches Finance.
    try {
        DB::transaction(function () {
            recordSale();
            throw new RuntimeException('the sale failed');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('platform_outbox')->count())->toBe(0)
        ->and($this->platform()->moduleEvents)->toBe([]);

    DB::transaction(fn () => recordSale());

    expect($this->platform()->moduleEvents['finance'])->toHaveCount(1);
});

it('delivers the envelope with an event token for the organization and module', function () {
    $id = recordSale();

    $row = outboxRow($id);
    expect($row->status)->toBe('delivered')
        ->and($row->delivered_at)->not->toBeNull()
        ->and($this->platform()->eventTokens)->toBe([['organization_id' => $this->platform()->organization('acme')['id'], 'audience' => 'finance', 'application' => 'pharmacy']]);

    $envelope = $this->platform()->moduleEvents['finance'][0];
    expect($envelope)
        ->event_type->toBe('finance.invoice.issued')
        ->schema_version->toBe(1)
        ->organization_id->toBe($this->platform()->organization('acme')['id'])
        ->source_app->toBe('pharmacy')
        ->source_transaction_id->toBe('S-0001')
        ->and($envelope['payload']['total_minor'])->toBe(1250);
});

it('retries a failing module with backoff, keeping the transaction id', function () {
    $this->platform()->moduleAnswers['finance'] = [503, 500];
    $id = recordSale();

    expect(outboxRow($id))->status->toBe('pending')->attempts->toBe(1);

    // Not due yet: nothing happens.
    $this->outbox->deliver($id);
    expect(outboxRow($id)->attempts)->toBe(1);

    $this->travel(61)->seconds();
    $this->outbox->deliver($id);
    expect(outboxRow($id))->status->toBe('pending')->attempts->toBe(2);

    $this->travel(301)->seconds();
    Artisan::call('platform:outbox');
    expect(outboxRow($id)->status)->toBe('delivered');

    $events = $this->platform()->moduleEvents['finance'];
    expect($events)->toHaveCount(3)
        ->and(array_unique(array_column($events, 'source_transaction_id')))->toBe(['S-0001'])
        ->and(count(array_unique(array_column($events, 'event_id'))))->toBe(3);
});

it('settles each answer the way the contract says', function (int $answer, string $status) {
    $this->platform()->moduleAnswers['finance'] = [$answer];

    expect(outboxRow(recordSale())->status)->toBe($status);
})->with([
    'accepted' => [201, 'delivered'],
    'already applied' => [200, 'duplicate'],
    'conflict: never retried' => [409, 'failed'],
    'invalid: never retried' => [422, 'failed'],
    'refused: never retried' => [403, 'failed'],
]);

it('skips organizations that do not use the module', function () {
    $this->platform()->notEntitledTo = ['finance'];

    expect(outboxRow(recordSale())->status)->toBe('skipped')
        ->and($this->platform()->moduleEvents)->toBe([]);
});

it('gives up after the last attempt and says so', function () {
    $this->platform()->moduleAnswers['finance'] = array_fill(0, Outbox::MAX_ATTEMPTS, 502);
    $id = recordSale();

    for ($attempt = 2; $attempt <= Outbox::MAX_ATTEMPTS; $attempt++) {
        $this->travel(7)->hours();
        $this->outbox->deliver($id);
    }

    expect(outboxRow($id))
        ->status->toBe('failed')
        ->attempts->toBe(Outbox::MAX_ATTEMPTS)
        ->last_error->toContain('Gave up');
});

it('waits while the module URL is not configured', function () {
    config(['platform-client.modules.finance' => null]);

    $id = recordSale();

    expect(outboxRow($id))->status->toBe('pending')->last_error->toContain('No events URL');
});

it('never delivers the same event twice at once', function () {
    Queue::fake();
    $id = recordSale();

    // Another worker claimed it a moment ago.
    DB::table('platform_outbox')->where('id', $id)->update(['next_attempt_at' => now()->addMinute()]);
    $this->outbox->deliver($id);

    expect($this->platform()->moduleEvents)->toBe([]);
});
