<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Applications\Models\Application;
use App\Modules\Applications\Models\ApplicationFeature;
use App\Modules\Applications\Models\ApplicationRole;
use App\Modules\Authorization\Actions\AssignRole;
use App\Modules\Authorization\Support\Scope;
use App\Modules\Entitlements\Actions\ManageEntitlements;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Actions\CreateOrganization;
use App\Modules\Structure\Models\Branch;
use App\Modules\Structure\Models\Department;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The demo business on a developer machine, SSUWC: its owner, a member for
 * each role of each product, its head office and departments, and every
 * product registered with this platform. Writes who and what it made to
 * DEMO_BUSINESS_FILE, which each product's own DemoBusinessSeeder reads.
 *
 * Provisioning calls the products, so they must be answering, and the queue
 * must run inline: QUEUE_CONNECTION=sync MAIL_MAILER=log.
 */
class DemoBusinessSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public const SLUG = 'ssuwc';

    public const DOMAIN = 'ssuwc.gov.ss';

    /** Department code => name. */
    private const DEPARTMENTS = [
        'ADM' => 'Administration',
        'FIN' => 'Finance',
        'HRM' => 'Human Resources',
        'OPS' => 'Water Operations',
        'TRT' => 'Treatment and Quality',
        'NET' => 'Network and Projects',
        'COM' => 'Commercial and Billing',
        'FLT' => 'Fleet and Tankers',
        'TRN' => 'Training Institute',
    ];

    /** Team key => [name, mailbox, [application => role]]. */
    private const TEAM = [
        'finance_manager' => ['Mary Lado', 'finance.manager', ['finance' => 'finance_manager', 'projects' => 'controller']],
        'finance_officer' => ['Oliver Wani', 'finance.officer', ['finance' => 'finance_officer']],
        'cashier' => ['Chol Deng', 'cashier', ['finance' => 'cashier']],
        'chief_accountant' => ['Abuk Garang', 'chief.accountant', ['finance' => 'chief_accountant']],
        'accountant' => ['Peter Modi', 'accountant', ['finance' => 'accountant', 'logistics' => 'accountant']],
        'auditor' => ['Nyandeng Mabior', 'auditor', ['finance' => 'auditor', 'projects' => 'viewer']],
        'system_administrator' => ['Deng Ajak', 'system.admin', ['finance' => 'system_administrator']],
        'hr_manager' => ['Rebecca Kiden', 'hr.manager', ['hr' => 'hr_manager']],
        'hr_officer' => ['James Lokuda', 'hr.officer', ['hr' => 'hr_officer']],
        'line_manager' => ['Simon Tombe', 'line.manager', ['hr' => 'line_manager']],
        'logistics_manager' => ['Gabriel Ladu', 'logistics.manager', ['logistics' => 'manager']],
        'dispatcher' => ['Grace Poni', 'dispatcher', ['logistics' => 'dispatcher']],
        'driver' => ['Santino Lual', 'driver', ['logistics' => 'driver']],
        'fleet_officer' => ['Michael Taban', 'fleet', ['logistics' => 'fleet']],
        'pharmacy_manager' => ['Agnes Yangi', 'pharmacy.manager', ['pharmacy' => 'manager']],
        'pharmacist' => ['Martha Achol', 'pharmacist', ['pharmacy' => 'pharmacist']],
        'storekeeper' => ['Paul Lemi', 'storekeeper', ['pharmacy' => 'storekeeper', 'retail' => 'storekeeper']],
        'shop_cashier' => ['Viola Keji', 'shop.cashier', ['pharmacy' => 'cashier', 'retail' => 'cashier', 'restaurant' => 'cashier']],
        'retail_manager' => ['Thomas Duku', 'retail.manager', ['retail' => 'manager']],
        'projects_manager' => ['Anna Nyibol', 'projects.manager', ['projects' => 'manager']],
        'project_member' => ['Samuel Malish', 'project.member', ['projects' => 'member']],
        'restaurant_manager' => ['Josephine Ayaa', 'restaurant.manager', ['restaurant' => 'manager']],
        'waiter' => ['David Jada', 'waiter', ['restaurant' => 'waiter']],
        'kitchen' => ['Teresa Amer', 'kitchen', ['restaurant' => 'kitchen']],
        'school_head' => ['Isaac Bol', 'school.head', ['school' => 'head']],
        'registrar' => ['Helen Sadia', 'registrar', ['school' => 'registrar']],
        'teacher' => ['John Mayen', 'teacher', ['school' => 'teacher']],
        'bursar' => ['Lucy Amuna', 'bursar', ['school' => 'bursar']],
    ];

    public function run(CreateOrganization $organizations, ManageEntitlements $entitlements, AssignRole $roles, TenantContext $tenant): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoBusinessSeeder only runs with APP_ENV=local.');
        }

        $file = (string) getenv('DEMO_BUSINESS_FILE');
        if ($file === '') {
            throw new RuntimeException('Set DEMO_BUSINESS_FILE.');
        }

        $owner = $this->user('SSUWC Administrator', 'admin@'.self::DOMAIN);
        $organization = $organizations->handle($owner, [
            'display_name' => 'SSUWC',
            'legal_name' => 'South Sudan Urban Water Corporation',
            'slug' => self::SLUG,
            'contact_email' => $owner->email,
            'country_code' => 'SS',
            'default_currency' => 'USD',
            'timezone' => 'Africa/Juba',
        ]);

        $staff = PlatformStaff::query()->first();
        $products = Application::query()->whereIn('provisioning_method', ['sync_api', 'async_api'])->whereNotNull('integration_endpoint')->orderBy('code')->get();
        foreach ($products as $product) {
            $entitlements->grantManual($organization, $product, $staff, 'Local demo business');

            foreach (ApplicationFeature::query()->where('catalog_version_id', $product->current_catalog_version_id)->get() as $feature) {
                $entitlements->overrideFeature($organization, $feature, Entitlement::GRANT, null, $staff, 'Local demo business');
            }
        }

        $users = ['owner' => $owner];
        $memberships = [];
        $branchId = '';
        $departments = [];

        $tenant->run($organization, function () use ($organization, $products, $roles, &$users, &$memberships, &$branchId, &$departments) {
            $grantor = OrganizationMembership::query()->findOrFail($organization->fresh()->owner_membership_id);
            $memberships['owner'] = $grantor->getKey();

            $branch = Branch::query()->create(['name' => 'Juba Head Office', 'code' => 'JUB', 'address' => 'Ministries Road', 'city' => 'Juba', 'country_code' => 'SS', 'status' => 'active']);
            $branchId = $branch->getKey();
            foreach (self::DEPARTMENTS as $code => $name) {
                $departments[$code] = Department::query()->create(['branch_id' => $branchId, 'name' => $name, 'code' => $code, 'status' => 'active'])->getKey();
            }

            foreach (self::TEAM as $key => [$name, $mailbox, $assignments]) {
                $user = $this->user($name, $mailbox.'@'.self::DOMAIN);
                $users[$key] = $user;

                $member = new OrganizationMembership;
                $member->forceFill(['user_id' => $user->getKey(), 'status' => MembershipStatus::Active, 'joined_at' => now()])->save();
                $memberships[$key] = $member->getKey();

                foreach ($assignments as $code => $roleKey) {
                    $product = $products->firstWhere('code', $code) ?? throw new RuntimeException("{$code} is not registered.");
                    $role = ApplicationRole::query()->where('catalog_version_id', $product->current_catalog_version_id)->where('key', $roleKey)->sole();
                    $roles->handle($grantor, $member, $role, Scope::organization(), acknowledgeSeparationOfDuties: true);
                }
            }
        });

        file_put_contents($file, json_encode([
            'organization' => [
                'id' => $organization->id,
                'slug' => self::SLUG,
                'display_name' => $organization->display_name,
                'status' => 'active',
                'country_code' => 'SS',
                'default_currency' => 'USD',
                'timezone' => 'Africa/Juba',
            ],
            'branch_id' => $branchId,
            'departments' => $departments,
            'memberships' => $memberships,
            'users' => array_map(fn (User $user) => ['id' => $user->getKey(), 'name' => $user->name, 'email' => $user->email], $users),
            'products' => $products->pluck('code')->all(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
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
