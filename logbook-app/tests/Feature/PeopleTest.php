<?php

/*
 * People from Logbook itself: the Manager invites, adds accounts and gives
 * or takes back Logbook roles. The platform decides (its own tests cover
 * the rules); Logbook sends the call as the signed-in member and shows the
 * answer.
 */

beforeEach(function () {
    $this->manager = actingAsRole('logbook_manager', name: 'Mary Manager');
    $this->organization = $this->platform()->organization('acme')['id'];
});

it('shows Logbook\'s people, the rest of the workspace and open invitations', function () {
    $this->platform()->applicationMembers['acme'] = [[
        'membership_id' => '01a1f5aa-0000-7000-8000-000000000001', 'sub' => (string) $this->manager->id, 'name' => 'Mary Manager',
        'sign_in_name' => 'mary@example.test', 'membership_status' => 'active', 'is_owner' => false, 'application_access' => 'active',
        'roles' => [['role' => 'logbook_manager', 'name' => 'Manager', 'scope_type' => 'organization', 'branch_id' => null, 'expires_at' => null]],
    ]];
    $this->platform()->workspaceMembers['acme'] = [
        ['membership_id' => '01a1f5aa-0000-7000-8000-000000000001', 'sub' => (string) $this->manager->id, 'name' => 'Mary Manager', 'sign_in_name' => 'mary@example.test', 'is_owner' => false],
        ['membership_id' => '01a1f5aa-0000-7000-8000-000000000002', 'sub' => 'x', 'name' => 'Deng Driver', 'sign_in_name' => 'deng', 'is_owner' => false],
    ];
    $this->platform()->pendingInvitations[$this->organization] = [
        ['id' => '01a1f5aa-0000-7000-8000-000000000009', 'email' => 'new@example.test', 'invited_by' => 'Mary Manager', 'expires_at' => now()->addDays(7)->toIso8601String(), 'roles' => ['Contributor']],
    ];

    $this->get(p('people'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('people/index')
        ->has('people', 1)
        ->where('people.0.roles', ['logbook_manager'])
        ->has('others', 1)
        ->where('others.0.name', 'Deng Driver')
        ->has('invitations', 1)
        // A Manager gives the delegable roles only.
        ->where('roles', fn ($roles) => collect($roles)->where('assignable', true)->pluck('key')->sort()->values()->all()
            === ['logbook_contributor', 'logbook_reviewer', 'logbook_viewer']));
});

it('invites, gives and takes back roles as the signed-in member', function () {
    $this->post(p('people/invitations'), ['email' => 'akol@example.test', 'role' => 'logbook_contributor'])->assertSessionHasNoErrors();
    $this->post(p('people/roles'), ['membership_id' => '01a1f5aa-0000-7000-8000-000000000002', 'role' => 'logbook_reviewer'])->assertSessionHasNoErrors();
    $this->post(p('people/roles/revoke'), ['membership_id' => '01a1f5aa-0000-7000-8000-000000000002', 'role' => 'logbook_reviewer'])->assertSessionHasNoErrors();

    $calls = $this->platform()->peopleCalls;
    expect(array_column($calls, 'path'))->toBe(['/api/v1/people/invitations', '/api/v1/people/roles', '/api/v1/people/roles/revoke'])
        ->and(array_unique(array_column($calls, 'actor_user_id')))->toBe([(string) $this->manager->id])
        ->and(array_unique(array_column($calls, 'organization_id')))->toBe([$this->organization]);
});

it('adds an account for someone without an email', function () {
    $this->post(p('people/accounts'), ['name' => 'Ayen Guard', 'username' => 'Ayen.G', 'password' => 'gate-north-7', 'role' => 'logbook_contributor'])
        ->assertSessionHasNoErrors();

    expect($this->platform()->memberAccounts['ayen.g']['role'])->toBe('logbook_contributor');
});

it('shows the platform\'s refusal next to the form', function () {
    $this->platform()->refusePeople = ['role' => ['Manager can only be assigned by the organization owner.']];

    $this->post(p('people/roles'), ['membership_id' => '01a1f5aa-0000-7000-8000-000000000002', 'role' => 'logbook_manager'])
        ->assertSessionHasErrors(['role' => 'Manager can only be assigned by the organization owner.']);
});

it('is the Manager\'s and the administrator\'s, not a contributor\'s', function () {
    actingAsRole('logbook_contributor');

    $this->get(p('people'))->assertForbidden();
    $this->post(p('people/invitations'), ['email' => 'akol@example.test', 'role' => 'logbook_contributor'])->assertForbidden();
    expect($this->platform()->peopleCalls)->toBe([]);
});
