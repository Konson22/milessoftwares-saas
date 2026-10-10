<?php

namespace App\Modules\Applications\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The workspace shell ({slug}.<root>) as a platform-internal application that
 * owns the shared workspace OIDC client. Its per-tenant callbacks are written
 * by TenantRedirectUris.
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
                'tenant_redirect_path' => '/auth/callback',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('application_clients')->where('id', $applicationClientId)->update([
                'oauth_client_ref' => $oauthClientId,
                'status' => 'active',
                'tenant_redirect_path' => '/auth/callback',
                'updated_at' => now(),
            ]);
        }

        return (string) $applicationClientId;
    }
}
