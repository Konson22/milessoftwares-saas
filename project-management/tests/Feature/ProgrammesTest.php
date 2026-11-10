<?php

use App\Models\AuditEvent;
use App\Models\Programme;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

const PROGRAMME_LEAD = ['projects.view', 'projects.manage', 'projects.tasks.manage', 'projects.finance.view'];

beforeEach(function () {
    $this->organization('acme');
    $this->actingAsMember('acme', PROGRAMME_LEAD);
});

function pgUrl(string $path = '', string $slug = 'acme'): string
{
    return test()->organizationUrl($slug, $path);
}

function pgProgramme(string $code = 'water'): Programme
{
    test()->post(pgUrl('programmes'), ['code' => $code, 'name' => "Programme {$code}"])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Programme::query()->where('code', mb_strtoupper($code))->sole());
}

/**
 * @param  array<string, mixed>  $details
 */
function pgProject(string $code = 'wash', array $details = []): Project
{
    test()->post(pgUrl('projects'), ['code' => $code, 'name' => "Project {$code}", ...$details])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Project::query()->where('code', mb_strtoupper($code))->sole());
}

it('adds a programme and checks its code', function () {
    $programme = pgProgramme();
    expect($programme->code)->toBe('WATER');

    $this->post(pgUrl('programmes'), ['code' => 'water', 'name' => 'Again'])->assertSessionHasErrors('code');
    $this->post(pgUrl('programmes'), ['code' => '9 bad', 'name' => 'Bad'])->assertSessionHasErrors('code');
    $this->post(pgUrl('programmes'), ['code' => 'OK2', 'name' => ''])->assertSessionHasErrors('name');

    $this->get(pgUrl('programmes'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/programmes/index')
        ->has('programmes', 1)
        ->where('programmes.0.code', 'WATER')
        ->where('programmes.0.projects', 0));

    expect(AuditEvent::query()->where('action', 'programme.created')->count())->toBe(1);
});

it('adds its projects up, and hides money from those who may not see it', function () {
    $programme = pgProgramme();
    $one = pgProject('wash', ['budget' => '100', 'programme_id' => $programme->id]);
    $two = pgProject('sani', ['budget' => '50']);
    pgProject('other', ['budget' => '999']);
    $this->post(pgUrl("programmes/{$programme->id}/projects"), ['project_id' => $two->id])->assertSessionHasNoErrors();

    $this->post(pgUrl("projects/{$one->id}/tasks"), ['title' => 'Late', 'priority' => 'medium', 'due_on' => '2020-01-01'])->assertSessionHasNoErrors();

    $this->get(pgUrl("programmes/{$programme->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/programmes/show')
        ->has('projects', 2)
        ->where('summary.projects', 2)
        ->where('summary.open', 2)
        ->where('summary.overdueTasks', 1)
        ->has('totals', 1)
        ->where('totals.0.budgetMinor', 15000)
        ->has('available', 1));

    $this->actingAsMember('acme', ['projects.view']);
    $this->get(pgUrl("programmes/{$programme->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('totals', null)
        ->where('projects.0.budgetMinor', null)
        ->where('projects.0.actualMinor', null)
        ->where('canManage', false));
});

it('leaves a cancelled project\'s budget out of the total', function () {
    $programme = pgProgramme();
    pgProject('wash', ['budget' => '100', 'programme_id' => $programme->id]);
    $cancelled = pgProject('sani', ['budget' => '50', 'programme_id' => $programme->id]);
    $this->post(pgUrl("projects/{$cancelled->id}/status"), ['status' => Project::CANCELLED])->assertSessionHasNoErrors();

    $this->get(pgUrl("programmes/{$programme->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('totals.0.budgetMinor', 10000)
        ->where('totals.0.cancelledActualMinor', 0)
        ->where('projects.0.budgetMinor', 5000));
});

it('shows the programme on the project, and moves it through the form', function () {
    $first = pgProgramme('water');
    $second = pgProgramme('health');
    $project = pgProject('wash', ['programme_id' => $first->id]);

    $this->get(pgUrl("projects/{$project->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('project.programme', 'WATER · Programme water'));

    $this->put(pgUrl("projects/{$project->id}"), ['code' => 'wash', 'name' => 'Wash', 'programme_id' => $second->id])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => $project->fresh()->programme_id))->toBe($second->id);

    $this->put(pgUrl("projects/{$project->id}"), ['code' => 'wash', 'name' => 'Wash', 'programme_id' => ''])->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => $project->fresh()->programme_id))->toBeNull();
});

it('leaves the programme alone when a save does not mention it', function () {
    $programme = pgProgramme();
    $project = pgProject('wash', ['programme_id' => $programme->id]);

    $this->put(pgUrl("projects/{$project->id}"), ['code' => 'wash', 'name' => 'Renamed'])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => $project->fresh()->programme_id))->toBe($programme->id);
});

