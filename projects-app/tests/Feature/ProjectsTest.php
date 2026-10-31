<?php

use App\Models\Person;
use App\Models\Project;
use App\Models\Risk;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ProjectMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Projects: set up with a team and milestones, broken into numbered tasks
| moved across a board (respecting what waits on what), time logged at each
| person's cost, spending against the budget, risks scored, and every
| project's health judged from all of that.
*/

const PM = [
    'projects.view', 'projects.manage', 'projects.tasks.manage', 'projects.time.log',
    'projects.time.manage', 'projects.finance.view', 'projects.people.manage',
];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-26 09:00'));
    $this->asTenant('acme', fn () => Workspace::query()->create(['slug' => 'acme', 'display_name' => 'Acme', 'currency' => 'SSP', 'timezone' => 'Africa/Juba']));
});

/**
 * @param  list<string>  $permissions
 */
function signIn(array $permissions = PM): User
{
    $member = test()->actingAsMember('acme', $permissions);
    assert($member instanceof User);

    return $member;
}

/**
 * The signed-in member's entry in the people directory (made on their first page).
 */
function me(): Person
{
    test()->get(test()->workspaceUrl('acme'))->assertOk();

    return test()->asTenant('acme', fn () => Person::query()->whereNotNull('user_id')->latest()->firstOrFail());
}

/**
 * @param  array<string, mixed>  $details
 */
