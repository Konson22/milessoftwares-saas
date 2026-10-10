<?php

namespace App\Modules\Administration\Console;

use App\Models\User;
use App\Modules\Administration\Actions\StaffDirectory;
use App\Modules\Administration\Support\PlatformRoleCatalog;
use Illuminate\Console\Command;

/**
 * Bootstraps staff from the server console, e.g. the first super admin:
 *
 *   php artisan platform:staff ops@milessoftwares.com --role=super_admin
 */
class AddPlatformStaff extends Command
{
    protected $signature = 'platform:staff {email : An existing user account} {--role=* : Platform roles to grant}';

    protected $description = 'Make an existing user platform staff and grant platform roles';

    public function handle(StaffDirectory $staff): int
    {
        PlatformRoleCatalog::sync();

        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No user with that email. They must register on the auth host first.');

            return self::FAILURE;
        }

        /** @var list<string> $roles */
        $roles = $this->option('role');
        $unknown = array_diff($roles, array_keys(PlatformRoleCatalog::roles()));

        if ($unknown !== []) {
            $this->components->error('Unknown roles: '.implode(', ', $unknown).'. Known: '.implode(', ', array_keys(PlatformRoleCatalog::roles())));

            return self::FAILURE;
        }

        $record = $staff->add($user, actor: null);

        foreach ($roles as $role) {
            $staff->grantRole($record, $role, actor: null);
        }

        $this->components->info("{$user->email} is platform staff with: ".($record->roles()->pluck('key')->implode(', ') ?: 'no roles'));

        if ($user->two_factor_confirmed_at === null) {
            $this->components->warn('They have no two-factor authentication yet and cannot open admin until they set it up.');
        }

        return self::SUCCESS;
    }
}
