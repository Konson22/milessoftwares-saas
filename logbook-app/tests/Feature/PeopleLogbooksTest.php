<?php

use App\Logbook\Models\AccessGrant;
use App\Logbook\Models\PendingMember;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Cache;

/*
 * Logbook's people are managed in Logbook alone: which logbooks someone
 * works on is chosen when inviting them (kept until their first visit) or
 * adding their account, and changed on the People page. Departments are
 * added and renamed here too.
 */

beforeEach(function () {
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->generators = logbook('Generators', ['restricted' => true]);
    $this->gate = logbook('Gate log', ['restricted' => true]);
    $this->organization = $this->platform()->organization('acme')['id'];
});

it('keeps the logbooks chosen for an invitee and makes them a member on their first visit', function () {
    $this->post(p('people/invitations'), [
        'email' => 'Akol@Example.test', 'role' => 'logbook_contributor', 'logbook_ids' => [$this->generators->id],
    ])->assertSessionHasNoErrors();
    expect(inTenant(fn () => PendingMember::query()->pluck('email')->all()))->toBe(['akol@example.test']);

    // They accept and open Logbook: a contributor of Generators, not of the gate log.
    $akol = actingAsRole('logbook_contributor', name: 'Akol');
    inTenant(fn () => $akol->forceFill(['email' => 'akol@example.test'])->save());
    inTenant(fn () => WorkspaceMember::query()->where('user_id', $akol->id)->delete());
    $this->get(p('logbooks'))->assertOk()->assertInertia(fn ($page) => $page->has('logbooks', 1)->where('logbooks.0.name', 'Generators'));
    expect(inTenant(fn () => AccessGrant::query()->where('user_id', $akol->id)->pluck('role')->all()))->toBe(['contributor'])
        ->and(inTenant(fn () => PendingMember::query()->count()))->toBe(0);
});

it('drops the chosen logbooks when the invitation is cancelled', function () {
    $this->post(p('people/invitations'), ['email' => 'akol@example.test', 'role' => 'logbook_viewer', 'logbook_ids' => [$this->gate->id]])->assertSessionHasNoErrors();
    $invitation = inTenant(fn () => PendingMember::query()->sole()->invitation_id);

    $this->post(p("people/invitations/{$invitation}/cancel"))->assertSessionHasNoErrors();
    expect(inTenant(fn () => PendingMember::query()->count()))->toBe(0);
});

it('refuses logbooks outside the department the role is for', function () {
    $maintenance = $this->platform()->department('acme', 'Maintenance');
    Cache::flush();
    $this->post(p('people/invitations'), [
        'email' => 'akol@example.test', 'role' => 'logbook_contributor', 'department_id' => $maintenance, 'logbook_ids' => [$this->gate->id],
    ])->assertSessionHasErrors('logbook_ids');
});

it('makes a new account a member of the chosen logbooks at once', function () {
    $this->post(p('people/accounts'), [
        'name' => 'Deng Driver', 'username' => 'deng', 'password' => 'temporary1', 'role' => 'logbook_contributor', 'logbook_ids' => [$this->generators->id, $this->gate->id],
    ])->assertSessionHasNoErrors();

    $deng = inTenant(fn () => User::query()->where('email', 'deng')->sole());
    expect(inTenant(fn () => AccessGrant::query()->where('user_id', $deng->id)->count()))->toBe(2);
});

it('adds a person to a logbook and removes them from the People page', function () {
    $writer = $this->team['writer'];
    $this->platform()->applicationMembers['acme'] = [[
        'membership_id' => '01a1f5aa-0000-7000-8000-000000000003', 'sub' => (string) $writer->id, 'name' => 'Writer',
        'sign_in_name' => 'writer@example.test', 'membership_status' => 'active', 'is_owner' => false, 'application_access' => 'active',
        'roles' => [['role' => 'logbook_contributor', 'name' => 'Contributor', 'scope_type' => 'organization', 'branch_id' => null, 'expires_at' => null]],
    ]];

    $this->post(p('people/logbooks'), ['user_id' => $writer->id, 'logbook_id' => $this->gate->id])->assertSessionHasNoErrors();
    $this->get(p('people'))->assertOk()->assertInertia(fn ($page) => $page->where('people.0.logbooks.0.name', 'Gate log')->where('people.0.logbooks.0.role', 'contributor'));

    $grant = inTenant(fn () => AccessGrant::query()->where('user_id', $writer->id)->where('logbook_id', $this->gate->id)->sole());
    $this->delete(p("access/{$grant->id}"))->assertSessionHasNoErrors();
    expect(inTenant(fn () => AccessGrant::query()->whereKey($grant->id)->exists()))->toBeFalse();

    // Someone without a Logbook role cannot be added.
    $this->post(p('people/logbooks'), ['user_id' => (string) str()->uuid(), 'logbook_id' => $this->gate->id])->assertSessionHasErrors('people');
});

it('lets a whole-workspace people manager add and rename departments', function () {
    $this->post(p('departments'), ['name' => 'Maintenance'])->assertSessionHasNoErrors();
    $this->get(p('departments'))->assertInertia(fn ($page) => $page->component('departments/index')
        ->where('canEdit', true)->has('departments', 1)->where('departments.0.name', 'Maintenance'));
    $id = $this->platform()->organization('acme') ? collect($this->get(p('departments'))->viewData('page')['props']['departments'])->first()['id'] : null;

    $this->put(p("departments/{$id}"), ['name' => 'Workshop'])->assertSessionHasNoErrors();
    Cache::flush();
    $this->get(p('departments'))->assertInertia(fn ($page) => $page->where('departments.0.name', 'Workshop'));
});

it('keeps departments read-only for a department people manager', function () {
    $maintenance = $this->platform()->department('acme', 'Maintenance');
    $this->platform()->grant('acme', (string) $this->team['manager']->id, [['permissions' => rolePermissions('logbook_manager'), 'scope' => ['scope_type' => 'department', 'department_id' => $maintenance], 'role' => 'logbook_manager']]);
    Cache::flush();

    $this->get(p('departments'))->assertInertia(fn ($page) => $page->where('canEdit', false)->has('departments', 1));
    $this->post(p('departments'), ['name' => 'Security'])->assertForbidden();
});
