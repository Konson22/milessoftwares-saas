# Shared modules: Finance, HR and Payroll

Shared modules are separate services. Products send them **business events**; the platform
vouches for who may send what about which organization. This page is for both sides. Product
integration in general (sign-in, access API, webhooks) is in `docs/integration.md`.

## Contracts

JSON Schemas in `contracts/events/`:

| File                                         | Event                         |
| -------------------------------------------- | ----------------------------- |
| `envelope.schema.json`                       | the envelope every event uses |
| `finance.invoice.issued.v1.schema.json`      | invoice or receipt issued     |
| `finance.payment.received.v1.schema.json`    | payment taken                 |
| `hr.employee.joined.v1.schema.json`          | a person starts working       |
| `payroll.timesheet.submitted.v1.schema.json` | hours worked                  |

```json
{
    "event_id": "uuid",
    "event_type": "finance.payment.received",
    "schema_version": 1,
    "organization_id": "uuid",
    "source_app": "retail",
    "source_transaction_id": "PAY-2026-0001",
    "occurred_at": "2026-10-10T10:00:00+00:00",
    "payload": {
        "payment_reference": "PAY-2026-0001",
        "currency": "SSP",
        "amount_minor": 50000,
        "method": "mobile_money"
    }
}
```

- `source_transaction_id` is the product's own id for the business transaction. **Keep it stable
  across retries**; `event_id` may change per attempt.
- Amounts are integers in the currency's minor unit.
- **Sensitive HR and payroll data never travels in events**: pay, rates, bank details, identity
  numbers, dates of birth, health. The envelope validator rejects these keys at any depth
  (`Envelope::SENSITIVE_KEYS`); such data is entered inside the HR or Payroll module itself.
- A breaking payload change is a new `schema_version`; receivers support old versions for a
  published deprecation period.

## Sending (products)

1. Get a service token with scope `events.issue` (client credentials).
2. Exchange it for an **event token**, bound to one organization and one module:

    ```
    POST /api/v1/event-tokens          (auth host, Bearer service token)
    {"organization_id": "…", "audience": "finance"}
    →  {"token": "…", "token_type": "Bearer", "expires_in": 300}
    ```

    Issued only while the organization is active and entitled to **both** your product and the
    module; otherwise `404 not_entitled`. The token is RS256-signed by the platform key with
    `iss`, `aud` (module code), `sub` (your application code), `org`, `scope=events.write`, 5-minute
    expiry.

3. `POST` the envelope to the module's events endpoint with `Authorization: Bearer <event token>`.
4. On a timeout or 5xx, retry with the **same** `source_transaction_id`. Answers:
   `201 accepted`, `200 duplicate` (already applied; nothing happened), `409 conflict` (that
   transaction id was already used for a different event: fix the product), `401/403/422` (do
   not retry unchanged).

## Receiving (modules): `miles/shared-module-kit`

`packages/shared-module-kit` (composer path package, PHP 8.3+, no framework):

```php
$pem = Jwks::publicKeyPem($jwksJson, Jwks::kidOf($authorizationHeader)); // cache by kid
$token = (new EventTokenVerifier($issuer, $pem, 'finance'))->verify($authorizationHeader);
$envelope = Envelope::fromArray($decodedBody);

$result = (new Inbox($pdo))->accept($envelope, $token, function (Envelope $event) use ($ledger) {
    $ledger->post($event); // runs once per business transaction, in the same DB transaction
});

http_response_code($result->httpStatus()); // 201 / 200 / 409
```

- `Inbox` keys on `(source_app, organization_id, source_transaction_id)`
  (`schema/postgres.sql`, unique constraint). A retry is a no-op; a different payload under the
  same key is a conflict and is not applied. If your handler throws, nothing is stored and the
  retry can succeed.
- The token must vouch for the envelope: `org` must equal `organization_id` and `sub` must equal
  `source_app`, or `EventRejected` (answer 403).
- Report health every minute with `HealthReporter` (scope `health.write`); staff see it on the
  admin overview, and a service silent for 5 minutes shows as unknown.

## Reference implementation

`examples/finance-module` is a minimal Finance receiver built on the kit, and the sample product
sends it a payment twice:

```
php artisan platform:sample-app --entitle=<workspace-slug>
php -S 127.0.0.1:8200 -t examples/sample-app/public
php -S 127.0.0.1:8300 -t examples/finance-module/public
```

Sign in at `http://127.0.0.1:8200/login?org=<slug>`, then `POST /send-payment`: the first attempt
answers `accepted`, the retry `duplicate`, and `GET http://127.0.0.1:8300/ledger` shows one entry.
