<?php

namespace App\Http\Controllers;

use App\Finance\NotYetPostable;
use App\Finance\PostEvent;
use App\Finance\UnsupportedEvent;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Api\PlatformApi;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\SignIn\KeySet;
use Miles\PlatformClient\SignIn\SignInFailed;
use Miles\PlatformClient\Tenancy\CurrentTenant;
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
 * Receives a product's business event and posts it exactly once.
 *
 *   201 accepted   200 duplicate   409 conflict (same transaction id, other content)
 *   401 bad token  403 token does not vouch for the event   422 not postable
 *   503 not yet (the workspace or an earlier event is missing): retry later
 */
class EventController extends Controller
{
    public function __invoke(
        Request $request,
        PlatformConfig $config,
        KeySet $keys,
        PlatformApi $platform,
        CurrentTenant $tenant,
        PostEvent $post,
    ): JsonResponse {
        $authorization = (string) $request->header('Authorization', '');

        try {
            $jwt = (string) preg_replace('/^Bearer\s+/i', '', $authorization);
            $verifier = new EventTokenVerifier($config->issuer(), $keys->publicKey(Jwks::kidOf($jwt)), $config->application());
            $token = $verifier->verify($authorization);
        } catch (InvalidEventToken|SignInFailed|RuntimeException $e) {
            return response()->json(['error' => 'invalid_token', 'detail' => $e->getMessage()], 401);
        }

        try {
            $envelope = Envelope::fromArray($request->json()->all());
        } catch (InvalidEnvelope $e) {
            return response()->json(['error' => 'invalid_envelope', 'detail' => $e->errors], 422);
        }

        // The token must vouch for this event before anything else is looked up.
        if ($token->organizationId !== $envelope->organizationId) {
            return response()->json(['error' => 'organization_mismatch'], 403);
        }
        if ($token->sourceApp !== $envelope->sourceApp) {
            return response()->json(['error' => 'source_mismatch'], 403);
        }

        if ($envelope->module() !== $config->application()) {
            return response()->json(['error' => 'wrong_module', 'detail' => "{$envelope->eventType} is not a {$config->application()} event."], 422);
        }

        try {
            $organization = $platform->organization($token->organizationId);
        } catch (RuntimeException) {
            return $this->later('The organization could not be looked up.');
        }

        return $tenant->run($organization, function () use ($envelope, $token, $post) {
            $workspace = Workspace::current();

            if ($workspace === null) {
                return $this->later('Finance is not set up for this organization yet.');
            }

            $currency = $envelope->payload['currency'] ?? null;
            if (is_string($currency) && $currency !== $workspace->currency) {
                return response()->json(['error' => 'currency_mismatch', 'detail' => "Finance keeps the books in {$workspace->currency}, not {$currency}."], 422);
            }

            try {
                $result = DB::transaction(fn (): InboxResult => (new Inbox(DB::connection()->getPdo()))
                    ->accept($envelope, $token, fn (Envelope $event) => $post->handle($event)));
            } catch (EventRejected $e) {
                return response()->json(['error' => $e->reason, 'detail' => $e->getMessage()], 403);
            } catch (NotYetPostable $e) {
                return $this->later($e->getMessage());
            } catch (UnsupportedEvent $e) {
                return response()->json(['error' => 'unsupported_event', 'detail' => $e->getMessage()], 422);
            } catch (ValidationException $e) {
                return response()->json(['error' => 'not_postable', 'detail' => $e->errors()], 422);
            }

            return response()->json($result->toArray(), $result->httpStatus());
        });
    }

    private function later(string $detail): JsonResponse
    {
        return response()->json(['error' => 'retry_later', 'detail' => $detail], 503, ['Retry-After' => '60']);
    }
}
