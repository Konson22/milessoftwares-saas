<?php

namespace App\Sites\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Models\Application;
use App\Modules\Identity\Models\OAuthClient;
use App\Modules\Integration\Actions\ManageIntegration;
use App\Modules\Integration\Models\WebhookDelivery;
use App\Modules\Integration\Models\WebhookEndpoint;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Provisioning\Models\ProvisioningStep;
use App\Modules\Provisioning\Support\Provisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A product's integration settings: clients, webhook endpoint and
 * deliveries, provisioning endpoint. Newly issued secrets are flashed once
 * and never shown again.
 */
class IntegrationController extends Controller
{
    /** A private-use scheme named after a domain the publisher owns: com.example.app:/oauth */
    private const APP_REDIRECT = '~^[a-z][a-z0-9-]*(\.[a-z0-9-]+)+:/[A-Za-z0-9/_.-]*$~';

    public function __construct(private readonly ManageIntegration $integration) {}

    public function show(Request $request, Application $application): Response
    {
        abort_if($application->kind === ApplicationKind::Platform, 404);

        $endpoint = WebhookEndpoint::query()->where('application_id', $application->id)->first();

        return Inertia::render('admin/applications/integration', [
            'application' => [
                'id' => $application->id,
                'code' => $application->code,
                'name' => $application->name,
                'provisioningMethod' => $application->provisioning_method,
                'integrationEndpoint' => $application->integration_endpoint,
            ],
            'clients' => DB::table('application_clients')
                ->where('application_id', $application->id)
                ->orderBy('created_at')
                ->get()
                ->map(fn (object $row) => [
                    'id' => $row->id,
                    'name' => $row->name,
                    'type' => $row->client_type,
                    'clientId' => $row->oauth_client_ref,
                    'status' => $row->status,
                    'tenantRedirectPath' => $row->tenant_redirect_path,
                    // Raw column: the model accessor adds per-request tenant URIs.
                    'redirectUris' => $this->fixedRedirectUris($row->oauth_client_ref),
                    'tenantCallbacks' => DB::table('application_client_redirect_uris')->where('application_client_id', $row->id)->count(),
                ])
                ->all(),
            'webhook' => $endpoint === null ? null : ['url' => $endpoint->url, 'status' => $endpoint->status],
            'deliveries' => $endpoint === null ? [] : WebhookDelivery::query()
                ->where('webhook_endpoint_id', $endpoint->id)
                ->latest()
                ->limit(30)
                ->get()
                ->map(fn (WebhookDelivery $delivery) => [
                    'id' => $delivery->id,
                    'eventType' => $delivery->event_type,
                    'status' => $delivery->status,
                    'attempts' => $delivery->attempts,
                    'lastResponseCode' => $delivery->last_response_code,
                    'lastError' => $delivery->last_error,
                    'createdAt' => $delivery->created_at?->toIso8601String(),
                ])
                ->all(),
            'provisioningSteps' => ProvisioningStep::acrossTenants()
                ->where('application_id', $application->id)
                ->latest()
                ->limit(30)
                ->get()
                ->map(fn (ProvisioningStep $step) => [
                    'id' => $step->id,
                    'organizationId' => $step->organization_id,
                    'organization' => Organization::query()->whereKey($step->organization_id)->value('display_name'),
                    'status' => $step->status,
                    'attempts' => $step->attempts,
                    'lastError' => $step->last_error,
                ])
                ->all(),
            'issuedSecret' => $request->session()->get('issued_secret'),
        ]);
    }

    public function storeSignInClient(Request $request, Application $application): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'confidential' => ['required', 'boolean'],
            'redirect_uris' => ['nullable', 'string', 'max:4000'],
            'tenant_redirect_path' => ['nullable', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9\/_.-]*$/'],
        ]);

        $uris = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($validated['redirect_uris'] ?? '')) ?: [])));
        foreach ($uris as $uri) {
            // A mobile app (public) comes back on its own reverse-domain scheme (RFC 8252 7.1).
            $app = ! $validated['confidential'] && preg_match(self::APP_REDIRECT, $uri) === 1;
            abort_unless($app || (filter_var($uri, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~', $uri) === 1), 422, "Invalid redirect URI: {$uri}");
        }

        abort_if($uris === [] && empty($validated['tenant_redirect_path']), 422, 'Give at least one redirect URI or a tenant callback path.');

        $issued = $this->integration->registerSignInClient(
            $application,
            $validated['name'],
            (bool) $validated['confidential'],
            $uris,
            $validated['tenant_redirect_path'] ?? null,
            $this->actor($request),
        );

        return back()->with('issued_secret', ['label' => 'Sign-in client', ...$issued]);
    }

    public function storeServiceClient(Request $request, Application $application): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $issued = $this->integration->registerServiceClient($application, $validated['name'], $this->actor($request));

        return back()->with('issued_secret', ['label' => 'Service client', ...$issued]);
    }

    public function rotateSecret(Request $request, Application $application, string $client): RedirectResponse
    {
        $secret = $this->integration->rotateClientSecret($application, $client, $this->actor($request));
        $clientId = DB::table('application_clients')->where('id', $client)->value('oauth_client_ref');

        return back()->with('issued_secret', ['label' => 'Rotated client secret', 'client_id' => $clientId, 'client_secret' => $secret]);
    }

    public function disableClient(Request $request, Application $application, string $client): RedirectResponse
    {
        $this->integration->disableClient($application, $client, $this->actor($request));

        return back();
    }

    public function updateWebhook(Request $request, Application $application): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url:https,http', 'max:2048'],
            'rotate_secret' => ['sometimes', 'boolean'],
        ]);

        $secret = $this->integration->configureWebhook($application, $validated['url'], (bool) ($validated['rotate_secret'] ?? false), $this->actor($request));

        return $secret === null ? back() : back()->with('issued_secret', ['label' => 'Webhook signing secret', 'client_id' => null, 'client_secret' => $secret]);
    }

    public function updateProvisioning(Request $request, Application $application): RedirectResponse
    {
        $validated = $request->validate([
            'provisioning_method' => ['required', Rule::in(['none', 'manual', 'sync_api', 'async_api'])],
            'integration_endpoint' => ['nullable', 'required_if:provisioning_method,sync_api,async_api', 'url:https,http', 'max:255'],
        ]);

        $application->forceFill($validated)->save();

        return back();
    }

    public function redeliver(Request $request, Application $application, WebhookDelivery $delivery): RedirectResponse
    {
        abort_unless($delivery->endpoint->application_id === $application->id, 404);
        $this->integration->redeliver($delivery, $this->actor($request));

        return back();
    }

    public function retryProvisioning(Request $request, Application $application, string $step, Provisioner $provisioner): RedirectResponse
    {
        $record = ProvisioningStep::acrossTenants()->whereKey($step)->where('application_id', $application->id)->firstOrFail();
        $provisioner->retry(Organization::query()->findOrFail($record->organization_id), $record);

        return back();
    }

    /**
     * The client's own list (raw column): the model accessor would add the
     * per-request tenant URIs.
     *
     * @return list<string>
     */
    private function fixedRedirectUris(mixed $oauthClientId): array
    {
        $client = is_string($oauthClientId) ? OAuthClient::query()->find($oauthClientId) : null;
        $uris = $client === null ? [] : json_decode((string) $client->getRawOriginal('redirect_uris'), true);

        return is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [];
    }

    private function actor(Request $request): ?User
    {
        $staff = $request->attributes->get('staff');

        return $staff instanceof PlatformStaff ? $staff->user : null;
    }
}
