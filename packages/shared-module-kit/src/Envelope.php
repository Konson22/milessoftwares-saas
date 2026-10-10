<?php

namespace Miles\SharedModuleKit;

use DateTimeImmutable;

/**
 * A business event as products send it to a shared module. Schema:
 * contracts/events/envelope.schema.json.
 *
 * Sensitive HR and payroll fields (pay, bank details, identity numbers,
 * health) must never travel in events: they are rejected wherever they
 * appear in the payload, at any depth.
 */
final readonly class Envelope
{
    /**
     * Keys refused anywhere in a payload, compared case-insensitively.
     */
    public const SENSITIVE_KEYS = [
        'salary', 'base_salary', 'gross_pay', 'net_pay', 'pay_rate', 'hourly_rate',
        'bank_account', 'account_number', 'iban', 'swift', 'routing_number',
        'national_id', 'passport_number', 'tax_id', 'ssn', 'social_security_number',
        'date_of_birth', 'medical', 'health_condition', 'disability', 'religion', 'ethnicity',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public string $eventId,
        public string $eventType,
        public int $schemaVersion,
        public string $organizationId,
        public string $sourceApp,
        public string $sourceTransactionId,
        public DateTimeImmutable $occurredAt,
        public array $payload,
    ) {}

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidEnvelope
     */
    public static function fromArray(array $data): self
    {
        $errors = [];
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        $string = fn (string $key) => isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;

        if (! preg_match($uuid, (string) $string('event_id'))) {
            $errors[] = 'event_id must be a UUID';
        }
        if (! preg_match('/^(finance|hr|payroll)(\.[a-z][a-z0-9_]*){2}$/', (string) $string('event_type'))) {
            $errors[] = 'event_type must look like finance.invoice.issued';
        }
        if (! is_int($data['schema_version'] ?? null) || $data['schema_version'] < 1) {
            $errors[] = 'schema_version must be a positive integer';
        }
        if (! preg_match($uuid, (string) $string('organization_id'))) {
            $errors[] = 'organization_id must be a UUID';
        }
        if (! preg_match('/^[a-z][a-z0-9_]{0,31}$/', (string) $string('source_app'))) {
            $errors[] = 'source_app must be an application code';
        }
        $transaction = (string) $string('source_transaction_id');
        if ($transaction === '' || strlen($transaction) > 128) {
            $errors[] = 'source_transaction_id must be 1-128 characters';
        }
        $occurredAt = DateTimeImmutable::createFromFormat(DATE_ATOM, (string) $string('occurred_at')) ?: null;
        if ($occurredAt === null) {
            $errors[] = 'occurred_at must be an ISO 8601 timestamp';
        }
        if (! is_array($data['payload'] ?? null) || array_is_list($data['payload']) && $data['payload'] !== []) {
            $errors[] = 'payload must be an object';
        } elseif (($sensitive = self::sensitiveKeys($data['payload'])) !== []) {
            $errors[] = 'payload contains fields that must never be sent in events: '.implode(', ', $sensitive);
        }

        if ($errors !== []) {
            throw new InvalidEnvelope($errors);
        }

        /** @var array<string, mixed> $payload */
        $payload = $data['payload'];

        return new self(
            (string) $string('event_id'),
            (string) $string('event_type'),
            (int) $data['schema_version'],
            strtolower((string) $string('organization_id')),
            (string) $string('source_app'),
            $transaction,
            $occurredAt,
            $payload,
        );
    }

    /**
     * The module the event is addressed to: "finance", "hr" or "payroll".
     */
    public function module(): string
    {
        return explode('.', $this->eventType, 2)[0];
    }

    /**
     * Stable fingerprint of what the event says, used to tell a genuine
     * retry from a different event reusing the same transaction id.
     */
    public function contentHash(): string
    {
        return hash('sha256', json_encode([$this->eventType, $this->schemaVersion, self::sorted($this->payload)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<mixed>  $value
     * @return list<string>
     */
    private static function sensitiveKeys(array $value, string $path = ''): array
    {
        $found = [];

        foreach ($value as $key => $item) {
            $location = $path === '' ? (string) $key : "{$path}.{$key}";

            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $found[] = $location;
            }

            if (is_array($item)) {
                $found = [...$found, ...self::sensitiveKeys($item, $location)];
            }
        }

        return $found;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? self::sorted($item) : $item, $value);
    }
}
