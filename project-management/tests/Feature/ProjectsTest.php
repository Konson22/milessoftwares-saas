<?php

use App\Models\AuditEvent;
use App\Models\Person;
use App\Models\Project;
use App\Models\Risk;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Support\ProjectCodes;
use App\Support\ProjectMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    $this->organization('acme')->forceFill(['currency' => 'SSP', 'timezone' => 'Africa/Juba'])->save();
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
    test()->get(test()->organizationUrl('acme'))->assertOk();

    return test()->asTenant('acme', fn () => Person::query()->whereNotNull('user_id')->latest()->firstOrFail());
}

/**
 * @param  array<string, mixed>  $details
 */
function startProject(array $details = []): Project
{
    test()->post(test()->organizationUrl('acme', 'projects'), [
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
    test()->post(test()->organizationUrl('acme', "projects/{$project->id}/tasks"), [
        'title' => 'A task',
        'priority' => 'medium',
        ...$details,
    ])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Task::query()->where('project_id', $project->id)->orderByDesc('number')->firstOrFail());
}

function moveTask(Project $project, Task $task, string $status, ?int $position = null): TestResponse
{
    return test()->post(test()->organizationUrl('acme', "projects/{$project->id}/tasks/{$task->id}/move"), array_filter(['status' => $status, 'position' => $position], fn ($value) => $value !== null));
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

    // The code is the task numbers' prefix, so it stays once tasks exist, and the form says why.
    $this->put($this->organizationUrl('acme', "projects/{$project->id}"), [
        'code' => 'SITE', 'name' => 'New website', 'budget' => '10000',
    ])->assertSessionHasErrors(['code' => 'Tasks are already numbered WEB-1 and on, so the prefix stays WEB.']);
    // Saved without changing it (or with it left blank): fine.
    $this->put($this->organizationUrl('acme', "projects/{$project->id}"), ['code' => '', 'name' => 'New website 2', 'budget' => '10000'])->assertSessionHasNoErrors();
    expect($project->fresh()?->code)->toBe('WEB')->and($project->fresh()?->name)->toBe('New website 2');
});

it('refuses a project code that is taken or badly formed', function () {
    signIn();
    startProject();

    $this->post($this->organizationUrl('acme', 'projects'), ['code' => 'WEB', 'name' => 'Another'])->assertSessionHasErrors('code');
    $this->post($this->organizationUrl('acme', 'projects'), ['code' => '1X', 'name' => 'Another'])->assertSessionHasErrors('code');
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

    $this->get($this->organizationUrl('acme', "projects/{$project->id}/board"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/projects/board')
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
    $url = fn (Task $task) => $this->organizationUrl('acme', "projects/{$project->id}/tasks/{$task->id}/dependencies");

    $this->post($url($build), ['depends_on_id' => $design->id])->assertSessionHasNoErrors();
    $this->post($url($design), ['depends_on_id' => $build->id])->assertSessionHasErrors('depends_on_id');
    $this->post($url($build), ['depends_on_id' => $build->id])->assertSessionHasErrors('depends_on_id');

    moveTask($project, $build, Task::DONE)->assertSessionHasErrors('status');

    moveTask($project, $design, Task::DONE)->assertSessionHasNoErrors();
    moveTask($project, $build, Task::DONE)->assertSessionHasNoErrors();

    $this->delete($this->organizationUrl('acme', "projects/{$project->id}/tasks/{$build->id}/dependencies/{$design->id}"))->assertSessionHasNoErrors();
    $this->asTenant('acme', fn () => expect($build->dependencies()->count())->toBe(0));
});

it('logs time at the person\'s cost and lets members log only their own', function () {
    signIn();
    $me = me();
    $this->asTenant('acme', fn () => $me->forceFill(['hourly_cost_minor' => 2_000])->save());
    $contractor = $this->asTenant('acme', fn () => Person::query()->create(['name' => 'Deng Bol', 'hourly_cost_minor' => 3_000]));
    $project = startProject();
    $task = addTask($project);

    $this->post($this->organizationUrl('acme', "projects/{$project->id}/time"), [
        'person_id' => $me->id, 'task_id' => $task->id, 'worked_on' => '2026-10-26', 'hours' => '1:30',
    ])->assertSessionHasNoErrors();

    $entry = $this->asTenant('acme', fn () => TimeEntry::query()->firstOrFail());
    expect($entry->minutes)->toBe(90)->and($entry->cost_minor)->toBe(3_000);

    $this->post($this->organizationUrl('acme', "projects/{$project->id}/time"), [
        'person_id' => $me->id, 'worked_on' => '2026-10-27', 'hours' => '1',
    ])->assertSessionHasErrors('worked_on');
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/time"), [
        'person_id' => $me->id, 'worked_on' => '2026-10-26', 'hours' => 'lots',
    ])->assertSessionHasErrors('hours');

    // A plain member logs their own time, not a contractor's.
    signIn(['projects.view', 'projects.time.log']);
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/time"), [
        'person_id' => $contractor->id, 'worked_on' => '2026-10-26', 'hours' => '2',
    ])->assertForbidden();
});

