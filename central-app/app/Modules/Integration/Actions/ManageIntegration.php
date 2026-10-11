<?php

namespace App\Modules\Integration\Actions;

use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Support\TenantRedirectUris;
use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\OAuthClient;
use App\Modules\Integration\Jobs\SendWebhook;
use App\Modules\Integration\Models\WebhookDelivery;
use App\Modules\Integration\Models\WebhookEndpoint;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

/**
 * Staff-side setup of a product's integration: OIDC clients for sign-in, a
 * service client for the API, the webhook endpoint and its secret. Secrets
 * are returned once and stored only hashed (clients) or encrypted (webhooks).
 */
final class ManageIntegration
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * A browser sign-in client. Public clients (SPA, mobile) get no secret and
     * rely on PKCE alone. With a tenant path, every entitled organization gets
     * its exact callback, e.g. https://{slug}.<root>/retail/auth/callback.
     *
     * @param  list<string>  $redirectUris  fixed callbacks, exact match
     * @return array{client_id: string, client_secret: string|null}
     */
    public function registerSignInClient(Application $application, string $name, bool $confidential, array $redirectUris, ?string $tenantPath, ?Authenticatable $actor): array
    {
        /** @var OAuthClient $client */
        $client = $this->clients->createAuthorizationCodeGrantClient($name, $redirectUris, $confidential);

        $this->linkClient($application, $client, $name, $confidential ? 'confidential' : 'public', $tenantPath);
        $this->audit->record('integration.client_registered', $application, ['client_id' => $client->getKey(), 'type' => $confidential ? 'confidential' : 'public'], actor: $actor, actorType: 'staff');

        if ($tenantPath !== null) {
            Organization::query()->chunkById(200, function ($organizations): void {
                foreach ($organizations as $organization) {
                    TenantRedirectUris::sync($organization);
                }
            });
        }

        return ['client_id' => (string) $client->getKey(), 'client_secret' => $client->plainSecret];
    }

    /**
     * The product backend's own identity for the platform API.
     *
     * @return array{client_id: string, client_secret: string|null}
     */
    public function registerServiceClient(Application $application, string $name, ?Authenticatable $actor): array
    {
        /** @var OAuthClient $client */
        $client = $this->clients->createClientCredentialsGrantClient($name);

        $this->linkClient($application, $client, $name, 'service', null);
        $this->audit->record('integration.client_registered', $application, ['client_id' => $client->getKey(), 'type' => 'service'], actor: $actor, actorType: 'staff');

        return ['client_id' => (string) $client->getKey(), 'client_secret' => $client->plainSecret];
    }

    public function rotateClientSecret(Application $application, string $applicationClientId, ?Authenticatable $actor): string
    {
        $row = DB::table('application_clients')->where('id', $applicationClientId)->where('application_id', $application->id)->first();
        abort_if($row === null, 404);

        $client = is_string($row->oauth_client_ref) ? OAuthClient::query()->find($row->oauth_client_ref) : null;
        abort_if($client === null, 404);
        abort_unless($client->confidential(), 422, 'Public clients have no secret.');

        $secret = Str::random(40);
        $client->forceFill(['secret' => $secret])->save();

        $this->audit->record('integration.client_secret_rotated', $application, ['client_id' => $client->getKey()], actor: $actor, actorType: 'staff');

        return $secret;
    }

    public function disableClient(Application $application, string $applicationClientId, ?Authenticatable $actor): void
    {
        $row = DB::table('application_clients')->where('id', $applicationClientId)->where('application_id', $application->id)->first();
        abort_if($row === null, 404);

        DB::transaction(function () use ($row): void {
            DB::table('application_clients')->where('id', $row->id)->update(['status' => 'disabled', 'updated_at' => now()]);
            OAuthClient::query()->whereKey($row->oauth_client_ref)->update(['revoked' => true]);
            DB::table('oauth_access_tokens')->where('client_id', $row->oauth_client_ref)->update(['revoked' => true]);
            DB::table('application_client_redirect_uris')->where('application_client_id', $row->id)->delete();
        });

        $this->audit->record('integration.client_disabled', $application, ['client_id' => $row->oauth_client_ref], actor: $actor, actorType: 'staff');
    }

    /**
     * Sets the webhook URL. A new or rotated secret is returned once.
     */
    public function configureWebhook(Application $application, string $url, bool $rotateSecret, ?Authenticatable $actor): ?string
    {
        $endpoint = WebhookEndpoint::query()->where('application_id', $application->id)->first()
            ?? (new WebhookEndpoint)->forceFill(['application_id' => $application->id]);
        $secret = null;

        if (! $endpoint->exists || $rotateSecret) {
            $secret = 'whsec_'.Str::random(40);
            $endpoint->forceFill(['secret' => $secret]);
        }

        $endpoint->forceFill(['url' => $url, 'status' => 'active'])->save();
        $this->audit->record('integration.webhook_configured', $application, ['url' => $url, 'secret_rotated' => $secret !== null], actor: $actor, actorType: 'staff');

        return $secret;
    }

    public function redeliver(WebhookDelivery $delivery, ?Authenticatable $actor): void
    {
        $delivery->forceFill(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null])->save();
        SendWebhook::dispatch($delivery->id);

        $this->audit->record('integration.webhook_redelivered', $delivery, ['event_type' => $delivery->event_type], actor: $actor, actorType: 'staff');
    }

    private function linkClient(Application $application, OAuthClient $client, string $name, string $type, ?string $tenantPath): void
    {
        DB::table('application_clients')->insert([
            'id' => (string) Str::uuid7(),
            'application_id' => $application->id,
            'name' => $name,
            'client_identifier' => $application->code.'-'.$type.'-'.Str::lower(Str::random(6)),
            'client_type' => $type,
            'oauth_client_ref' => $client->getKey(),
            'status' => 'active',
            'tenant_redirect_path' => $tenantPath,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
