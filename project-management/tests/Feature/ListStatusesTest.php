<?php

use App\Actions\WorkStructure;
use App\Models\AuditEvent;
use App\Models\ListStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskList;
use App\Support\ProjectMetrics;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
| A list's own statuses: names inside the four fixed categories. The category
| stays what progress, health and the done rules go by, so naming things
| differently never changes how a project stands.
*/

const LIST_ORGANIZER = ['projects.view', 'projects.tasks.manage', 'work.structure.manage'];

beforeEach(function () {
    $this->organizer = $this->actingAsMember('acme', LIST_ORGANIZER);
    $this->project = $this->asTenant('acme', fn () => Project::query()->create([
        'code' => 'WASH', 'name' => 'Water', 'status' => Project::ACTIVE, 'currency' => 'USD', 'created_by' => $this->organizer->id,
    ]));
    $this->list = $this->asTenant('acme', fn () => WorkStructure::setUpProject($this->project, $this->organizer));
});

function statusesUrl(string $path = ''): string
{
    return test()->organizationUrl('acme', 'structure/lists/'.test()->list->id.'/statuses'.$path);
}

function addStatus(string $name, string $category): ListStatus
{
    test()->post(statusesUrl(), ['name' => $name, 'category' => $category])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => ListStatus::query()->where('name', $name)->sole());
}

function statusTask(array $details = []): Task
{
    $url = test()->organizationUrl('acme', 'projects/'.test()->project->id.'/tasks');
    test()->post($url, ['title' => 'Task', 'priority' => 'medium', ...$details])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Task::query()->latest('created_at')->orderByDesc('id')->firstOrFail());
}

function statusMove(Task $task, array $to): TestResponse
{
    return test()->post(test()->organizationUrl('acme', 'projects/'.test()->project->id."/tasks/{$task->id}/move"), $to);
}

function statusFresh(Task $task): Task
{
    return test()->asTenant('acme', fn () => Task::query()->whereKey($task->id)->sole());
}

it('adds, renames and audits statuses, with names unique in the list', function () {
    $status = addStatus('Drilling', 'in_progress');

    $this->post(statusesUrl(), ['name' => 'Drilling', 'category' => 'todo'])->assertSessionHasErrors('name');
    $this->post(statusesUrl(), ['name' => 'Odd', 'category' => 'nonsense'])->assertSessionHasErrors('category');
    $this->post(statusesUrl(), ['name' => '  ', 'category' => 'todo'])->assertSessionHasErrors('name');

    $this->put(statusesUrl("/{$status->id}"), ['name' => 'Drilling', 'category' => 'review'])->assertSessionHasNoErrors();
    $this->put(statusesUrl("/{$status->id}"), ['name' => 'Checks', 'category' => 'review'])->assertSessionHasNoErrors();

    $this->asTenant('acme', fn () => expect(ListStatus::query()->sole()->only(['name', 'category']))->toBe(['name' => 'Checks', 'category' => 'review']));
    expect(AuditEvent::query()->whereIn('action', ['work.status_added', 'work.status_changed'])->count())->toBe(3);
});

it('puts new tasks in the list\'s first status of their category', function () {
    addStatus('Later', 'todo');
    $first = addStatus('Planned', 'todo');
    $this->post(statusesUrl("/{$first->id}/move"), ['direction' => 'up'])->assertSessionHasNoErrors();

    $task = statusTask();
    expect($task->status)->toBe(Task::TODO)->and($task->list_status_id)->toBe($first->id);

    $done = statusTask(['status' => 'review']);
    expect($done->list_status_id)->toBeNull();
});

it('moves a task to a named status, whose category becomes its status', function () {
    $planned = addStatus('Planned', 'todo');
    $drilling = addStatus('Drilling', 'in_progress');
    $testing = addStatus('Testing', 'in_progress');
    $task = statusTask();

    statusMove($task, ['list_status_id' => $testing->id])->assertSessionHasNoErrors();
    $moved = statusFresh($task);
    expect($moved->status)->toBe(Task::IN_PROGRESS)->and($moved->list_status_id)->toBe($testing->id);

    // Within a category: the name changes.
    statusMove($task, ['list_status_id' => $drilling->id])->assertSessionHasNoErrors();
    expect(statusFresh($task)->list_status_id)->toBe($drilling->id);

    // By category, as from the board: the same category keeps the name...
    statusMove($task, ['status' => 'in_progress'])->assertSessionHasNoErrors();
    expect(statusFresh($task)->list_status_id)->toBe($drilling->id);

    // ... another takes that category's first, or none.
    statusMove($task, ['status' => 'todo'])->assertSessionHasNoErrors();
    expect(statusFresh($task)->list_status_id)->toBe($planned->id);
    statusMove($task, ['status' => 'review'])->assertSessionHasNoErrors();
    expect(statusFresh($task)->list_status_id)->toBeNull();

    statusMove($task, [])->assertSessionHasErrors('status');
});

