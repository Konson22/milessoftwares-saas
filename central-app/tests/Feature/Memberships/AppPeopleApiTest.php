<?php

use App\Modules\Authorization\Models\RoleAssignment;
use App\Modules\Memberships\Models\Invitation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Tests\Support\IntegrationFixtures;

/*
| An application looking after its own people (platform.application_people_managers):
| a member holding the application's people permission invites, adds and gives
| or takes back that application's delegable roles from the application's own
| screens, without workspace access administration. Here retail's
| retail.payments.approve stands in for Logbook's logbook.people.manage.
*/

uses(IntegrationFixtures::class);

beforeEach(function () {
    Notification::fake();
    $this->prepareIntegrationFixtures();
    config(['platform.application_people_managers' => ['retail' => 'retail.payments.approve']]);

    app(TenantContext::class)->set($this->organization);
    $this->manager = $this->member();
    $this->grant($this->manager, 'retail', 'payments_approver');
    $this->person = $this->member();
    app(TenantContext::class)->clear();
});

/**
 * @param  array<string, mixed>  $body
 */
function people(string $path, array $body = [], string $scope = 'members.write')
{
    return test()->withToken(test()->serviceToken($scope))->postJson(test()->apiUrl('people/'.$path), [
        'organization_id' => test()->organization->id,
        'actor_user_id' => test()->manager->user_id,
        ...$body,
    ]);
}

function liveRoles(string $membershipId): array
{
    return app(TenantContext::class)->run(test()->organization, fn () => RoleAssignment::query()->live()
        ->where('membership_id', $membershipId)->with('role')->get()->pluck('role.key')->sort()->values()->all());
}

it('lets the application\'s people manager give and take back its delegable roles', function () {
    people('roles', ['membership_id' => $this->person->id, 'role' => 'payments_clerk'])->assertCreated();
    expect(liveRoles($this->person->id))->toBe(['payments_clerk']);

    people('roles/revoke', ['membership_id' => $this->person->id, 'role' => 'payments_clerk'])->assertOk();
    expect(liveRoles($this->person->id))->toBe([]);
});

it('keeps owner-only and more sensitive roles away from the people manager', function () {
    people('roles', ['membership_id' => $this->person->id, 'role' => 'retail_owner'])
        ->assertUnprocessable()->assertJsonValidationErrors('role');

    expect(liveRoles($this->person->id))->toBe([]);
});

it('refuses members who do not manage the application\'s people', function () {
    app(TenantContext::class)->set($this->organization);
    $clerk = $this->member();
    $this->grant($clerk, 'retail', 'payments_clerk');
    app(TenantContext::class)->clear();

    people('roles', ['actor_user_id' => $clerk->user_id, 'membership_id' => $this->person->id, 'role' => 'payments_clerk'])
        ->assertUnprocessable()->assertJsonValidationErrors('role');
    people('invitations', ['actor_user_id' => $clerk->user_id, 'email' => 'new@example.test', 'role' => 'payments_clerk'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('invites for the application\'s roles, lists and cancels the invitation', function () {
    people('invitations', ['email' => 'Akol@Example.test', 'role' => 'payments_clerk'])
        ->assertCreated()->assertJsonPath('email', 'akol@example.test');

    $list = $this->withToken($this->serviceToken('access.read'))
        ->getJson($this->apiUrl('people/invitations?organization_id='.$this->organization->id))
        ->assertOk()->assertJsonPath('data.0.roles', ['Payments clerk']);

    people('invitations/'.$list->json('data.0.id').'/cancel')->assertOk();
    expect(Invitation::acrossTenants()->sole()->status)->toBe('revoked');
});

it('lists the workspace\'s active members', function () {
    $this->withToken($this->serviceToken('access.read'))
        ->getJson($this->apiUrl('people/members?organization_id='.$this->organization->id))
        ->assertOk()->assertJsonCount(3, 'data');
});

it('is open only to applications whose managers look after their people', function () {
    config(['platform.application_people_managers' => []]);
    people('roles', ['membership_id' => $this->person->id, 'role' => 'payments_clerk'])->assertForbidden();

    config(['platform.application_people_managers' => ['retail' => 'retail.payments.approve']]);
    people('roles', ['membership_id' => $this->person->id, 'role' => 'payments_clerk'], 'access.read')->assertForbidden();
});
