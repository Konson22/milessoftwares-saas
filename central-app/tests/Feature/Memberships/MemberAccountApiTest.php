<?php

use App\Models\User;
use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Tests\Support\IntegrationFixtures;

/*
| A product backend adds a member with a username and the temporary password
| its administrator chose (Petrol: a pump attendant without an email). Same
| rules as workspace → Add directly; the person chooses their own password at
| first sign-in.
*/

uses(IntegrationFixtures::class);

beforeEach(function () {
    $this->prepareIntegrationFixtures();
    config(['platform.member_account_applications' => ['retail']]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function addAccount(array $overrides = [], string $scope = 'members.write')
{
    return test()->withToken(test()->serviceToken($scope))->postJson(test()->apiUrl('member-accounts'), [
        'organization_id' => test()->organization->id,
        'actor_user_id' => test()->owner->user_id,
        'name' => 'Amina Lual',
        'username' => 'Amina.Lual',
        'password' => 'pump-start-42',
        ...$overrides,
    ]);
}

it('adds a username account with the chosen temporary password and a role at a branch', function () {
    addAccount(['role' => 'cashier', 'branch_id' => $this->juba->id])
        ->assertCreated()
        ->assertJsonPath('username', 'amina.lual')
        ->assertJsonPath('role_note', null);

    $user = User::query()->where('username', 'amina.lual')->sole();
    expect($user->email)->toBeNull()
        ->and(Hash::check('pump-start-42', $user->password))->toBeTrue()
        ->and($user->mustChangePassword())->toBeTrue()
        ->and($user->created_by_organization_id)->toBe($this->organization->id);

    $assignment = app(TenantContext::class)->run($this->organization, fn () => RoleAssignment::query()
        ->whereHas('membership', fn ($query) => $query->where('user_id', $user->id))
        ->sole());
    expect($assignment->branch_id)->toBe($this->juba->id);
});

it('refuses a taken username, a short password, and an unknown role', function () {
    addAccount()->assertCreated();

    addAccount(['username' => 'AMINA.LUAL'])->assertUnprocessable()->assertJsonValidationErrors('username');
    addAccount(['username' => 'other', 'password' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
    addAccount(['username' => 'other', 'role' => 'no_such_role'])->assertUnprocessable()->assertJsonValidationErrors('role');

    expect(User::query()->where('username', 'other')->exists())->toBeFalse();
});

it('acts only for a member who may manage access', function () {
    $member = $this->member();

    addAccount(['actor_user_id' => $member->user_id])->assertUnprocessable()->assertJsonValidationErrors('actor_user_id');
    addAccount(['actor_user_id' => User::factory()->create()->id])->assertForbidden();

    expect(User::query()->where('username', 'amina.lual')->exists())->toBeFalse();
});

it('is open only to the listed applications, with the scope, for an entitled organization', function () {
    config(['platform.member_account_applications' => ['petrol']]);
    addAccount()->assertForbidden();

    config(['platform.member_account_applications' => ['retail']]);
    addAccount([], 'access.read')->assertForbidden();
    addAccount(['organization_id' => '01a1d679-0c01-70a9-ab69-0bf34af43f99'])->assertNotFound();

    expect(OrganizationMembership::acrossTenants()->whereHas('user', fn ($query) => $query->where('username', 'amina.lual'))->exists())->toBeFalse();
});
