<?php

use App\Enums\BudgetVersionStatus;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Models\BudgetVersion;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Stabilization milestone: project-level access inside an organization, and
| the project money M&E pages may show.
|
| A project is open to the organization (the default, and what every
| existing project keeps) or to its team only. Team-only projects are seen
| by the owner, holders of projects.all, its manager, whoever set it up and
| its team; the restriction reaches the project's records too (tasks,
| expenses, attachments...), and external participants keep the access
| their participation gives.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 09:00'));
    Notification::fake();
});

/**
 * Someone in acme with these permissions, with a Person record (so they
 * can be put on a team).
 *
 * @param  list<string>  $permissions
 * @return array{user: User, person: Person}
 */
function paMember(array $permissions, string $name = 'Field Officer'): array
{
    $user = User::factory()->create(['name' => $name]);
    test()->actingAsMember('acme', $permissions, user: $user);
    $person = test()->asTenant('acme', fn () => tap(tap(new Person)->forceFill(['name' => $name, 'user_id' => $user->id]))->save());

    return ['user' => $user, 'person' => $person];
}

/**
 * A team-only project and a task on it, set up by the owner.
 *
 * @return array{project: Project, task: Task}
 */
function paTeamProject(): array
{
    $project = collabProject();
    test()->put(test()->organizationUrl('acme', "projects/{$project->id}"), [
        'code' => 'WASH', 'name' => 'Clean water', 'visibility' => 'team',
    ])->assertSessionHasNoErrors();
    $task = test()->asTenant('acme', fn () => Task::query()->where('project_id', $project->id)->first())
        ?? test()->asTenant('acme', function () use ($project) {
            test()->post(test()->organizationUrl('acme', "projects/{$project->id}/tasks"), ['title' => 'Drill the first well', 'priority' => 'medium'])->assertSessionHasNoErrors();

            return Task::query()->where('project_id', $project->id)->firstOrFail();
        });

    return ['project' => $project->refresh(), 'task' => $task];
}

it('keeps every existing and new project open to the organization unless made team-only', function () {
    $project = collabProject();
    expect($project->visibility)->toBe(Project::VISIBLE_TO_ORGANIZATION);

    paMember(['projects.view']);
    $this->get($this->organizationUrl('acme', "projects/{$project->id}"))->assertOk();
});

