<?php

namespace Miles\PlatformClient\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\Provisioning\ProvisionsTenants;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use Miles\PlatformClient\Webhooks\Signature;
use Throwable;

/**
 * POST platform/tenants: the platform asks the product to prepare an
 * organization that became entitled (docs/integration.md section 4).
 *
 * The Idempotency-Key is the tenant-creation key: a key that was already
 * handled answers 200 and never runs the provisioner again. A failure
 * answers 500 and the platform retries on its schedule.
 */
final class ProvisioningController
{
    public function __construct(
        private readonly PlatformConfig $config,
        private readonly ProvisionsTenants $provisioner,
        private readonly CurrentTenant $tenant,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $body = $request->getContent();

        if (! Signature::valid($request->header(Signature::HEADER), $body, $this->config->webhookSecret(), now()->getTimestamp())) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $key = (string) $request->header('Idempotency-Key', '');
        if ($key === '' || strlen($key) > 100) {
            return response()->json(['error' => 'idempotency_key_required'], 400);
        }

        $payload = json_decode($body, true);
        $data = is_array($payload) && is_array($payload['organization'] ?? null) ? $payload['organization'] : null;

        if ($data === null || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id']) || ! is_string($data['slug'] ?? null)) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        if (is_string($payload['application'] ?? null) && $payload['application'] !== $this->config->application()) {
            return response()->json(['error' => 'wrong_application'], 422);
        }

        $organization = Organization::fromArray($data + ['status' => 'active']);

        if ($this->alreadyProvisioned($key)) {
            return response()->json(['status' => 'already_provisioned', 'organization_id' => $organization->id]);
        }

        try {
            DB::transaction(function () use ($key, $organization): void {
                $this->tenant->run($organization, fn () => $this->provisioner->provision($organization));

                DB::table('platform_provisioned_tenants')->insert([
                    'idempotency_key' => $key,
                    'organization_id' => $organization->id,
                    'slug' => $organization->slug,
                    'provisioned_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            // A concurrent delivery of the same key won the race.
            if ($this->alreadyProvisioned($key)) {
                return response()->json(['status' => 'already_provisioned', 'organization_id' => $organization->id]);
            }

            Log::error('Tenant provisioning failed', ['organization' => $organization->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'provisioning_failed'], 500);
        }

        return response()->json(['status' => 'provisioned', 'organization_id' => $organization->id], 201);
    }

    /**
     * Reads the table each time: a concurrent delivery may have committed
     * in between.
     *
     * @phpstan-impure
     */
    private function alreadyProvisioned(string $key): bool
    {
        return DB::table('platform_provisioned_tenants')->where('idempotency_key', $key)->exists();
    }
}
