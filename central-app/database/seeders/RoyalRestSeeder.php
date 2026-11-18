<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Authorization\Actions\AssignmentDenied;
use App\Modules\Authorization\Actions\AssignRole;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Branch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Royal Rest, the local restaurant workspace (slug royal-rest), made real:
 * three branches in Juba and a team for each with the restaurant's roles,
 * every account signing in with "password". Writes who and what it made to
 * DEMO_BUSINESS_FILE for restaurant-app's RoyalRestSeeder, which enters a
 * month of trading. Safe to run again: what exists is kept.
 *
 *   DEMO_BUSINESS_FILE=/tmp/royal-rest.json php artisan db:seed --class=RoyalRestSeeder
 */
class RoyalRestSeeder extends Seeder
{
    public const SLUG = 'royal-rest';

    public const PASSWORD = 'password';

    private const DOMAIN = 'royalrest.test';

    /** Code => [name, address]. MAIN is the branch the workspace started with. */
    private const BRANCHES = [
        'MAIN' => ['Main branch', 'Hai Malakal, opposite Juba Teaching Hospital'],
        'GUD' => ['Gudele', 'Gudele Block 9, by the roundabout'],
        'MUN' => ['Munuki', 'Munuki Block C, Customs market road'],
    ];

    /** Team key => [name, mailbox, role, branch code or null for every branch]. */
    private const TEAM = [
        'global_admin' => ['Achol Mayen', 'achol', 'global_admin', null],
        'store_keeper' => ['Lado Kenyi', 'lado', 'store_keeper', null],

        'main_manager' => ['Rebecca Akuol', 'rebecca', 'manager', 'MAIN'],
        'main_cashier' => ['Viola Keji', 'viola', 'cashier', 'MAIN'],
        'main_waiter_1' => ['David Jada', 'david', 'waiter', 'MAIN'],
        'main_waiter_2' => ['Mary Poni', 'mary', 'waiter', 'MAIN'],
        'main_kitchen' => ['Teresa Amer', 'teresa', 'kitchen', 'MAIN'],

        'gud_manager' => ['James Lual', 'james', 'manager', 'GUD'],
        'gud_cashier' => ['Grace Ajak', 'grace', 'cashier', 'GUD'],
        'gud_waiter_1' => ['Peter Wani', 'peter', 'waiter', 'GUD'],
        'gud_waiter_2' => ['Nyandeng Bol', 'nyandeng', 'waiter', 'GUD'],
        'gud_kitchen' => ['Santino Deng', 'santino', 'kitchen', 'GUD'],

        'mun_manager' => ['Agnes Yangi', 'agnes', 'manager', 'MUN'],
        'mun_cashier' => ['Simon Tombe', 'simon', 'cashier', 'MUN'],
        'mun_waiter_1' => ['Helen Sadia', 'helen', 'waiter', 'MUN'],
        'mun_waiter_2' => ['Joseph Malish', 'joseph', 'waiter', 'MUN'],
        'mun_kitchen' => ['Martha Achieng', 'martha', 'kitchen', 'MUN'],
    ];

    public function run(AssignRole $roles, TenantContext $tenant): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('RoyalRestSeeder only runs with APP_ENV=local.');
        }

        $file = (string) getenv('DEMO_BUSINESS_FILE');
        if ($file === '') {
            throw new RuntimeException('Set DEMO_BUSINESS_FILE.');
        }

        $organizationId = DB::table('organization_slugs')->where('slug', self::SLUG)->value('organization_id')
            ?? throw new RuntimeException('No workspace royal-rest: sign up Royal Rest first.');
        $organization = Organization::query()->findOrFail($organizationId);
        $restaurant = Application::query()->where('code', 'restaurant')->sole();

        $branches = [];
        $users = [];
        $memberships = [];

        $tenant->run($organization, function () use ($organization, $restaurant, $roles, &$branches, &$users, &$memberships) {
            $owner = OrganizationMembership::query()->findOrFail($organization->owner_membership_id);
            $memberships['owner'] = $owner->getKey();
            $users['owner'] = User::query()->findOrFail($owner->user_id);

            foreach (self::BRANCHES as $code => [$name, $address]) {
                $branch = Branch::query()->where('code', $code)->first() ?? new Branch;
                $branch->forceFill(['name' => $name, 'code' => $code, 'address' => $address, 'city' => 'Juba', 'country_code' => 'SS', 'status' => 'active'])->save();
                $branches[$code] = $branch->getKey();
            }

            foreach (self::TEAM as $key => [$name, $mailbox, $roleKey, $branchCode]) {
                $user = $this->user($name, $mailbox.'@'.self::DOMAIN);
                $users[$key] = $user;

                $member = OrganizationMembership::query()->where('user_id', $user->getKey())->first() ?? new OrganizationMembership;
                if (! $member->exists) {
                    $member->forceFill(['user_id' => $user->getKey(), 'status' => MembershipStatus::Active, 'joined_at' => now()->subMonths(2)])->save();
                }
                $memberships[$key] = $member->getKey();

                $role = ApplicationRole::query()->where('catalog_version_id', $restaurant->current_catalog_version_id)->where('key', $roleKey)->sole();
                try {
                    $roles->handle($owner, $member, $role, $branchCode === null ? Scope::organization() : Scope::branch($branches[$branchCode]), acknowledgeSeparationOfDuties: true);
                } catch (AssignmentDenied $e) {
                    if ($e->reason !== 'duplicate') {
                        throw $e;
                    }
                }
            }
        });

        file_put_contents($file, json_encode([
            'organization' => [
                'id' => $organization->id,
                'slug' => self::SLUG,
                'display_name' => $organization->display_name,
                'status' => 'active',
                'country_code' => $organization->country_code,
                'default_currency' => $organization->default_currency,
                'timezone' => $organization->timezone,
            ],
            'branches' => $branches,
            'memberships' => $memberships,
            'users' => array_map(fn (User $user) => ['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email], $users),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->command?->info('Royal Rest: '.count($branches).' branches, '.count(self::TEAM).' staff (password "'.self::PASSWORD.'", @'.self::DOMAIN.').');
    }

    private function user(string $name, string $email): User
    {
        $user = User::query()->where('email', $email)->first() ?? new User;

        if (! $user->exists) {
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
            ])->save();
        }

        return $user;
    }
}
