<?php

use App\Actions\Reminders;
use App\Models\AuditEvent;
use App\Models\Person;
use App\Models\Project;
use App\Models\Space;
use App\Models\Task;
use App\Models\TaskList;
use App\Models\UserNotification;
use App\Models\Workspace;
use App\Support\PortfolioTrends;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Work that belongs to no project: lists and tasks an organization runs day
| to day, which must never leak into a project's numbers.
*/

const WORK_ORGANIZER = ['projects.view', 'projects.manage', 'projects.tasks.manage', 'work.structure.manage'];

beforeEach(function () {
    $this->organization('acme');
    $this->user = $this->actingAsMember('acme', WORK_ORGANIZER);
});

function opsUrl(string $path = '', string $slug = 'acme'): string
{
    return test()->organizationUrl($slug, $path);
}

function opsList(string $name = 'Office admin'): TaskList
{
    test()->post(opsUrl('structure/workspaces'), ['name' => 'Main'])->assertSessionHasNoErrors();
    $workspace = test()->asTenant('acme', fn () => Workspace::query()->latest('created_at')->orderByDesc('id')->firstOrFail());
    test()->post(opsUrl('structure/spaces'), ['workspace_id' => $workspace->id, 'name' => 'Operations'])->assertSessionHasNoErrors();
    $space = test()->asTenant('acme', fn () => Space::query()->where('workspace_id', $workspace->id)->firstOrFail());
    test()->post(opsUrl('structure/lists'), ['space_id' => $space->id, 'name' => $name])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => TaskList::query()->where('name', $name)->firstOrFail());
}

/**
 * @param  array<string, mixed>  $details
 */
function opsTask(TaskList $list, array $details = []): Task
{
    test()->post(opsUrl("lists/{$list->id}/tasks"), ['title' => 'Renew the office lease', 'priority' => 'medium', ...$details])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Task::query()->where('list_id', $list->id)->whereNull('parent_id')->orderByDesc('created_at')->orderByDesc('number')->firstOrFail());
}

function opsProject(string $code = 'wash'): Project
{
    test()->post(opsUrl('projects'), ['code' => $code, 'name' => "Project {$code}"])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Project::query()->where('code', mb_strtoupper($code))->firstOrFail());
}