it('takes a project out, keeps the address honest, and records it', function () {
    $programme = pgProgramme('water');
    $other = pgProgramme('health');
    $project = pgProject('wash', ['programme_id' => $programme->id]);

    $this->delete(pgUrl("programmes/{$other->id}/projects/{$project->id}"))->assertNotFound();
    $this->delete(pgUrl("programmes/{$programme->id}/projects/{$project->id}"))->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => $project->fresh()->programme_id))->toBeNull()
        ->and(AuditEvent::query()->where('action', 'programme.project_placed')->count())->toBe(2);
});

it('archives only once projects are finished, and then takes no more', function () {
    $programme = pgProgramme();
    $project = pgProject('wash', ['programme_id' => $programme->id]);

    $this->post(pgUrl("programmes/{$programme->id}/archive"))->assertSessionHasErrors('programme');

    $this->post(pgUrl("projects/{$project->id}/status"), ['status' => Project::COMPLETED])->assertSessionHasNoErrors();
    $this->post(pgUrl("programmes/{$programme->id}/archive"))->assertSessionHasNoErrors();

    $other = pgProject('sani');
    $this->post(pgUrl("programmes/{$programme->id}/projects"), ['project_id' => $other->id])->assertSessionHasErrors('programme_id');
    $this->put(pgUrl("projects/{$other->id}"), ['code' => 'sani', 'name' => 'S', 'programme_id' => $programme->id])->assertSessionHasErrors('programme_id');

    // What was already in it stays, and can still be saved.
    $this->put(pgUrl("projects/{$project->id}"), ['code' => 'wash', 'name' => 'W', 'programme_id' => $programme->id])->assertSessionHasNoErrors();
    $this->get(pgUrl("projects/{$project->id}/edit"))->assertInertia(fn (Assert $page) => $page->has('programmes', 1));

    $this->post(pgUrl("programmes/{$programme->id}/restore"))->assertSessionHasNoErrors();
    $this->post(pgUrl("programmes/{$programme->id}/projects"), ['project_id' => $other->id])->assertSessionHasNoErrors();
});

it('appears in search', function () {
    $programme = pgProgramme('water');

    $this->get(pgUrl('search?q=water'))->assertInertia(fn (Assert $page) => $page
        ->where('groups.0.title', 'Programmes')
        ->where('groups.0.results.0.url', "/projects/programmes/{$programme->id}"));
});

it('needs permission', function () {
    $programme = pgProgramme();

    $this->actingAsMember('acme', ['projects.view']);
    $this->post(pgUrl('programmes'), ['code' => 'NOPE', 'name' => 'No'])->assertForbidden();
    $this->put(pgUrl("programmes/{$programme->id}"), ['code' => 'NOPE', 'name' => 'No'])->assertForbidden();
    $this->post(pgUrl("programmes/{$programme->id}/archive"))->assertForbidden();

    $this->actingAsMember('acme', []);
    $this->get(pgUrl('programmes'))->assertForbidden();
    $this->get(pgUrl("programmes/{$programme->id}"))->assertForbidden();
});

it('keeps each organization\'s programmes and projects apart', function () {
    $programme = pgProgramme();
    $project = pgProject('wash');

    $this->organization('other');
    $this->actingAsMember('other', PROGRAMME_LEAD);

    $this->get(pgUrl("programmes/{$programme->id}", 'other'))->assertNotFound();
    $this->post(pgUrl("programmes/{$programme->id}/archive", 'other'))->assertNotFound();
    $this->get(pgUrl('programmes', 'other'))->assertInertia(fn (Assert $page) => $page->has('programmes', 0));

    // Their project can't be pulled in, or ours pointed at theirs.
    test()->post(pgUrl('programmes', 'other'), ['code' => 'THEIRS', 'name' => 'Theirs'])->assertSessionHasNoErrors();
    $theirs = $this->asTenant('other', fn () => Programme::query()->sole());
    $this->post(pgUrl("programmes/{$theirs->id}/projects", 'other'), ['project_id' => $project->id])->assertSessionHasErrors('project_id');
});

it('refuses another organization\'s programme in the database', function () {
    $project = pgProject('wash');

    $this->organization('other');
    $this->actingAsMember('other', PROGRAMME_LEAD);
    test()->post(pgUrl('programmes', 'other'), ['code' => 'THEIRS', 'name' => 'Theirs'])->assertSessionHasNoErrors();
    $theirs = $this->asTenant('other', fn () => Programme::query()->sole());

    expect(fn () => DB::transaction(fn () => DB::table('projects')->where('id', $project->id)->update(['programme_id' => $theirs->id])))
        ->toThrow(QueryException::class, 'projects_programme_id_tenant_fk');
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'PostgreSQL enforces this');

it('leaves task numbers unchanged by a programme', function () {
    $programme = pgProgramme();
    $project = pgProject('wash', ['programme_id' => $programme->id]);
    $this->post(pgUrl("projects/{$project->id}/tasks"), ['title' => 'Drill', 'priority' => 'medium'])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => Task::query()->where('project_id', $project->id)->sole()->number))->toBe('WASH-1');
});
