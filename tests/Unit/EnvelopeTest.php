<?php

use Miles\SharedModuleKit\Envelope;
use Miles\SharedModuleKit\InvalidEnvelope;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function envelopeData(array $overrides = []): array
{
    return array_merge([
        'event_id' => '01a12700-0000-7000-8000-000000000001',
        'event_type' => 'hr.employee.joined',
        'schema_version' => 1,
        'organization_id' => '01A12700-0000-7000-8000-000000000002',
        'source_app' => 'school',
        'source_transaction_id' => 'STAFF-77',
        'occurred_at' => '2026-10-10T09:30:00+00:00',
        'payload' => ['employee_reference' => 'STAFF-77', 'display_name' => 'Grace Ayen', 'started_on' => '2026-10-01'],
    ], $overrides);
}

it('accepts a well-formed envelope and normalizes the organization id', function () {
    $envelope = Envelope::fromArray(envelopeData());

    expect($envelope->module())->toBe('hr')
        ->and($envelope->organizationId)->toBe('01a12700-0000-7000-8000-000000000002');
});

it('rejects malformed envelopes', function (array $overrides, string $message) {
    expect(fn () => Envelope::fromArray(envelopeData($overrides)))->toThrow(InvalidEnvelope::class, $message);
})->with([
    'event id' => [['event_id' => 'nope'], 'event_id must be a UUID'],
    'unknown module' => [['event_type' => 'crm.lead.created'], 'event_type'],
    'schema version' => [['schema_version' => '1'], 'schema_version'],
    'organization' => [['organization_id' => 'acme'], 'organization_id'],
    'transaction id' => [['source_transaction_id' => ''], 'source_transaction_id'],
    'timestamp' => [['occurred_at' => 'yesterday'], 'occurred_at'],
    'payload list' => [['payload' => ['a', 'b']], 'payload must be an object'],
]);

it('refuses sensitive HR and payroll fields anywhere in the payload', function (array $payload, string $field) {
    expect(fn () => Envelope::fromArray(envelopeData(['payload' => $payload])))
        ->toThrow(InvalidEnvelope::class, $field);
})->with([
    'salary' => [['employee_reference' => 'X', 'salary' => 1000], 'salary'],
    'nested bank account' => [['employee_reference' => 'X', 'details' => ['Bank_Account' => '123']], 'details.Bank_Account'],
    'in a list' => [['entries' => [['date' => '2026-10-01', 'hourly_rate' => 5]]], 'entries.0.hourly_rate'],
    'national id' => [['national_id' => 'A1'], 'national_id'],
]);

it('fingerprints content independently of key order', function () {
    $a = Envelope::fromArray(envelopeData(['payload' => ['a' => 1, 'b' => ['x' => 1, 'y' => 2]]]));
    $b = Envelope::fromArray(envelopeData(['event_id' => '01a12700-0000-7000-8000-000000000009', 'payload' => ['b' => ['y' => 2, 'x' => 1], 'a' => 1]]));
    $c = Envelope::fromArray(envelopeData(['payload' => ['a' => 2, 'b' => ['x' => 1, 'y' => 2]]]));

    expect($a->contentHash())->toBe($b->contentHash())
        ->and($a->contentHash())->not->toBe($c->contentHash());
});