it('weighs spending against the budget lines, with labour from time', function () {
    signIn();
    $me = me();
    $this->asTenant('acme', fn () => $me->forceFill(['hourly_cost_minor' => 10_000])->save());
    $project = startProject();
    $lines = $this->organizationUrl('acme', "projects/{$project->id}/budget/lines");

    $this->post($lines, ['category' => 'labour', 'amount' => '4000'])->assertSessionHasNoErrors();
    $this->post($lines, ['category' => 'equipment', 'amount' => '2500'])->assertSessionHasNoErrors();
    expect($project->fresh()?->budget_minor)->toBe(650_000);

    $this->post($this->organizationUrl('acme', "projects/{$project->id}/expenses"), [
        'category' => 'labour', 'description' => 'Overtime', 'amount' => '10', 'spent_on' => '2026-10-20',
    ])->assertSessionHasErrors('category');
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/expenses"), [
        'category' => 'equipment', 'description' => 'Laptop', 'supplier' => 'Juba IT', 'amount' => '1200', 'spent_on' => '2026-10-20',
    ])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/time"), [
        'person_id' => $me->id, 'worked_on' => '2026-10-26', 'hours' => '3',
    ])->assertSessionHasNoErrors();

    $this->get($this->organizationUrl('acme', "projects/{$project->id}/budget"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/projects/budget')
            ->where('budgetMinor', 650_000)
            ->where('actualMinor', 150_000)
            ->where('byCategory.0.category', 'labour')
            ->where('byCategory.0.actualMinor', 30_000)
            ->where('byCategory.1.category', 'equipment')
            ->where('byCategory.1.actualMinor', 120_000));

    // Without finance access, no money: neither the page nor the figures.
    signIn(['projects.view']);
    $this->get($this->organizationUrl('acme', "projects/{$project->id}/budget"))->assertForbidden();
    $this->get($this->organizationUrl('acme', "projects/{$project->id}"))
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

    $this->get($this->organizationUrl('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/home')
            ->where('portfolio.open', 1)
            ->where('portfolio.offTrack', 1)
            ->where('portfolio.projects.0.code', 'WEB'));
});

it('judges health by the slack the organization sets', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-31'));
    signIn();
    // Half the time gone, nothing done: 50 points behind.
    $project = startProject(['start_on' => '2026-10-01', 'due_on' => '2026-11-30']);
    addTask($project, ['due_on' => '2026-11-20']);
    $this->asTenant('acme', fn () => expect(ProjectMetrics::for($project)['health'])->toBe(ProjectMetrics::AT_RISK)
        ->and(ProjectMetrics::for($project)['healthReasons'])->toBe(['0% done with 50% of the time gone']));

    // Only administrators set it, within 0 to 100 points.
    $this->put($this->organizationUrl('acme', 'admin/settings/health'), ['health_schedule_slack' => 60, 'health_budget_slack' => 20])->assertForbidden();
    $this->actingAs($this->member('acme', owner: true));
    $this->put($this->organizationUrl('acme', 'admin/settings/health'), ['health_schedule_slack' => 101, 'health_budget_slack' => -1])
        ->assertSessionHasErrors(['health_schedule_slack', 'health_budget_slack']);
    $this->get($this->organizationUrl('acme', 'admin/settings'))
        ->assertInertia(fn (Assert $page) => $page->where('settings.health', ['scheduleSlack' => 20, 'budgetSlack' => 20]));

    $this->put($this->organizationUrl('acme', 'admin/settings/health'), ['health_schedule_slack' => 60, 'health_budget_slack' => 20])
        ->assertSessionHasNoErrors();
    $this->asTenant('acme', fn () => expect(ProjectMetrics::for($project)['health'])->toBe(ProjectMetrics::ON_TRACK));
    expect(AuditEvent::query()->where('action', 'organization.health_thresholds_updated')->first()?->properties)
        ->toBe([
            'before' => ['health_schedule_slack' => 20],
            'after' => ['health_schedule_slack' => 60],
        ]);
});

