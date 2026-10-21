<?php

use App\Models\User;
use App\Modules\Administration\Models\PlatformStaff;

function adminUrl(): string
{
    return 'http://admin.'.config('platform.root_domain').'/';
}

function makeStaff(User $user, string $status = 'active'): PlatformStaff
{
    $staff = new PlatformStaff;
    $staff->forceFill(['user_id' => $user->id, 'status' => $status])->save();

    return $staff;
}

it('sends guests to the admin host sign-in page', function () {
    $this->get(adminUrl())->assertRedirect('http://admin.'.config('platform.root_domain').'/login');
});

it('forbids ordinary customers', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create())->get(adminUrl())->assertForbidden();
});

it('forbids staff without confirmed MFA', function () {
    $user = User::factory()->create();
    makeStaff($user);

    $this->actingAs($user)->get(adminUrl())->assertForbidden();
});

it('forbids suspended staff', function () {
    $user = User::factory()->withTwoFactor()->create();
    makeStaff($user, 'suspended');

    $this->actingAs($user)->get(adminUrl())->assertForbidden();
});

it('admits active staff with MFA', function () {
    $user = User::factory()->withTwoFactor()->create();
    makeStaff($user);

    $this->actingAs($user)->get(adminUrl())->assertOk()->assertInertia(fn ($page) => $page->component('admin/overview'));
});
