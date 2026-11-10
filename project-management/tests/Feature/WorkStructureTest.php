<?php

use App\Models\AuditEvent;
use App\Models\Folder;
use App\Models\Project;
use App\Models\Space;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The work structure: Workspace → Space → (Folder) → List → Task. Every
| project gets a list; tasks live in lists of their own project; parts are
| archived, never deleted, and only once nothing open is left in them.
*/

const ORGANIZER = ['projects.view', 'projects.manage', 'projects.tasks.manage', 'work.structure.manage'];

function wsUrl(string $path = '', string $slug = 'acme'): string
{
    return test()->organizationUrl($slug, $path);
}

function wsProject(string $code = 'wash'): Project
{
    test()->post(wsUrl('projects'), ['code' => $code, 'name' => "Project {$code}"])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Project::query()->where('code', mb_strtoupper($code))->firstOrFail());
}

/**
 * @param  array<string, mixed>  $details
 */
function wsTask(Project $project, array $details = []): Task
{
    test()->post(wsUrl("projects/{$project->id}/tasks"), ['title' => 'A task', 'priority' => 'medium', ...$details])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Task::query()->where('project_id', $project->id)->latest('created_at')->orderByDesc('id')->firstOrFail());
}

/**
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function inAcme(callable $callback): mixed
{
    return test()->asTenant('acme', $callback);
}

it('gives every new project a folder and a list, and puts its tasks there', function () {
    $this->actingAsMember('acme', ORGANIZER);

    $wash = wsProject('wash');
    $edu = wsProject('edu');
    $task = wsTask($wash);

    inAcme(function () use ($wash, $edu, $task) {
        // One "Main" workspace and "Projects" space, made with the first project.
        expect(Workspace::query()->pluck('name')->all())->toBe(['Main'])
            ->and(Space::query()->pluck('name')->all())->toBe(['Projects'])
            ->and(Folder::query()->orderBy('position')->pluck('name')->all())->toBe(['WASH · Project wash', 'EDU · Project edu'])
            ->and($wash->lists()->pluck('name')->all())->toBe(['Tasks'])
            ->and($edu->lists()->count())->toBe(1)
            ->and($task->list_id)->toBe($wash->lists()->value('id'));
    });

    $this->get(wsUrl('spaces'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/work/spaces')
        ->where('canManage', true)
        ->where('workspaces.0.name', 'Main')
        ->where('workspaces.0.spaces.0.folders.0.lists.0.project', 'WASH')
        ->where('workspaces.0.spaces.0.folders.0.lists.0.tasks', 1)
        ->where('workspaces.0.spaces.0.folders.0.lists.0.open', 1));
});

it('organizes work: workspaces, spaces, folders and lists, renamed and audited', function () {
    $this->actingAsMember('acme', ORGANIZER);
    $wash = wsProject('wash');

    $this->post(wsUrl('structure/workspaces'), ['name' => 'Juba office'])->assertSessionHasNoErrors();
    $workspace = inAcme(fn () => Workspace::query()->where('name', 'Juba office')->firstOrFail());
    $this->post(wsUrl('structure/spaces'), ['workspace_id' => $workspace->id, 'name' => 'Water'])->assertSessionHasNoErrors();
    $space = inAcme(fn () => Space::query()->where('name', 'Water')->firstOrFail());
    $this->post(wsUrl('structure/folders'), ['space_id' => $space->id, 'name' => 'Boreholes'])->assertSessionHasNoErrors();
    $folder = inAcme(fn () => Folder::query()->where('name', 'Boreholes')->firstOrFail());

    // A list in a folder, and one straight in the space.
    $this->post(wsUrl('structure/lists'), ['space_id' => $space->id, 'folder_id' => $folder->id, 'project_id' => $wash->id, 'name' => 'Drilling'])->assertSessionHasNoErrors();
    $this->post(wsUrl('structure/lists'), ['space_id' => $space->id, 'project_id' => $wash->id, 'name' => 'Hygiene'])->assertSessionHasNoErrors();
    $this->post(wsUrl('structure/lists'), ['space_id' => $space->id, 'project_id' => $wash->id, 'name' => ''])->assertSessionHasErrors('name');

    $this->put(wsUrl("structure/folder/{$folder->id}"), ['name' => 'Boreholes 2027'])->assertSessionHasNoErrors();
    expect(inAcme(fn () => $folder->fresh()?->name))->toBe('Boreholes 2027');

    $this->get(wsUrl('spaces'))->assertInertia(fn (Assert $page) => $page
        ->where('workspaces.1.name', 'Juba office')
        ->where('workspaces.1.spaces.0.folders.0.lists.0.name', 'Drilling')
        ->where('workspaces.1.spaces.0.lists.0.name', 'Hygiene'));

    $actions = AuditEvent::query()->where('action', 'like', 'work.%')->pluck('action')->all();
    expect($actions)->toContain('work.workspace_created', 'work.space_created', 'work.folder_created', 'work.list_created', 'work.folder_renamed');
});

it('adds tasks to a chosen list of the project, and moves them only between its lists', function () {
    $this->actingAsMember('acme', ORGANIZER);
    $wash = wsProject('wash');
    $edu = wsProject('edu');
    $default = inAcme(fn () => $wash->lists()->firstOrFail());
    $space = inAcme(fn () => Space::query()->firstOrFail());
    $this->post(wsUrl('structure/lists'), ['space_id' => $space->id, 'project_id' => $wash->id, 'name' => 'Procurement'])->assertSessionHasNoErrors();
    $procurement = inAcme(fn () => TaskList::query()->where('name', 'Procurement')->firstOrFail());
    $eduList = inAcme(fn () => $edu->lists()->firstOrFail());

    $task = wsTask($wash, ['list_id' => $procurement->id]);
    expect($task->list_id)->toBe($procurement->id)->and($task->number)->toBe('WASH-1');

    // Another project's list is refused, when adding and when moving.
    $this->post(wsUrl("projects/{$wash->id}/tasks"), ['title' => 'X', 'priority' => 'medium', 'list_id' => $eduList->id])->assertSessionHasErrors('list_id');
    $this->put(wsUrl("projects/{$wash->id}/tasks/{$task->id}"), ['title' => 'A task', 'priority' => 'medium', 'list_id' => $eduList->id])->assertSessionHasErrors('list_id');
    expect(inAcme(fn () => $task->fresh()?->list_id))->toBe($procurement->id);

    $this->put(wsUrl("projects/{$wash->id}/tasks/{$task->id}"), ['title' => 'A task', 'priority' => 'medium', 'list_id' => $default->id])->assertSessionHasNoErrors();
    expect(inAcme(fn () => $task->fresh()?->list_id))->toBe($default->id)
        ->and(AuditEvent::query()->where('action', 'work.task_moved')->exists())->toBeTrue();

    $this->get(wsUrl("projects/{$wash->id}/tasks/{$task->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('task.list', ['id' => $default->id, 'name' => 'Tasks'])
        ->has('lists', 2));

    // The list page shows its tasks by status.
    $this->get(wsUrl("lists/{$default->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/work/list')
        ->where('list.project.code', 'WASH')
        ->where('list.path', ['Main', 'Projects', 'WASH · Project wash'])
        ->where('columns.0.status', Task::TODO)
        ->where('columns.0.tasks.0.number', 'WASH-1')
        ->where('canAdd', true));
});

it('archives only what has nothing open left, and never a project\'s last list', function () {
    $this->actingAsMember('acme', ORGANIZER);
    $wash = wsProject('wash');
    $default = inAcme(fn () => $wash->lists()->firstOrFail());
    $folder = inAcme(fn () => Folder::query()->firstOrFail());
    $space = inAcme(fn () => Space::query()->firstOrFail());
    $task = wsTask($wash);

    $this->post(wsUrl("structure/list/{$default->id}/archive"))->assertSessionHasErrors(['structure' => '1 task in it is not done: move or finish it first.']);
    $this->post(wsUrl("structure/folder/{$folder->id}/archive"))->assertSessionHasErrors(['structure' => 'Archive its lists first.']);
    $this->post(wsUrl("structure/space/{$space->id}/archive"))->assertSessionHasErrors('structure');

    $this->post(wsUrl("projects/{$wash->id}/tasks/{$task->id}/move"), ['status' => Task::DONE])->assertSessionHasNoErrors();
    $this->post(wsUrl("structure/list/{$default->id}/archive"))->assertSessionHasErrors(['structure' => "It is WASH's only list."]);

    $this->post(wsUrl('structure/lists'), ['space_id' => $space->id, 'project_id' => $wash->id, 'name' => 'Phase 2'])->assertSessionHasNoErrors();
    $this->post(wsUrl("structure/list/{$default->id}/archive"))->assertSessionHasNoErrors();
    expect(inAcme(fn () => $default->fresh()?->archived_at))->not->toBeNull();

    // Archived: kept, with its tasks, but out of the tree and closed to new work.
    expect(inAcme(fn () => Task::query()->whereKey($task->id)->value('list_id')))->toBe($default->id);
    $this->get(wsUrl('spaces'))->assertInertia(fn (Assert $page) => $page
        ->where('workspaces.0.spaces.0.folders.0.lists', [])
        ->where('workspaces.0.spaces.0.lists.0.name', 'Phase 2'));
    $this->post(wsUrl("projects/{$wash->id}/tasks"), ['title' => 'X', 'priority' => 'medium', 'list_id' => $default->id])->assertSessionHasErrors('list_id');
    $this->get(wsUrl("lists/{$default->id}"))->assertInertia(fn (Assert $page) => $page->where('list.archived', true)->where('canAdd', false));

    // A new task without a list goes to the project's first active list.
    expect(wsTask($wash)->list_id)->toBe(inAcme(fn () => TaskList::query()->where('name', 'Phase 2')->value('id')));

    // Nothing can be added inside an archived part.
    $this->post(wsUrl("structure/folder/{$folder->id}/archive"))->assertSessionHasNoErrors();
    $this->post(wsUrl('structure/lists'), ['space_id' => $space->id, 'folder_id' => $folder->id, 'project_id' => $wash->id, 'name' => 'Late'])
        ->assertSessionHasErrors(['structure' => 'That folder is archived.']);
});

it('lets viewers see the structure but not change it', function () {
    $this->actingAsMember('acme', ORGANIZER);
    $wash = wsProject('wash');
    $list = inAcme(fn () => $wash->lists()->firstOrFail());
    $workspace = inAcme(fn () => Workspace::query()->firstOrFail());

    $this->actingAsMember('acme', ['projects.view']);
    $this->get(wsUrl('spaces'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    $this->get(wsUrl("lists/{$list->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canAdd', false));
    $this->post(wsUrl('structure/workspaces'), ['name' => 'Mine'])->assertForbidden();
    $this->put(wsUrl("structure/workspace/{$workspace->id}"), ['name' => 'Mine'])->assertForbidden();
    $this->post(wsUrl("structure/list/{$list->id}/archive"))->assertForbidden();

    $this->actingAsMember('acme', []);
    $this->get(wsUrl('spaces'))->assertForbidden();
});

it('keeps each organization\'s structure to itself', function () {
    $owner = $this->actingAsMember('acme', ORGANIZER);
    $wash = wsProject('wash');
    $list = inAcme(fn () => $wash->lists()->firstOrFail());
    $space = inAcme(fn () => Space::query()->firstOrFail());

    $this->actingAsMember('globex', ORGANIZER);
    $this->get(wsUrl("lists/{$list->id}", 'globex'))->assertNotFound();
    $this->put(wsUrl("structure/list/{$list->id}", 'globex'), ['name' => 'Taken'])->assertNotFound();
    $this->post(wsUrl("structure/space/{$space->id}/archive", 'globex'))->assertNotFound();
    $this->post(wsUrl('structure/folders', 'globex'), ['space_id' => $space->id, 'name' => 'In'])->assertNotFound();
    $this->get(wsUrl('spaces', 'globex'))->assertInertia(fn (Assert $page) => $page->where('workspaces', []));
    expect(inAcme(fn () => $list->fresh()?->name))->toBe('Tasks');
    expect($owner)->toBeInstanceOf(User::class);
});
