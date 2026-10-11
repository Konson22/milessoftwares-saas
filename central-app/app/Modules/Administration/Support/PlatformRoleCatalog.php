<?php

namespace App\Modules\Administration\Support;

use App\Modules\Administration\Enums\PlatformPermission as P;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform roles are defined in code and synced to platform_roles, so a role's
 * permissions change only through a reviewed deploy.
 */
final class PlatformRoleCatalog
{
    /**
     * @return array<string, array{name: string, description: string, permissions: list<P>}>
     */
    public static function roles(): array
    {
        return [
            'super_admin' => [
                'name' => 'Super admin',
                'description' => 'Everything, including managing staff. Keep this to very few people.',
                'permissions' => P::cases(),
            ],
            'support_agent' => [
                'name' => 'Support agent',
                'description' => 'Looks up organizations and requests time-boxed support access.',
                'permissions' => [P::OrganizationsView, P::SupportRequest],
            ],
            'support_manager' => [
                'name' => 'Support manager',
                'description' => "Approves other staff members' support access and can suspend organizations.",
                'permissions' => [P::OrganizationsView, P::SupportRequest, P::SupportApprove, P::OrganizationsSuspend],
            ],
            'billing_admin' => [
                'name' => 'Billing admin',
                'description' => 'Manages plans, subscriptions and entitlements.',
                'permissions' => [P::OrganizationsView, P::PlansManage, P::EntitlementsManage],
            ],
            'catalog_admin' => [
                'name' => 'Catalog admin',
                'description' => 'Manages the application registry and role catalogs.',
                'permissions' => [P::RegistryManage],
            ],
            'sales' => [
                'name' => 'Sales',
                'description' => 'Handles demo requests and contact messages from the public site.',
                'permissions' => [P::LeadsManage],
            ],
            'security_auditor' => [
                'name' => 'Security auditor',
                'description' => 'Reads sign-in events and the audit log, and verifies the audit chain.',
                'permissions' => [P::OrganizationsView, P::SecurityView],
            ],
        ];
    }

    /**
     * @return list<P>
     */
    public static function permissionsFor(string $roleKey): array
    {
        return self::roles()[$roleKey]['permissions'] ?? [];
    }

    /**
     * Inserts missing roles and refreshes names. Roles removed from code are
     * kept (staff may still hold them) but grant nothing.
     */
    public static function sync(): void
    {
        foreach (self::roles() as $key => $role) {
            $exists = DB::table('platform_roles')->where('key', $key)->exists();

            $exists
                ? DB::table('platform_roles')->where('key', $key)->update([
                    'name' => $role['name'],
                    'description' => $role['description'],
                    'updated_at' => now(),
                ])
                : DB::table('platform_roles')->insert([
                    'id' => (string) Str::uuid7(),
                    'key' => $key,
                    'name' => $role['name'],
                    'description' => $role['description'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }
}
