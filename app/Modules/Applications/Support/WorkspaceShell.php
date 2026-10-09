<?php

namespace App\Modules\Applications\Support;

use App\Modules\Organizations\Models\Organization;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The workspace shell ({slug}.<root>) as a platform-internal application. It
 * owns the shared workspace OIDC client, and each organization gets exactly
 * one redirect URI row for its primary slug.
 */
final class WorkspaceShell
{
    public const CODE = 'workspace';

    /**
     * Registers the application and links it to the workspace OAuth client.
     * Returns the application_clients id.
     */
    public static function register(string $oauthClientId): string
    {
        $applicationId = DB::table('applications')->where('code', self::CODE)->value('id');

        if ($applicationId === null) {
            $applicationId = (string) Str::uuid7();
            DB::table('applications')->insert([
                'id' => $applicationId,
                'code' => self::CODE,
                'name' => 'Business workspace',
                'category' => 'platform',
                'kind' => 'platform',
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $applicationClientId = DB::table('application_clients')->where('client_identifier', 'workspace-web')->value('id');

        if ($applicationClientId === null) {
            $applicationClientId = (string) Str::uuid7();
            DB::table('application_clients')->insert([
                'id' => $applicationClientId,
                'application_id' => $applicationId,
                'name' => 'Workspace web',
                'client_identifier' => 'workspace-web',
                'client_type' => 'confidential',
                'oauth_client_ref' => $oauthClientId,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('application_clients')->where('id', $applicationClientId)->update([
                'oauth_client_ref' => $oauthClientId,
                'status' => 'active',
                'updated_at' => now(),
            ]);
        }

        return (string) $applicationClientId;
    }

    /**
     * Points the organization's workspace callback at its current primary slug.
     * A retired slug's host only redirects, so its URI is removed.
     */
    public static function syncRedirectUri(Organization $organization): void
    {
        $applicationClientId = DB::table('application_clients')->where('client_identifier', 'workspace-web')->value('id');

        if ($applicationClientId === null) {
            Log::warning('Workspace shell is not registered; run `php artisan platform:oidc-clients`.', [
                'organization_id' => $organization->getKey(),
            ]);

            return;
        }

        $organization->unsetRelation('primarySlug');
        $slug = $organization->primarySlug?->slug;

        DB::transaction(function () use ($applicationClientId, $organization, $slug): void {
            DB::table('application_client_redirect_uris')
                ->where('application_client_id', $applicationClientId)
                ->where('organization_id', $organization->getKey())
                ->delete();

            if ($slug === null) {
                return;
            }

            DB::table('application_client_redirect_uris')->insert([
                'id' => (string) Str::uuid7(),
                'application_client_id' => $applicationClientId,
                'organization_id' => $organization->getKey(),
                'uri' => PlatformHosts::url(PlatformHosts::workspace($slug), 'auth/callback'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