function startProject(array $details = []): Project
{
    test()->post(test()->workspaceUrl('acme', 'projects'), [
        'code' => 'web',
        'name' => 'New website',
        'start_on' => '2026-10-01',
        'due_on' => '2026-12-31',
        'budget' => '10000',
        ...$details,
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Project::query()->latest()->firstOrFail());
}

/**
 * @param  array<string, mixed>  $details
 */
function addTask(Project $project, array $details = []): Task
{
    test()->post(test()->workspaceUrl('acme', "projects/{$project->id}/tasks"), [
        'title' => 'A task',
        'priority' => 'medium',
        ...$details,
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Task::query()->where('project_id', $project->id)->orderByDesc('number')->firstOrFail());
}

function moveTask(Project $project, Task $task, string $status, ?int $position = null): TestResponse
{
    return test()->post(test()->workspaceUrl('acme', "projects/{$project->id}/tasks/{$task->id}/move"), array_filter(['status' => $status, 'position' => $position], fn ($value) => $value !== null));
}

it('sets up a project with its manager on the team and numbers its tasks', function () {
    signIn();
    $me = me();

    $project = startProject(['manager_id' => $me->id]);

    expect($project->code)->toBe('WEB')
        ->and($project->status)->toBe(Project::PLANNING)
        ->and($project->budget_minor)->toBe(1_000_000)
        ->and($project->currency)->toBe('SSP');
    $this->asTenant('acme', fn () => expect($project->members()->where('person_id', $me->id)->exists())->toBeTrue());

    $first = addTask($project, ['title' => 'Wireframes']);
    $second = addTask($project, ['title' => 'Build pages']);

    expect([$first->number, $second->number])->toBe(['WEB-1', 'WEB-2']);

    // The code is the task numbers' prefix, so it stays once tasks exist.
    $this->put($this->workspaceUrl('acme', "projects/{$project->id}"), [
        'code' => 'SITE', 'name' => 'New website', 'budget' => '10000',
    ])->assertSessionHasNoErrors();
    expect($project->fresh()?->code)->toBe('WEB');
});

it('refuses a project code that is taken or badly formed', function () {
    signIn();
    startProject();

    $this->post($this->workspaceUrl('acme', 'projects'), ['code' => 'WEB', 'name' => 'Another'])->assertSessionHasErrors('code');
    $this->post($this->workspaceUrl('acme', 'projects'), ['code' => '1X', 'name' => 'Another'])->assertSessionHasErrors('code');
});

it('moves tasks across the board and reorders a column', function () {
    signIn();
    $project = startProject();
    $a = addTask($project, ['title' => 'A']);
    $b = addTask($project, ['title' => 'B']);
    $c = addTask($project, ['title' => 'C']);

    moveTask($project, $c, Task::TODO, 0)->assertSessionHasNoErrors();
    $this->asTenant('acme', fn () => expect(Task::query()->where('status', Task::TODO)->orderBy('position')->pluck('title')->all())->toBe(['C', 'A', 'B']));

    moveTask($project, $a, Task::IN_PROGRESS)->assertSessionHasNoErrors();
    moveTask($project, $a, Task::DONE)->assertSessionHasNoErrors();

    $a = $this->asTenant('acme', fn () => $a->fresh());
    expect($a?->status)->toBe(Task::DONE)->and($a?->completed_at)->not->toBeNull();

    $this->get($this->workspaceUrl('acme', "projects/{$project->id}/board"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/board')
            ->has('columns', 4)
            ->where('columns.0.tasks.0.title', 'C')
            ->where('columns.3.tasks.0.number', 'WEB-1')
            ->where('project.progress', 33));
});

it('keeps a task from being done while what it waits for is open, and refuses circles', function () {
    signIn();
    $project = startProject();
    $design = addTask($project, ['title' => 'Design']);
    $build = addTask($project, ['title' => 'Build']);
    $url = fn (Task $task) => $this->workspaceUrl('acme', "projects/{$project->id}/tasks/{$task->id}/dependencies");

    $this->post($url($build), ['depends_on_id' => $design->id])->assertSessionHasNoErrors();
    $this->post($url($design), ['depends_on_id' => $build->id])->assertSessionHasErrors('depends_on_id');
    $this->post($url($build), ['depends_on_id' => $build->id])->assertSessionHasErrors('depends_on_id');

    moveTask($project, $build, Task::DONE)->assertSessionHasErrors('status');

    moveTask($project, $design, Task::DONE)->assertSessionHasNoErrors();
    moveTask($project, $build, Task::DONE)->assertSessionHasNoErrors();

    $this->delete($this->workspaceUrl('acme', "projects/{$project->id}/tasks/{$build->id}/dependencies/{$design->id}"))->assertSessionHasNoErrors();
    $this->asTenant('acme', fn () => expect($build->dependencies()->count())->toBe(0));
});

it('logs time at the person\'s cost and lets members log only their own', function () {
    signIn();
    $me = me();
    $this->asTenant('acme', fn () => $me->forceFill(['hourly_cost_minor' => 2_000])->save());
    $contractor = $this->asTenant('acme', fn () => Person::query()->create(['name' => 'Deng Bol', 'hourly_cost_minor' => 3_000]));
    $project = startProject();
    $task = addTask($project);

    $this->post($this->workspaceUrl('acme', "projects/{$project->id}/time"), [
        'person_id' => $me->id, 'task_id' => $task->id, 'worked_on' => '2026-10-26', 'hours' => '1:30',
    ])->assertSessionHasNoErrors();

    $entry = $this->asTenant('acme', fn () => TimeEntry::query()->firstOrFail());
    expect($entry->minutes)->toBe(90)->and($entry->cost_minor)->toBe(3_000);

    $this->post($this->workspaceUrl('acme', "projects/{$project->id}/time"), [
        'person_id' => $me->id, 'worked_on' => '2026-10-27', 'hours' => '1',
    ])->assertSessionHasErrors('worked_on');
    $this->post($this->workspaceUrl('acme', "projects/{$project->id}/time"), [
        'person_id' => $me->id, 'worked_on' => '2026-10-26', 'hours' => 'lots',
    ])->assertSessionHasErrors('hours');

    // A plain member logs their own time, not a contractor's.
    signIn(['projects.view', 'projects.time.log']);
    $this->post($this->workspaceUrl('acme', "projects/{$project->id}/time"), [
        'person_id' => $contractor->id, 'worked_on' => '2026-10-26', 'hours' => '2',
    ])->assertForbidden();
});

it('weighs spending against the budget lines, with labour from time', function () {
    signIn();
    $me = me();
    $this->asTenant('acme', fn () => $me->forceFill(['hourly_cost_minor' => 10_000])->save());
    $project = startProject();
    $lines = $this->workspaceUrl('acme', "projects/{$project->id}/budget/lines");

    $this->post($lines, ['category' => 'labour', 'amount' => '4000'])->assertSessionHasNoErrors();
    $this->post($lines, ['category' => 'equipment', 'amount' => '2500'])->assertSessionHasNoErrors();
    expect($project->fresh()?->budget_minor)->toBe(650_000);

    $this->post($this->workspaceUrl('acme', "projects/{$project->id}/expenses"), [
        'category' => 'labour', 'description' => 'Overtime', 'amount' => '10', 'spent_on' => '2026-10-20',
    ])->assertSessionHasErrors('category');
    $this->post($this->workspaceUrl('acme', "projects/{$project->id}/expenses"), [
        'category' => 'equipment', 'description' => 'Laptop', 'supplier' => 'Juba IT', 'amount' => '1200', 'spent_on' => '2026-10-20',
    ])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', "projects/{$project->id}/time"), [
        'person_id' => $me->id, 'worked_on' => '2026-10-26', 'hours' => '3',
    ])->assertSessionHasNoErrors();

    $this->get($this->workspaceUrl('acme', "projects/{$project->id}/budget"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/budget')
            ->where('budgetMinor', 650_000)
            ->where('actualMinor', 150_000)
            ->where('byCategory.0.category', 'labour')
            ->where('byCategory.0.actualMinor', 30_000)
            ->where('byCategory.1.category', 'equipment')
            ->where('byCategory.1.actualMinor', 120_000));

    // Without finance access, no money: neither the page nor the figures.
    signIn(['projects.view']);
    $this->get($this->workspaceUrl('acme', "projects/{$project->id}/budget"))->assertForbidden();
    $this->get($this->workspaceUrl('acme', "projects/{$project->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('metrics.actualMinor', null)->where('project.can.finance', false));
});

it('judges health from schedule, overdue work and spend', function () {
    signIn();
    $project = startProject(['start_on' => '2026-10-01', 'due_on' => '2026-11-30']);
    addTask($project, ['due_on' => '2026-11-15']);

    $this->asTenant('acme', fn () => expect(ProjectMetrics::for($project)['health'])->toBe(ProjectMetrics::AT_RISK));

    $late = addTask($project, ['due_on' => '2026-10-20']);
    $this->asTenant('acme', function () use ($project, $late) {
        expect($late->overdue())->toBeTrue()
            ->and(ProjectMetrics::for($project)['overdueTasks'])->toBe(1);
    });

    $this->travelTo(CarbonImmutable::parse('2026-12-05'));
    $this->asTenant('acme', fn () => expect(ProjectMetrics::for($project->fresh() ?? $project)['health'])->toBe(ProjectMetrics::OFF_TRACK));

    $this->get($this->workspaceUrl('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('home')
            ->where('portfolio.open', 1)
            ->where('portfolio.offTrack', 1)
            ->where('portfolio.projects.0.code', 'WEB'));
});

it('keeps a risk register scored probability by impact', function () {
    signIn();
    $project = startProject();
    $url = $this->workspaceUrl('acme', "projects/{$project->id}/risks");

    $this->post($url, ['title' => 'Supplier late', 'probability' => 4, 'impact' => 4])->assertSessionHasNoErrors();
    $this->post($url, ['title' => 'Scope creep', 'probability' => 2, 'impact' => 3])->assertSessionHasNoErrors();
    $this->post($url, ['title' => 'Bad', 'probability' => 6, 'impact' => 1])->assertSessionHasErrors('probability');

    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->component('projects/risks')
        ->where('risks.0.title', 'Supplier late')
        ->where('risks.0.score', 16)
        ->where('risks.0.level', 'high')
        ->where('risks.1.level', 'low'));

    $risk = $this->asTenant('acme', fn () => Risk::query()->where('title', 'Supplier late')->firstOrFail());
    $this->put("{$url}/{$risk->id}", ['title' => 'Supplier late', 'probability' => 4, 'impact' => 4, 'status' => 'closed'])->assertSessionHasNoErrors();
    expect($risk->fresh()?->status)->toBe(Risk::CLOSED);
});

it('builds the team with allocations and milestones', function () {
    signIn();
    $project = startProject();
    $person = $this->asTenant('acme', fn () => Person::query()->create(['name' => 'Achol Garang']));

    $this->post($this->workspaceUrl('acme', "projects/{$project->id}/members"), ['person_id' => $person->id, 'role' => 'Designer', 'allocation_percent' => 60])->assertSessionHasNoErrors();
    $this->post($this->workspaceUrl('acme', "projects/{$project->id}/members"), ['person_id' => $person->id, 'allocation_percent' => 10])->assertSessionHasErrors('person_id');
    $this->post($this->workspaceUrl('acme', "projects/{$project->id}/milestones"), ['name' => 'Launch', 'due_on' => '2026-12-15'])->assertSessionHasNoErrors();

    $this->get($this->workspaceUrl('acme', "projects/{$project->id}/team"))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/team')
            ->where('members.0.name', 'Achol Garang')
            ->where('members.0.allocation', 60)
            ->where('milestones.0.name', 'Launch'));
});

it('lets viewers look but not change anything', function () {
    signIn();
    $project = startProject();
    $task = addTask($project);

    signIn(['projects.view']);
    foreach (['', 'board', 'tasks', 'timeline', 'team', 'time', 'risks', "tasks/{$task->id}"] as $page) {
        $this->get($this->workspaceUrl('acme', rtrim("projects/{$project->id}/{$page}", '/')))->assertOk();
    }
    $this->get($this->workspaceUrl('acme', 'my-work'))->assertOk();
    $this->get($this->workspaceUrl('acme', 'people'))->assertOk();

    $this->post($this->workspaceUrl('acme', "projects/{$project->id}/tasks"), ['title' => 'Nope', 'priority' => 'low'])->assertForbidden();
    moveTask($project, $task, Task::DONE)->assertForbidden();
    $this->post($this->workspaceUrl('acme', 'projects'), ['code' => 'NOPE', 'name' => 'Nope'])->assertForbidden();
});

it('keeps each workspace\'s projects to itself', function () {
    signIn();
    $project = startProject();

    $this->actingAsMember('globex', PM);
    $this->get($this->workspaceUrl('globex', "projects/{$project->id}"))->assertNotFound();
});