it('applies the done rules to a named status too', function () {
    $finished = addStatus('Commissioned', 'done');
    $task = statusTask();
    $blocker = statusTask(['title' => 'Blocker']);
    $this->post($this->organizationUrl('acme', "projects/{$this->project->id}/tasks/{$task->id}/dependencies"), ['depends_on_id' => $blocker->id])->assertSessionHasNoErrors();

    statusMove($task, ['list_status_id' => $finished->id])->assertSessionHasErrors('status');
    expect(statusFresh($task)->status)->toBe(Task::TODO);

    statusMove($blocker, ['status' => 'done'])->assertSessionHasNoErrors();
    statusMove($task, ['list_status_id' => $finished->id])->assertSessionHasNoErrors();
    $done = statusFresh($task);
    expect($done->status)->toBe(Task::DONE)->and($done->completed_at)->not->toBeNull();
});

it('counts progress by category whatever the list calls it', function () {
    $finished = addStatus('Commissioned', 'done');
    $task = statusTask();
    statusTask();
    statusMove($task, ['list_status_id' => $finished->id])->assertSessionHasNoErrors();

    $metrics = $this->asTenant('acme', fn () => ProjectMetrics::for($this->project));
    expect($metrics['progress'])->toBe(50)->and($metrics['tasksDone'])->toBe(1);
});

it('refuses another list\'s status', function () {
    $task = statusTask();
    $other = $this->asTenant('acme', fn () => TaskList::query()->create([
        'space_id' => $this->list->space_id, 'project_id' => $this->project->id, 'name' => 'Second',
    ]));
    $foreign = $this->asTenant('acme', fn () => ListStatus::query()->create([
        'list_id' => $other->id, 'name' => 'Theirs', 'category' => 'done', 'created_by' => $this->organizer->id,
    ]));

    statusMove($task, ['list_status_id' => $foreign->id])->assertSessionHasErrors('list_status_id');
    expect(statusFresh($task)->status)->toBe(Task::TODO);

    // Reached through another list: unknown.
    $this->put(statusesUrl("/{$foreign->id}"), ['name' => 'Mine', 'category' => 'todo'])->assertNotFound();
    $this->delete(statusesUrl("/{$foreign->id}"))->assertNotFound();
});

it('maps tasks to the same category when they move to another list', function () {
    addStatus('Drilling', 'in_progress');
    $parent = statusTask(['status' => 'in_progress']);
    $this->post($this->organizationUrl('acme', "projects/{$this->project->id}/tasks/{$parent->id}/subtasks"), ['title' => 'Sub'])->assertSessionHasNoErrors();
    $other = $this->asTenant('acme', fn () => TaskList::query()->create([
        'space_id' => $this->list->space_id, 'project_id' => $this->project->id, 'name' => 'Second',
    ]));
    $theirs = $this->asTenant('acme', fn () => ListStatus::query()->create([
        'list_id' => $other->id, 'name' => 'Underway', 'category' => 'in_progress', 'created_by' => $this->organizer->id,
    ]));

    $this->put($this->organizationUrl('acme', "projects/{$this->project->id}/tasks/{$parent->id}"), ['title' => 'Task', 'priority' => 'medium', 'list_id' => $other->id])->assertSessionHasNoErrors();

    $moved = statusFresh($parent);
    expect($moved->list_id)->toBe($other->id)->and($moved->list_status_id)->toBe($theirs->id)->and($moved->status)->toBe(Task::IN_PROGRESS);

    // The subtask is to do: nothing named for that in the new list.
    $subtask = $this->asTenant('acme', fn () => Task::query()->where('parent_id', $parent->id)->sole());
    expect($subtask->list_id)->toBe($other->id)->and($subtask->list_status_id)->toBeNull();
});

