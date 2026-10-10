<?php

namespace App\Modules\Integration\Support;

use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Integration\Jobs\SendWebhook;
use App\Modules\Integration\Models\WebhookDelivery;
use App\Modules\Integration\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tells products that something they cache has changed: one webhook
 * delivery per affected product, and a new effective-access cache version
 * for the organization so the API never serves stale access afterwards.
 *
 * Deliveries are written in the caller's transaction and sent after commit.
 */
final class IntegrationEvents
{
    public function entitlementChanged(string $organizationId, string $applicationId): void
    {
        $this->emit('entitlement.changed', $organizationId, [$applicationId], [
            'application' => $this->applicationCode($applicationId),
        ]);
    }

    public function accessChanged(string $organizationId, string $applicationId, string $membershipId, string $userId): void
    {
        $this->emit('access.changed', $organizationId, [$applicationId], [
            'application' => $this->applicationCode($applicationId),
            'membership_id' => $membershipId,
            'sub' => $userId,
        ]);
    }

    /**
     * membership.revoked for suspended or removed members, membership.reactivated
     * when they are active again.
     */
    public function membershipStatusChanged(string $organizationId, string $membershipId, string $userId, string $status): void
    {
        $this->emit($status === 'active' ? 'membership.reactivated' : 'membership.revoked', $organizationId, $this->entitledApplications($organizationId), [
            'membership_id' => $membershipId,
            'sub' => $userId,
            'status' => $status,
        ]);
    }

    public function organizationStatusChanged(string $organizationId, string $status): void
    {
        $this->emit($status === 'suspended' ? 'organization.suspended' : 'organization.reactivated', $organizationId, $this->entitledApplications($organizationId), [
            'status' => $status,
        ]);
    }

    /**
     * Part of every effective-access cache key; bumping it invalidates the
     * organization's cached answers at once.
     */
    public static function cacheVersion(string $organizationId): int
    {
        return (int) Cache::get("effective-access:version:{$organizationId}", 0);
    }

    /**
     * @param  list<string>  $applicationIds
     * @param  array<string, mixed>  $data
     */
    private function emit(string $eventType, string $organizationId, array $applicationIds, array $data): void
    {
        Cache::forever("effective-access:version:{$organizationId}", self::cacheVersion($organizationId) + 1);

        if ($applicationIds === []) {
            return;
        }

        $endpoints = WebhookEndpoint::query()
            ->whereIn('application_id', $applicationIds)
            ->where('status', 'active')
            ->get(['id']);

        foreach ($endpoints as $endpoint) {
            $id = (string) Str::uuid7();

            $delivery = new WebhookDelivery;
            $delivery->forceFill([
                'id' => $id,
                'webhook_endpoint_id' => $endpoint->id,
                'organization_id' => $organizationId,
                'event_type' => $eventType,
                'payload' => [
                    'event_id' => $id,
                    'event_type' => $eventType,
                    'schema_version' => 1,
                    'occurred_at' => now()->toIso8601String(),
                    'organization_id' => $organizationId,
                    'data' => $data,
                ],
                'status' => 'pending',
                'next_attempt_at' => now(),
            ])->save();

            SendWebhook::dispatch($id)->afterCommit();
        }
    }

    /**
     * @return list<string>
     */
    private function entitledApplications(string $organizationId): array
    {
        return array_values(DB::table('entitlements')
            ->join('applications', 'applications.id', '=', 'entitlements.application_id')
            ->where('entitlements.organization_id', $organizationId)
            ->where('applications.kind', '!=', ApplicationKind::Platform->value)
            ->whereNull('entitlements.feature_key')
            ->whereNull('entitlements.revoked_at')
            ->where(fn ($query) => $query->whereNull('entitlements.valid_until')->orWhere('entitlements.valid_until', '>', now()))
            ->distinct()
            ->pluck('entitlements.application_id')
            ->map(fn ($id) => (string) $id)
            ->all());
    }

    private function applicationCode(string $applicationId): ?string
    {
        $code = DB::table('applications')->where('id', $applicationId)->value('code');

        return is_string($code) ? $code : null;
    }
}