it('keeps a risk register scored probability by impact', function () {
    signIn();
    $project = startProject();
    $url = $this->organizationUrl('acme', "projects/{$project->id}/risks");

    $this->post($url, ['title' => 'Supplier late', 'probability' => 4, 'impact' => 4])->assertSessionHasNoErrors();
    $this->post($url, ['title' => 'Scope creep', 'probability' => 2, 'impact' => 3])->assertSessionHasNoErrors();
    $this->post($url, ['title' => 'Bad', 'probability' => 6, 'impact' => 1])->assertSessionHasErrors('probability');

    $this->get($url)->assertInertia(fn (Assert $page) => $page
        ->component('portal/projects/risks')
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

    $this->post($this->organizationUrl('acme', "projects/{$project->id}/members"), ['person_id' => $person->id, 'role' => 'Designer', 'allocation_percent' => 60])->assertSessionHasNoErrors();
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/members"), ['person_id' => $person->id, 'allocation_percent' => 10])->assertSessionHasErrors('person_id');
    $this->post($this->organizationUrl('acme', "projects/{$project->id}/milestones"), ['name' => 'Launch', 'due_on' => '2026-12-15'])->assertSessionHasNoErrors();

    $this->get($this->organizationUrl('acme', "projects/{$project->id}/team"))
        ->assertInertia(fn (Assert $page) => $page
            ->component('portal/projects/team')
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
        $this->get($this->organizationUrl('acme', rtrim("projects/{$project->id}/{$page}", '/')))->assertOk();
    }
    $this->get($this->organizationUrl('acme', 'my-work'))->assertOk();
    $this->get($this->organizationUrl('acme', 'people'))->assertOk();

    $this->post($this->organizationUrl('acme', "projects/{$project->id}/tasks"), ['title' => 'Nope', 'priority' => 'low'])->assertForbidden();
    moveTask($project, $task, Task::DONE)->assertForbidden();
    $this->post($this->organizationUrl('acme', 'projects'), ['code' => 'NOPE', 'name' => 'Nope'])->assertForbidden();
});

it('keeps each organization\'s projects to itself', function () {
    signIn();
    $project = startProject();

    $this->actingAsMember('globex', PM);
    $this->get($this->organizationUrl('globex', "projects/{$project->id}"))->assertNotFound();
});

it('makes a task prefix from the name when it is left blank', function () {
    signIn();
    $url = $this->organizationUrl('acme', 'projects');

    foreach (['Water Supply Project' => 'WSP', 'Website Development' => 'WEB', 'Education Programme' => 'EDU', 'Health Services' => 'HEA'] as $name => $code) {
        $this->post($url, ['code' => '', 'name' => $name])->assertSessionHasNoErrors();
        expect($this->asTenant('acme', fn () => Project::query()->where('name', $name)->value('code')))->toBe($code);
    }

    // Taken already: the next free one.
    $this->post($url, ['name' => 'Website Redesign'])->assertSessionHasNoErrors();
    $this->post($url, ['name' => 'Website Hosting'])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => Project::query()->where('name', 'like', 'Website %')->orderBy('code')->pluck('code')->all()))
        ->toBe(['WEB', 'WEB2', 'WEB3']);

    // Odd names still get a valid prefix.
    $this->post($url, ['name' => '2026 & beyond: a very, very long programme name for the northern region'])->assertSessionHasNoErrors();
    $this->post($url, ['name' => '!!!'])->assertSessionHasNoErrors();
    $codes = $this->asTenant('acme', fn () => Project::query()->pluck('code')->all());
    foreach ($codes as $code) {
        expect($code)->toMatch(ProjectCodes::PATTERN);
    }

    // Tasks are numbered with it.
    $project = $this->asTenant('acme', fn () => Project::query()->where('name', 'Water Supply Project')->firstOrFail());
    expect(addTask($project, ['title' => 'Survey'])->number)->toBe('WSP-1');
});

it('explains a prefix that is badly formed, and leaves other organizations alone', function () {
    signIn();
    $url = $this->organizationUrl('acme', 'projects');

    $this->post($url, ['code' => 'we b!', 'name' => 'Bad'])
        ->assertSessionHasErrors(['code' => 'Use 2 to 10 letters or numbers, starting with a letter and without spaces (for example WEB). Or leave it blank.']);
    $this->post($url, ['code' => 'ABCDEFGHIJK', 'name' => 'Too long'])->assertSessionHasErrors('code');
    $this->post($url, ['code' => 'web', 'name' => 'Website'])->assertSessionHasNoErrors();
    $this->post($url, ['code' => 'WEB', 'name' => 'Again'])
        ->assertSessionHasErrors(['code' => 'Another project already uses this prefix. Choose another, or leave it blank to get one.']);

    // Another organization's WEB does not count.
    $this->actingAsMember('globex', PM);
    $this->post($this->organizationUrl('globex', 'projects'), ['name' => 'Website'])->assertSessionHasNoErrors();
    expect($this->asTenant('globex', fn () => Project::query()->value('code')))->toBe('WEB');
});

it('takes the next prefix when another project grabbed it at the same moment', function () {
    signIn();
    me();
    $raced = false;
    // Between choosing WEB and saving it, another request saves WEB (as two set up at once would).
    Project::creating(function (Project $project) use (&$raced) {
        if (! $raced && $project->code === 'WEB') {
            $raced = true;
            DB::table('projects')->insert([
                'id' => (string) Str::uuid7(), 'organization_id' => $project->organization_id, 'code' => 'WEB', 'name' => 'Raced',
                'currency' => 'SSP', 'status' => Project::PLANNING, 'visibility' => 'organization', 'created_by' => $project->created_by,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $this->post($this->organizationUrl('acme', 'projects'), ['name' => 'Website'])->assertSessionHasNoErrors();

    // WEB collided on the unique index, so it took the next one. (The simulated rival lived in the
    // save's own transaction, so it rolled back with the collision; a real one commits apart.)
    expect($raced)->toBeTrue()
        ->and($this->asTenant('acme', fn () => Project::query()->where('name', 'Website')->value('code')))->toBe('WEB2');
});