it('shows a team-only project, and its records, to its team and to those who see every project only', function () {
    ['project' => $project, 'task' => $task] = paTeamProject();
    $projectUrl = $this->organizationUrl('acme', "projects/{$project->id}");
    $taskUrl = $this->organizationUrl('acme', "projects/{$project->id}/tasks/{$task->id}");

    // Someone who may see projects, but is not on this one's team.
    ['user' => $user, 'person' => $person] = paMember(['projects.view', 'projects.tasks.manage']);
    $this->get($projectUrl)->assertNotFound();
    $this->get($taskUrl)->assertNotFound();
    $this->get($this->organizationUrl('acme', 'projects'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('projects', fn ($projects) => collect($projects['data'] ?? $projects)->pluck('id')->doesntContain($project->id)));
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/reports"))->assertNotFound();

    // On the team: in.
    $member = $this->asTenant('acme', fn () => ProjectMember::query()->create(['project_id' => $project->id, 'person_id' => $person->id]));
    $this->get($projectUrl)->assertOk();
    $this->get($taskUrl)->assertOk();

    // Taken off the team: out again, at once.
    $this->asTenant('acme', fn () => ProjectMember::query()->whereKey($member->id)->delete());
    $this->get($projectUrl)->assertNotFound();

    // Portfolio managers (projects.all, in the admin, manager, controller and
    // finance officer roles) and the owner see it.
    $this->actingAsMember('acme', rolePermissions('manager'));
    $this->get($projectUrl)->assertOk();
    $this->get($taskUrl)->assertOk();
    $this->actingAsMember('acme', [], owner: true);
    $this->get($projectUrl)->assertOk();
});

it('lets nobody make a project team-only and so shut themselves out of it', function () {
    $project = collabProject();
    paMember(['projects.view', 'projects.manage']);

    $this->put($this->organizationUrl('acme', "projects/{$project->id}"), ['code' => 'WASH', 'name' => 'Clean water', 'visibility' => 'team'])
        ->assertSessionHasErrors('visibility');
    expect($project->refresh()->visibility)->toBe(Project::VISIBLE_TO_ORGANIZATION);
});

it('keeps external participants on a team-only project through their participation', function () {
    $project = collabProject();
    $participant = acceptProjectInvitation(inviteToProject($project, 'lead-ngo', 'lead_implementer', ['view']), 'lead-ngo');
    $this->actingAsMember('acme', [], owner: true);
    $this->put($this->organizationUrl('acme', "projects/{$project->id}"), ['code' => 'WASH', 'name' => 'Clean water', 'visibility' => 'team'])->assertSessionHasNoErrors();

    $this->actingAsMember('lead-ngo', COLLAB_MANAGER);
    $this->get($this->organizationUrl('lead-ngo', "shared-projects/{$participant->id}"))->assertOk();
});

/*
| M&E: linked project figures.
*/

/**
 * A programme with its Juba unit linked to a project whose typed estimate is
 * 50,000; set up by the owner. Returns what the tests need.
 *
 * @return array{programme: string, project: Project}
 */
function paLinkedProgramme(): array
{
    $project = collabProject(); // estimate 50000, no budget version
    $programme = setUpProgramme();
    test()->put(mel("programmes/{$programme['programme']->id}/units/{$programme['units']['JUB']->id}/project"), ['linked_project_id' => $project->id])->assertSessionHasNoErrors();

    return ['programme' => $programme['programme']->id, 'project' => $project];
}

/**
 * The linked project as the programme page shows it to whoever is signed in.
 *
 * @return array<string, mixed>|null
 */
function paLinked(string $programme): ?array
{
    $page = test()->get(mel("programmes/{$programme}"))->assertOk()->viewData('page');

    return collect($page['props']['units'])->firstWhere('code', 'JUB')['project'] ?? null;
}

it('shows M&E roles no Projects records, and project money only to those who may see it', function () {
    ['programme' => $programme, 'project' => $project] = paLinkedProgramme();

    // Paid 500.00; 9.99 of logged staff time (estimated personnel cost, not spending).
    $this->asTenant('acme', function () use ($project) {
        $project->expenses()->create(['category' => CostCategory::Materials, 'description' => 'Pipes', 'amount_minor' => 50000, 'status' => ExpenseStatus::Paid, 'direct' => true, 'spent_on' => '2026-11-02', 'recorded_by' => User::query()->firstOrFail()->id]);
        $person = Person::query()->create(['name' => 'Engineer', 'hourly_cost_minor' => 999]);
        TimeEntry::query()->create(['project_id' => $project->id, 'person_id' => $person->id, 'worked_on' => '2026-11-03', 'minutes' => 60, 'cost_minor' => 999, 'created_by' => User::query()->firstOrFail()->id]);
    });

    // Donor viewers, partners and officers of M&E: nothing of the project.
    foreach (['mel_donor_viewer', 'mel_partner', 'mel_officer', 'mel_evaluator'] as $role) {
        $this->actingAsMember('acme', [...rolePermissions($role), 'mel.programmes.all']);
        expect(paLinked($programme))->toBeNull();
    }

    // An M&E officer who also sees projects: status and progress, no money.
    $this->actingAsMember('acme', [...rolePermissions('mel_officer'), 'projects.view']);
    expect(paLinked($programme))->toMatchArray(['label' => 'WASH · Clean water', 'approvedBudgetMinor' => null, 'paidMinor' => null]);

    // With projects.finance.view: what was paid (not the staff time), and no
    // "approved budget" while only an estimate was typed.
    $this->actingAsMember('acme', [...rolePermissions('mel_officer'), 'projects.view', 'projects.finance.view']);
    expect(paLinked($programme))->toMatchArray(['approvedBudgetMinor' => null, 'paidMinor' => 50000]);

    // Once a budget version is approved, its total.
    $this->asTenant('acme', fn () => (new BudgetVersion)->forceFill(['project_id' => $project->id, 'number' => 1, 'status' => BudgetVersionStatus::Approved, 'total_minor' => 4000000, 'created_by' => User::query()->firstOrFail()->id])->save());
    expect(paLinked($programme))->toMatchArray(['approvedBudgetMinor' => 4000000, 'paidMinor' => 50000]);

    // The same rules on the programme report.
    $report = fn () => $this->get(mel("reports/programme?programme={$programme}"))->assertOk()->viewData('page')['props']['data']['projects'];
    expect($report()[0])->toMatchArray(['approvedBudgetMinor' => 4000000, 'paidMinor' => 50000]);
    $this->actingAsMember('acme', [...rolePermissions('mel_donor_viewer'), 'mel.programmes.all']);
    expect($report())->toBe([]);

    // Someone outside M&E does not open the programme at all.
    $this->actingAsMember('acme', ['projects.view', 'projects.finance.view']);
    $this->get(mel("programmes/{$programme}"))->assertForbidden();
});

it('does not show a team-only project in M&E to those outside its team', function () {
    ['programme' => $programme, 'project' => $project] = paLinkedProgramme();
    $this->put($this->organizationUrl('acme', "projects/{$project->id}"), ['code' => 'WASH', 'name' => 'Clean water', 'visibility' => 'team'])->assertSessionHasNoErrors();

    $this->actingAsMember('acme', [...rolePermissions('mel_officer'), 'projects.view', 'projects.finance.view']);
    expect(paLinked($programme))->toBeNull();
});
