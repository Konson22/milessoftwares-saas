<?php

namespace App\Http\Controllers;

use App\FinanceIntegration\FinanceMode;
use App\FinanceIntegration\OutcomeNotYet;
use App\FinanceIntegration\OutcomeRefused;
use App\FinanceIntegration\Outcomes;
use App\Models\Organization;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\SignIn\KeySet;
use Miles\PlatformClient\SignIn\SignInFailed;
use Miles\SharedModuleKit\Envelope;
use Miles\SharedModuleKit\EventRejected;
use Miles\SharedModuleKit\EventTokenVerifier;
use Miles\SharedModuleKit\Inbox;
use Miles\SharedModuleKit\InboxResult;
use Miles\SharedModuleKit\InvalidEnvelope;
use Miles\SharedModuleKit\InvalidEventToken;
use Miles\SharedModuleKit\Jwks;
use RuntimeException;

/**
 * Finance's outcomes for requests Projects handed it (Phase 3B), server to
 * server: POST {workspace}/projects/events. No session, no CSRF. The caller
 * proves itself with a platform-signed event token whose audience is
 * Projects, whose subject is Finance and which is bound to one organization.
 *
 *   201 accepted   200 duplicate (a retry of the same event)
 *   401 bad token  403 token does not vouch for the event, or not Finance
 *   409 conflict: same transaction id with other content, an outcome out of
 *       turn for the request (invalid transition, stale sequence), or the
 *       organization is not integrated
 *   422 malformed, unknown request or payment, amount or currency mismatch
 *   503 an earlier outcome is missing: retry later
 *
 * Every refusal is kept in finance_inbox_rejections for reconciliation;
 * nothing refused changes anything else.
 */
class FinanceEventController extends Controller
{
    public function __invoke(Request $request, PlatformConfig $config, KeySet $keys, CurrentOrganization $current, Outcomes $outcomes): JsonResponse
    {
        $authorization = (string) $request->header('Authorization', '');

        try {
            $jwt = (string) preg_replace('/^Bearer\s+/i', '', $authorization);
            $verifier = new EventTokenVerifier($config->issuer(), $keys->publicKey(Jwks::kidOf($jwt)), $config->application());
            $token = $verifier->verify($authorization);
        } catch (InvalidEventToken|SignInFailed|RuntimeException $e) {
            return $this->refuse(null, null, 401, 'invalid_token', $e->getMessage());
        }

        try {
            $envelope = Envelope::fromArray($request->json()->all());
        } catch (InvalidEnvelope $e) {
            return $this->refuse(null, null, 422, 'invalid_envelope', implode('; ', $e->errors), $token->organizationId);
        }

        if ($token->organizationId !== $envelope->organizationId) {
            return $this->refuse($envelope, null, 403, 'organization_mismatch', 'The event is for a different organization than the token.', $token->organizationId);
        }
        if ($token->sourceApp !== $envelope->sourceApp) {
            return $this->refuse($envelope, null, 403, 'source_mismatch', 'The event names a different source than the token.');
        }
        if ($token->sourceApp !== config('finance_integration.source')) {
            return $this->refuse($envelope, null, 403, 'source_not_allowed', "Only Finance reports outcomes, not {$token->sourceApp}.");
        }
        if ($envelope->module() !== $config->application()) {
            return $this->refuse($envelope, null, 422, 'wrong_module', "{$envelope->eventType} is not a {$config->application()} event.");
        }

        $organization = Organization::query()->find($envelope->organizationId);
        if ($organization === null) {
            return $this->refuse($envelope, null, 422, 'unknown_organization', 'No organization here has that id.');
        }
        if (! FinanceMode::of($organization)->financeOwnsPayment()) {
            return $this->refuse($envelope, $envelope->payload, 409, 'not_integrated', 'The organization does not use Finance for payments ('.FinanceMode::of($organization)->value.').');
        }

        return $current->run($organization, function () use ($envelope, $token, $outcomes) {
            try {
                $result = DB::transaction(fn (): InboxResult => (new Inbox(DB::connection()->getPdo()))
                    ->accept($envelope, $token, fn (Envelope $event) => $outcomes->apply($event)));
            } catch (EventRejected $e) {
                return $this->refuse($envelope, null, 403, $e->reason, $e->getMessage());
            } catch (OutcomeNotYet $e) {
                return response()->json(['error' => 'retry_later', 'detail' => $e->getMessage()], 503, ['Retry-After' => '60']);
            } catch (OutcomeRefused $e) {
                return $this->refuse($envelope, $envelope->payload, $e->status, $e->reason, $e->getMessage());
            }

            if ($result->status === InboxResult::CONFLICT) {
                $this->keep($envelope, $envelope->payload, 409, 'conflict', 'Same source transaction id, different content.', null);
            }

            return response()->json($result->toArray(), $result->httpStatus());
        });
    }

    /**
     * @param  array<string, mixed>|null  $payload  kept only once the envelope passed validation
     */
    private function refuse(?Envelope $envelope, ?array $payload, int $status, string $reason, string $detail, ?string $organizationId = null): JsonResponse
    {
        $this->keep($envelope, $payload, $status, $reason, $detail, $organizationId);

        return response()->json(['error' => $reason, 'detail' => $detail], $status);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function keep(?Envelope $envelope, ?array $payload, int $status, string $reason, string $detail, ?string $organizationId): void
    {
        DB::table('finance_inbox_rejections')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $envelope !== null ? $envelope->organizationId : $organizationId,
            'source_app' => $envelope?->sourceApp,
            'event_id' => $envelope?->eventId,
            'event_type' => $envelope?->eventType,
            'source_transaction_id' => $envelope?->sourceTransactionId,
            'http_status' => $status,
            'reason' => mb_substr($reason, 0, 64),
            'detail' => mb_substr($detail, 0, 2000),
            'payload' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'received_at' => now(),
        ]);
    }
}
