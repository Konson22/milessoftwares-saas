<?php

namespace App\Modules\Provisioning\Jobs;

use App\Modules\Integration\Jobs\SendWebhook;
use App\Modules\Integration\Models\WebhookEndpoint;
use App\Modules\Integration\Support\WebhookSignature;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Provisioning\Models\ProvisioningStep;
use App\Modules\Provisioning\Support\Provisioner;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * POST {integration_endpoint}/tenants for one step. The request is signed
 * like a webhook (same secret) and carries the step id as Idempotency-Key,
 * so a retried call can never create two tenants.
 *
 *   2xx  -> succeeded (synchronous products)
 *   202  -> running; the product calls /api/v1/provisioning/steps/{id}/complete
 *   else -> retried with the webhook backoff, then failed
 */
class ProvisionTenant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $stepId,
    ) {}

    public function handle(TenantContext $tenant): void
    {
        $organization = Organization::query()->with('primarySlug')->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $tenant->run($organization, function () use ($organization): void {
            $step = ProvisioningStep::query()->with(['application', 'run'])->find($this->stepId);

            if ($step === null || $step->status !== 'pending' || $step->application === null) {
                return;
            }

            $endpoint = WebhookEndpoint::query()->where('application_id', $step->application_id)->where('status', 'active')->first();

            if ($endpoint === null) {
                Provisioner::finish($step, false, 'The application has no active integration endpoint secret (webhook endpoint).');

                return;
            }

            $body = json_encode([
                'step_id' => $step->id,
                'application' => $step->application->code,
                'organization' => [
                    'id' => $organization->id,
                    'slug' => $organization->primarySlug?->slug,
                    'display_name' => $organization->display_name,
                    'country_code' => $organization->country_code,
                    'default_currency' => $organization->default_currency,
                    'timezone' => $organization->timezone,
                ],
                'callback_url' => route('api.provisioning.complete', ['step' => $step->id]),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $step->forceFill(['attempts' => $step->attempts + 1])->save();

            try {
                $response = Http::timeout(15)
                    ->withHeaders([
                        'Idempotency-Key' => $step->id,
                        'Content-Type' => 'application/json',
                        WebhookSignature::HEADER => WebhookSignature::sign($endpoint->secret, $body, now()->getTimestamp()),
                    ])
                    ->withBody($body, 'application/json')
                    ->post(rtrim((string) $step->application->integration_endpoint, '/').'/tenants');

                if ($response->status() === 202) {
                    $step->forceFill(['status' => 'running', 'last_error' => null])->save();

                    return;
                }

                if ($response->successful()) {
                    Provisioner::finish($step, true);

                    return;
                }

                $this->failedAttempt($step, 'HTTP '.$response->status());
            } catch (Throwable $e) {
                $this->failedAttempt($step, mb_substr($e->getMessage(), 0, 500));
            }
        });
    }

    private function failedAttempt(ProvisioningStep $step, string $error): void
    {
        $delay = SendWebhook::BACKOFF[$step->attempts - 1] ?? null;

        if ($delay === null) {
            Provisioner::finish($step, false, $error);

            return;
        }

        $step->forceFill(['last_error' => $error])->save();
        self::dispatch($this->organizationId, $step->id)->delay($delay);
    }
}
