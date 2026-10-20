<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Administration\Actions\StaffDirectory;
use App\Modules\Administration\Support\PlatformRoleCatalog;
use App\Modules\Organizations\Actions\CreateOrganization;
use App\Modules\Organizations\Models\OrganizationSlug;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\RecoveryCode;
use Laravel\Fortify\TwoFactorAuthenticationProvider;
use RuntimeException;

/**
 * Accounts and organizations for trying the platform by hand on a developer
 * machine. Goes through the same actions as the UI, so memberships, roles
 * and audit records look exactly like real ones. Safe to run again.
 *
 *   php artisan db:seed --class=LocalDemoSeeder
 */
class LocalDemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(CreateOrganization $organizations, StaffDirectory $staff, TwoFactorAuthenticationProvider $totp): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('LocalDemoSeeder only runs with APP_ENV=local.');
        }

        Artisan::call('platform:catalog-import');
        PlatformRoleCatalog::sync();

        // Admin requires confirmed two-factor authentication.
        $admin = $this->user('Platform Admin', 'admin@milesoftwares.local');
        if ($admin->two_factor_confirmed_at === null) {
            $admin->forceFill([
                'two_factor_secret' => encrypt($totp->generateSecretKey()),
                'two_factor_recovery_codes' => encrypt((string) json_encode(Collection::times(8, fn () => RecoveryCode::generate())->all())),
                'two_factor_confirmed_at' => now(),
            ])->save();
        }
        $record = $staff->add($admin, actor: null);
        $staff->grantRole($record, 'super_admin', actor: null);

        foreach ([
            ['Ada Owner', 'owner@acme.test', 'Acme Trading', 'acme'],
            ['Grace Owner', 'owner@globex.test', 'Globex Supplies', 'globex'],
        ] as [$name, $email, $organization, $slug]) {
            $owner = $this->user($name, $email);

            if (OrganizationSlug::query()->where('slug', $slug)->exists()) {
                continue;
            }

            $organizations->handle($owner, [
                'display_name' => $organization,
                'legal_name' => "{$organization} Ltd",
                'slug' => $slug,
                'contact_email' => $email,
                'country_code' => 'SS',
                'default_currency' => 'USD',
                'timezone' => 'Africa/Juba',
            ]);
        }

        $this->user('Sam Member', 'member@example.test');

        $secret = decrypt((string) $admin->fresh()?->two_factor_secret);
        $this->command->table(['Account', 'Password', 'Notes'], [
            ['admin@milesoftwares.local', self::PASSWORD, "super_admin; authenticator secret {$secret}"],
            ['owner@acme.test', self::PASSWORD, 'owns Acme Trading (acme)'],
            ['owner@globex.test', self::PASSWORD, 'owns Globex Supplies (globex)'],
            ['member@example.test', self::PASSWORD, 'no organization; invite them'],
        ]);
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
