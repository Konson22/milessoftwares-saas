<?php

use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\Logbook;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Miles\PlatformClient\Http\Middleware\RequirePlatformAccess;

/*
 * Role and department: a Logbook role given for one department in Central
 * reaches that department's logbooks only. A logbook without a department
 * belongs to the whole workspace, which only whole-workspace roles reach.
 * Contributors read their own entries only.
 */

beforeEach(function () {
    $this->team = team();
    $this->maintenance = $this->platform()->department('acme', 'Maintenance');
    $this->security = $this->platform()->department('acme', 'Security');

    as_($this->team['manager'], 'logbook_manager');
    $this->maint = logbook('Generators', ['department_id' => $this->maintenance]);
    $this->maintSheet = publishedSheet($this->maint, 'GEN');
    $this->sec = logbook('Gate log', ['department_id' => $this->security]);
    $this->secSheet = publishedSheet($this->sec, 'GAT');
    $this->shared = logbook('Head office');
    $this->sharedSheet = publishedSheet($this->shared, 'HQ');

    // One entry on each, by a workspace-wide contributor.
    as_($this->team['other'], 'logbook_contributor');
    foreach ([$this->maintSheet, $this->secSheet, $this->sharedSheet] as $sheet) {
        writeEntry($sheet)->assertSessionHasNoErrors();
    }
});

/**
 * Signs in as someone whose role covers one department (or a branch).
 *
 * @param  array<string, string>  $scope
 */
function inDepartment(User $user, string $role, array $scope): void
{
    test()->platform()->grant('acme', (string) $user->id, [['permissions' => rolePermissions($role), 'scope' => $scope, 'role' => $role]]);
    test()->actingAs($user);
    test()->withSession([RequirePlatformAccess::SESSION_TENANT => test()->platform()->organization('acme')['id']]);
    Cache::flush();
}

function department(string $id): array
{
    return ['scope_type' => 'department', 'department_id' => $id];
}

it('shows a department viewer the logbooks and entries of their department only', function () {
    inDepartment($this->team['viewer'], 'logbook_viewer', department($this->maintenance));

    $this->get(p('logbooks'))->assertInertia(fn ($page) => $page->has('logbooks', 1)->where('logbooks.0.name', 'Generators')->where('logbooks.0.department', 'Maintenance'));
    $this->get(p("logbooks/{$this->maint->id}"))->assertOk();
    $this->get(p("logbooks/{$this->sec->id}"))->assertNotFound();
    $this->get(p("logbooks/{$this->shared->id}"))->assertNotFound();
    $this->get(p("sheets/{$this->secSheet->id}"))->assertNotFound();
    $this->get(p('entries?scope=all'))->assertInertia(fn ($page) => $page->where('entries.total', 1));
});

it('lets a department contributor write in their department only, and read only their own entries', function () {
    inDepartment($this->team['writer'], 'logbook_contributor', department($this->maintenance));

    writeEntry($this->maintSheet)->assertSessionHasNoErrors();
    writeEntry($this->secSheet)->assertNotFound();
    writeEntry($this->sharedSheet)->assertNotFound();
    // Other's entry on the same sheet stays out of sight.
    $this->get(p('entries?scope=all'))->assertInertia(fn ($page) => $page->where('entries.total', 1));
});

it('lets a department reviewer review entries of their department only', function () {
    inDepartment($this->team['reviewer'], 'logbook_reviewer', department($this->security));

    $this->get(p('reviews'))->assertInertia(fn ($page) => $page->has('pending', 1)->where('pending.0.sheet.code', 'GAT'));
});