it('keeps a status with tasks in its category, and keeps the tasks when it is removed', function () {
    $status = addStatus('Drilling', 'in_progress');
    $task = statusTask(['status' => 'in_progress']);

    $this->put(statusesUrl("/{$status->id}"), ['name' => 'Drilling', 'category' => 'done'])->assertSessionHasErrors('category');

    $this->delete(statusesUrl("/{$status->id}"))->assertSessionHasNoErrors();

    $kept = statusFresh($task);
    expect($kept->status)->toBe(Task::IN_PROGRESS)->and($kept->list_status_id)->toBeNull();
    expect(AuditEvent::query()->where('action', 'work.status_removed')->exists())->toBeTrue();
});

it('reorders only within a category, and stops at the ends', function () {
    $a = addStatus('A', 'in_progress');
    addStatus('Other', 'todo');
    $b = addStatus('B', 'in_progress');

    $this->post(statusesUrl("/{$a->id}/move"), ['direction' => 'up'])->assertSessionHasNoErrors();
    $this->post(statusesUrl("/{$a->id}/move"), ['direction' => 'down'])->assertSessionHasNoErrors();
    $this->post(statusesUrl("/{$a->id}/move"), ['direction' => 'sideways'])->assertSessionHasErrors('direction');

    $this->asTenant('acme', fn () => expect(ListStatus::firstFor($this->list->id, 'in_progress')->id)->toBe($b->id));
});

it('shows named statuses on the list page, the task page and the board', function () {
    $drilling = addStatus('Drilling', 'in_progress');
    addStatus('Testing', 'in_progress');
    $task = statusTask(['status' => 'in_progress']);
    statusTask();

    $this->get($this->organizationUrl('acme', "lists/{$this->list->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/work/list')
        ->where('canManageStatuses', true)
        ->has('statuses', 2)
        ->where('statuses.0.tasks', 1)
        // To do (no name), Drilling, Testing, In review, Done.
        ->has('columns', 5)
        ->where('columns.1.label', 'Drilling')
        ->has('columns.1.tasks', 1)
        ->where('columns.2.label', 'Testing'));

    $this->get($this->organizationUrl('acme', "projects/{$this->project->id}/tasks/{$task->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('statusValue', $drilling->id)
        ->where('task.listStatus', 'Drilling')
        // To do, Drilling, Testing, In review, Done.
        ->has('statusOptions', 5)
        ->where('statusOptions.1.label', 'Drilling'));

    // The project board keeps its four columns.
    $this->get($this->organizationUrl('acme', "projects/{$this->project->id}/board"))->assertInertia(fn (Assert $page) => $page
        ->has('columns', 4)
        ->where('columns.1.tasks.0.listStatus', 'Drilling'));
});

it('keeps a list without statuses exactly as it was', function () {
    $task = statusTask();

    $this->get($this->organizationUrl('acme', "lists/{$this->list->id}"))->assertInertia(fn (Assert $page) => $page
        ->has('statuses', 0)
        ->has('columns', 4));
    $this->get($this->organizationUrl('acme', "projects/{$this->project->id}/tasks/{$task->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('statusValue', 'todo')
        ->has('statusOptions', 4));
});

it('lets only organizers change statuses, not in an archived list, and keeps other organizations out', function () {
    $status = addStatus('Drilling', 'in_progress');

    $this->actingAsMember('acme', ['projects.view', 'projects.tasks.manage']);
    $this->get($this->organizationUrl('acme', "lists/{$this->list->id}"))->assertInertia(fn (Assert $page) => $page->where('canManageStatuses', false));
    $this->post(statusesUrl(), ['name' => 'No', 'category' => 'todo'])->assertForbidden();
    $this->put(statusesUrl("/{$status->id}"), ['name' => 'No', 'category' => 'todo'])->assertForbidden();
    $this->post(statusesUrl("/{$status->id}/move"), ['direction' => 'up'])->assertForbidden();
    $this->delete(statusesUrl("/{$status->id}"))->assertForbidden();

    $this->actingAsMember('other', LIST_ORGANIZER);
    $this->post(statusesUrl(), ['name' => 'No', 'category' => 'todo'])->assertForbidden();
    $this->post($this->organizationUrl('other', "structure/lists/{$this->list->id}/statuses"), ['name' => 'No', 'category' => 'todo'])->assertNotFound();

    $this->actingAs($this->organizer);
    $this->asTenant('acme', fn () => $this->list->forceFill(['archived_at' => now()])->save());
    $this->post(statusesUrl(), ['name' => 'Late', 'category' => 'todo'])->assertSessionHasErrors('name');
    $this->delete(statusesUrl("/{$status->id}"))->assertSessionHasErrors('name');
});