it('runs a list and its tasks with no project', function () {
    $list = opsList();
    expect($list->project_id)->toBeNull();

    $first = opsTask($list);
    $second = opsTask($list, ['title' => 'Book the annual audit']);

    expect($first->project_id)->toBeNull()
        ->and($first->number)->toBe('WORK-1')
        ->and($second->number)->toBe('WORK-2');

    $this->get(opsUrl("lists/{$list->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/work/list')
        ->where('list.project', null)
        ->where('canAdd', true)
        ->has('columns.0.tasks', 2));

    $this->get(opsUrl("work/tasks/{$first->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/work/task')
        ->where('task.number', 'WORK-1')
        ->where('task.list.name', 'Office admin'));

    $this->get(opsUrl('spaces'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('workspaces.0.spaces.0.lists.0.project', null));
});

it('changes a task and moves it through its list\'s statuses, with a trail', function () {
    $list = opsList();
    $task = opsTask($list);

    $this->put(opsUrl("work/tasks/{$task->id}"), ['title' => 'Renew the lease', 'priority' => 'high', 'due_on' => '2030-01-01'])->assertSessionHasNoErrors();
    $this->post(opsUrl("work/tasks/{$task->id}/move"), ['to' => Task::IN_PROGRESS])->assertSessionHasNoErrors();

    $fresh = $this->asTenant('acme', fn () => $task->fresh());
    expect($fresh->title)->toBe('Renew the lease')
        ->and($fresh->status)->toBe(Task::IN_PROGRESS);

    $this->post(opsUrl("structure/lists/{$list->id}/statuses"), ['name' => 'With landlord', 'category' => Task::REVIEW])->assertSessionHasNoErrors();
    $status = $this->asTenant('acme', fn () => $list->statuses()->firstOrFail());
    $this->post(opsUrl("work/tasks/{$task->id}/move"), ['to' => $status->id])->assertSessionHasNoErrors();

    $fresh = $this->asTenant('acme', fn () => $task->fresh());
    expect($fresh->status)->toBe(Task::REVIEW)
        ->and($fresh->list_status_id)->toBe($status->id);

    $this->post(opsUrl("work/tasks/{$task->id}/move"), ['to' => (string) str()->uuid()])->assertSessionHasErrors('to');
    expect(AuditEvent::query()->where('action', 'work.task_changed')->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', 'work.task_created')->count())->toBe(1);
});

it('keeps the rules on subtasks, and comments and checklists work', function () {
    $task = opsTask(opsList());

    $this->post(opsUrl("work/tasks/{$task->id}/subtasks"), ['title' => 'Call the landlord'])->assertSessionHasNoErrors();
    $subtask = $this->asTenant('acme', fn () => Task::query()->where('parent_id', $task->id)->sole());
    expect($subtask->project_id)->toBeNull()->and($subtask->list_id)->toBe($task->list_id);

    $this->post(opsUrl("work/tasks/{$task->id}/move"), ['to' => Task::DONE])->assertSessionHasErrors('status');
    $this->post(opsUrl("work/tasks/{$subtask->id}/subtasks"), ['title' => 'Too deep'])->assertSessionHasErrors('title');
    $this->post(opsUrl("work/tasks/{$subtask->id}/move"), ['to' => Task::DONE])->assertSessionHasNoErrors();
    $this->post(opsUrl("work/tasks/{$task->id}/move"), ['to' => Task::DONE])->assertSessionHasNoErrors();

    $this->post(opsUrl("work/tasks/{$task->id}/comments"), ['body' => 'Signed copy received.'])->assertSessionHasNoErrors();
    $this->post(opsUrl("work/tasks/{$task->id}/checklist"), ['body' => 'File the copy'])->assertSessionHasNoErrors();
    $item = $this->asTenant('acme', fn () => $task->checklistItems()->sole());
    $this->post(opsUrl("work/tasks/{$task->id}/checklist/{$item->id}/toggle"))->assertSessionHasNoErrors();
    expect($this->asTenant('acme', fn () => $item->fresh()->done_at))->not->toBeNull();

    $this->get(opsUrl("work/tasks/{$task->id}"))->assertInertia(fn (Assert $page) => $page
        ->has('task.comments', 1)->has('task.subtasks', 1)->has('task.checklist', 1));

    $this->delete(opsUrl("work/tasks/{$task->id}/checklist/{$item->id}"))->assertSessionHasNoErrors();
});

it('orders a task only among its own list', function () {
    $one = opsTask(opsList('One'));
    $other = opsTask(opsList('Two'));

    $this->post(opsUrl("work/tasks/{$one->id}/move"), ['to' => Task::IN_PROGRESS])->assertSessionHasNoErrors();
    $this->post(opsUrl("work/tasks/{$other->id}/move"), ['to' => Task::IN_PROGRESS])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => $one->fresh()->position))->toBe(0)
        ->and($this->asTenant('acme', fn () => $other->fresh()->position))->toBe(0);
});

it('moves tasks between lists outside projects only', function () {
    $list = opsList();
    $other = opsList('Facilities');
    $task = opsTask($list);
    $project = opsProject();
    $projectList = $this->asTenant('acme', fn () => TaskList::query()->where('project_id', $project->id)->firstOrFail());

    $save = fn (string $listId) => $this->put(opsUrl("work/tasks/{$task->id}"), ['title' => 'T', 'priority' => 'low', 'list_id' => $listId]);

    $save($projectList->id)->assertSessionHasErrors('list_id');
    $save($other->id)->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => $task->fresh()->list_id))->toBe($other->id);
});

it('archives a list once its work is done, and then refuses changes', function () {
    $list = opsList();
    $task = opsTask($list);

    $this->post(opsUrl("structure/list/{$list->id}/archive"))->assertSessionHasErrors('structure');
    $this->post(opsUrl("work/tasks/{$task->id}/move"), ['to' => Task::DONE])->assertSessionHasNoErrors();
    $this->post(opsUrl("structure/list/{$list->id}/archive"))->assertSessionHasNoErrors();

    $this->post(opsUrl("lists/{$list->id}/tasks"), ['title' => 'Late', 'priority' => 'low'])->assertSessionHasErrors('title');
    $this->post(opsUrl("work/tasks/{$task->id}/move"), ['to' => Task::TODO])->assertSessionHasErrors('status');
    $this->put(opsUrl("work/tasks/{$task->id}"), ['title' => 'Late', 'priority' => 'low'])->assertSessionHasErrors('title');
});

it('keeps the two kinds of task on their own addresses', function () {
    $list = opsList();
    $task = opsTask($list);
    $project = opsProject();
    $this->post(opsUrl("projects/{$project->id}/tasks"), ['title' => 'Drill', 'priority' => 'medium'])->assertSessionHasNoErrors();
    $projectTask = $this->asTenant('acme', fn () => Task::query()->where('project_id', $project->id)->firstOrFail());
    $projectList = $this->asTenant('acme', fn () => TaskList::query()->where('project_id', $project->id)->firstOrFail());

    $this->get(opsUrl("work/tasks/{$projectTask->id}"))->assertNotFound();
    $this->post(opsUrl("work/tasks/{$projectTask->id}/move"), ['to' => Task::DONE])->assertNotFound();
    $this->get(opsUrl("projects/{$project->id}/tasks/{$task->id}"))->assertNotFound();
    $this->post(opsUrl("lists/{$projectList->id}/tasks"), ['title' => 'Wrong door', 'priority' => 'low'])->assertSessionHasErrors('title');
});