it('lets a department manager manage their department\'s logbooks only', function () {
    inDepartment($this->team['manager'], 'logbook_manager', department($this->maintenance));

    // Every logbook of the department, restricted ones too.
    $this->post(p('logbooks'), ['name' => 'Pumps', 'restricted' => true, 'department_id' => $this->maintenance])->assertSessionHasNoErrors();
    $pumps = inTenant(fn () => Logbook::query()->where('name', 'Pumps')->sole());
    $this->get(p("logbooks/{$pumps->id}"))->assertOk()->assertInertia(fn ($page) => $page->where('canManage', true));

    // Not the whole workspace, nor another department.
    $this->post(p('logbooks'), ['name' => 'Anywhere'])->assertSessionHasErrors('department_id');
    $this->post(p('logbooks'), ['name' => 'Elsewhere', 'department_id' => $this->security])->assertSessionHasErrors('department_id');

    // Others' logbooks: out of reach, so not found.
    $this->get(p("logbooks/{$this->sec->id}"))->assertNotFound();
    $this->put(p("logbooks/{$this->sec->id}"), ['name' => 'Taken', 'department_id' => $this->maintenance])->assertNotFound();
    $this->put(p("logbooks/{$this->shared->id}"), ['name' => 'Taken', 'department_id' => $this->maintenance])->assertNotFound();
    $this->post(p("logbooks/{$this->sec->id}/sheets"), ['name' => 'Night', 'code' => 'NGT'])->assertNotFound();
    $this->post(p("sheets/{$this->secSheet->id}/archive"))->assertNotFound();

    // Their own department's logbook: managed.
    $this->get(p("logbooks/{$this->maint->id}"))->assertInertia(fn ($page) => $page->where('canManage', true)
        ->where('departments', [['id' => $this->maintenance, 'name' => 'Maintenance']])->where('canFileUnderWorkspace', false));
});

it('lets a whole-workspace manager file a logbook under no department or any', function () {
    as_($this->team['manager'], 'logbook_manager');

    $this->get(p('logbooks'))->assertInertia(fn ($page) => $page->has('logbooks', 3)->where('canFileUnderWorkspace', true)->has('departments', 2));
    $this->put(p("logbooks/{$this->shared->id}"), ['name' => 'Head office', 'department_id' => $this->security])->assertSessionHasNoErrors();
    expect(inTenant(fn () => $this->shared->fresh()->department_id))->toBe($this->security);
});

it('reaches the departments of a branch for a branch role', function () {
    $branch = $this->platform()->branch('acme', 'Juba');
    $juba = $this->platform()->department('acme', 'Juba workshop', $branch);
    as_($this->team['manager'], 'logbook_manager');
    $workshop = logbook('Workshop', ['department_id' => $juba]);

    inDepartment($this->team['viewer'], 'logbook_viewer', ['scope_type' => 'branch', 'branch_id' => $branch]);
    $this->get(p('logbooks'))->assertInertia(fn ($page) => $page->has('logbooks', 1)->where('logbooks.0.name', 'Workshop'));
    $this->get(p("logbooks/{$workshop->id}"))->assertOk();
});

it('shows a department auditor the audit trail of their department only', function () {
    inDepartment($this->team['auditor'], 'logbook_auditor', department($this->security));

    $this->get(p('audit'))->assertInertia(function ($page) {
        $subjects = collect($page->toArray()['props']['events']['data'])->pluck('subject');
        expect($subjects)->not->toBeEmpty()
            ->and($subjects->filter(fn ($s) => str_contains((string) $s, 'Generators') || str_contains((string) $s, 'GEN')))->toBeEmpty();

        return $page;
    });
    expect(inTenant(fn () => AuditEvent::query()->where('logbook_id', $this->maint->id)->exists()))->toBeTrue();
});

it('lets a department people manager give roles in their department only', function () {
    inDepartment($this->team['manager'], 'logbook_manager', department($this->maintenance));
    $membership = 'a1b2c3d4-0000-4000-8000-000000000001';

    $this->post(p('people/roles'), ['membership_id' => $membership, 'role' => 'logbook_contributor'])->assertSessionHasErrors('department_id');
    $this->post(p('people/roles'), ['membership_id' => $membership, 'role' => 'logbook_contributor', 'department_id' => $this->security])->assertSessionHasErrors('department_id');
    $this->post(p('people/roles'), ['membership_id' => $membership, 'role' => 'logbook_contributor', 'department_id' => $this->maintenance])->assertSessionHasNoErrors();

    $call = collect($this->platform()->peopleCalls)->last();
    expect($call['path'])->toBe('/api/v1/people/roles')
        ->and($call['department_id'])->toBe($this->maintenance);
});
