<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Administration\Actions\StaffDirectory;
use App\Modules\Administration\Models\PlatformStaff;
use App\Modules\Administration\Support\PlatformRoleCatalog;
use App\Support\Hosts\PlatformHosts;

trait AdminFixtures
{
    /**
     * Active staff with confirmed MFA and the given platform roles.
     *
     * @param  list<string>  $roles
     */
    protected function staffWith(array $roles, string $status = 'active'): PlatformStaff
    {
        PlatformRoleCatalog::sync();

        $directory = app(StaffDirectory::class);
        $staff = $directory->add(User::factory()->withTwoFactor()->create(), actor: null);

        foreach ($roles as $role) {
            $directory->grantRole($staff, $role, actor: null);
        }

        $staff->forceFill(['status' => $status])->save();

        return $staff->refresh();
    }

    protected function adminUrl(string $path = '/'): string
    {
        return PlatformHosts::url(PlatformHosts::admin(), $path);
    }
}