it('still gives project tasks their project numbers, and a list a project when asked', function () {
    $project = opsProject();
    $this->post(opsUrl("projects/{$project->id}/tasks"), ['title' => 'Drill', 'priority' => 'medium'])->assertSessionHasNoErrors();

    expect($this->asTenant('acme', fn () => Task::query()->where('project_id', $project->id)->sole()->number))->toBe('WASH-1');
});

it('leaves work outside projects out of portfolio numbers', function () {
    $task = opsTask(opsList());
    $this->post(opsUrl("work/tasks/{$task->id}/move"), ['to' => Task::DONE])->assertSessionHasNoErrors();

    $weeks = $this->asTenant('acme', fn () => PortfolioTrends::completedPerWeek());

    expect(array_sum(array_column($weeks, 'count')))->toBe(0);
});

it('shows up in my work, on the home page and in search', function () {
    $list = opsList();
    $person = $this->asTenant('acme', fn () => Person::forUser($this->user));
    $task = opsTask($list, ['assignee_id' => $person->id, 'title' => 'Renew the lease']);

    $this->get(opsUrl('my-work'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('tasks', 1)->where('tasks.0.id', $task->id)->where('tasks.0.projectId', null));
    $this->get(opsUrl())->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('myTasks', 1)->where('myTasks.0.projectId', null));

    $this->get(opsUrl('search?q=lease'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('groups.0.results.0.url', "/projects/work/tasks/{$task->id}")
        ->where('groups.0.results.0.hint', 'Office admin'));
});

it('sends overdue reminders and opens notifications at the right place', function () {
    $list = opsList();
    $other = $this->actingAsMember('acme', WORK_ORGANIZER);
    $this->get(opsUrl())->assertOk();
    $person = $this->asTenant('acme', fn () => Person::forUser($other));
    $this->actingAs($this->user);

    $task = opsTask($list, ['assignee_id' => $person->id, 'start_on' => '2020-01-01', 'due_on' => '2020-01-02']);
    $note = $this->asTenant('acme', fn () => UserNotification::query()->where('user_id', $other->id)->where('task_id', $task->id)->firstOrFail());
    expect($note->project_id)->toBeNull();

    $this->actingAs($other);
    $this->post(opsUrl("notifications/{$note->id}/open"))->assertRedirectContains("/work/tasks/{$task->id}");

    $this->asTenant('acme', fn () => (new ReflectionMethod(Reminders::class, 'overdueTasks'))->invoke(null));
    expect($this->asTenant('acme', fn () => UserNotification::query()->where('task_id', $task->id)->where('kind', UserNotification::REMINDER)->count()))->toBe(1);
});

it('needs permission', function () {
    $list = opsList();
    $task = opsTask($list);

    $this->actingAsMember('acme', ['projects.view']);
    $this->get(opsUrl("work/tasks/{$task->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    $this->post(opsUrl("lists/{$list->id}/tasks"), ['title' => 'No', 'priority' => 'low'])->assertForbidden();
    $this->post(opsUrl("work/tasks/{$task->id}/move"), ['to' => Task::DONE])->assertForbidden();

    $this->actingAsMember('acme', []);
    $this->get(opsUrl("work/tasks/{$task->id}"))->assertForbidden();
});

it('keeps each organization\'s work to itself', function () {
    $list = opsList();
    $task = opsTask($list);

    $this->organization('other');
    $this->actingAsMember('other', WORK_ORGANIZER);

    $this->get(opsUrl("work/tasks/{$task->id}", 'other'))->assertNotFound();
    $this->post(opsUrl("lists/{$list->id}/tasks", 'other'), ['title' => 'No', 'priority' => 'low'])->assertNotFound();
    $this->post(opsUrl("work/tasks/{$task->id}/move", 'other'), ['to' => Task::DONE])->assertNotFound();
});

it('numbers each organization on its own, and refuses a duplicate number', function () {
    $task = opsTask(opsList());

    expect(fn () => DB::transaction(fn () => DB::table('tasks')->insert([
        'id' => (string) str()->uuid(),
        'organization_id' => $task->organization_id,
        'list_id' => $task->list_id,
        'number' => $task->number,
        'title' => 'Duplicate',
        'status' => Task::TODO,
        'priority' => 'low',
        'position' => 9,
        'created_by' => $task->created_by,
    ])))->toThrow(QueryException::class);
});
