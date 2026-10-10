<?php

namespace App\Modules\Applications\Support;

use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Organizations\Models\Organization;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Exact per-tenant redirect URIs for clients served on tenant hosts. For one
 * organization, every active client with a tenant_redirect_path gets one row
 * for the primary slug, if the organization may use the application:
 * platform applications always, products only while entitled. Anything else
 * for that organization is removed, so a lost entitlement or an old slug
 * stops working as a sign-in callback.
 */
final class TenantRedirectUris
{
    public static function sync(Organization $organization): void
    {
        $organization->unsetRelation('primarySlug');
        $slug = $organization->primarySlug?->slug;

        $entitled = DB::table('entitlements')
            ->where('organization_id', $organization->getKey())
            ->whereNull('feature_key')
            ->whereNull('revoked_at')
            ->where('valid_from', '<=', now())
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->pluck('application_id');

        $clients = DB::table('application_clients')
            ->join('applications', 'applications.id', '=', 'application_clients.application_id')
            ->whereNotNull('application_clients.tenant_redirect_path')
            ->where('application_clients.status', 'active')
            ->where(fn ($query) => $query
                ->where('applications.kind', ApplicationKind::Platform->value)
                ->orWhereIn('applications.id', $entitled))
            ->get(['application_clients.id', 'application_clients.tenant_redirect_path']);

        DB::transaction(function () use ($organization, $slug, $clients): void {
            DB::table('application_client_redirect_uris')->where('organization_id', $organization->getKey())->delete();

            if ($slug === null) {
                return;
            }

            foreach ($clients as $client) {
                DB::table('application_client_redirect_uris')->insert([
                    'id' => (string) Str::uuid7(),
                    'application_client_id' => $client->id,
                    'organization_id' => $organization->getKey(),
                    'uri' => PlatformHosts::url(PlatformHosts::workspace($slug), (string) $client->tenant_redirect_path),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
