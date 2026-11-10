<?php

use App\Actions\WorkStructure;
use App\Models\AuditEvent;
use App\Models\Person;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskChecklistItem;
use App\Support\ProjectMetrics;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| What sits inside a task: subtasks (one level deep, holding their parent
| back), collaborators besides the lead, a checklist, and files on the
| private disk. Everything stays inside its project and organization.
*/

const TASK_WORKER = ['projects.view', 'projects.tasks.manage'];

beforeEach(function () {
    $this->worker = $this->actingAsMember('acme', TASK_WORKER);
});

function partsUrl(string $path = '', string $slug = 'acme'): string
{
    return test()->organizationUrl($slug, $path);
}

function partsProject(string $code = 'wash', string $slug = 'acme'): Project
{
    return test()->asTenant($slug, function () use ($code) {
        $project = Project::query()->create([
            'code' => mb_strtoupper($code),
            'name' => "Project {$code}",
            'status' => Project::ACTIVE,
            'currency' => 'USD',
            'created_by' => test()->worker->id,
        ]);

        return $project;
    });
}

function partsTask(Project $project, array $details = []): Task
{
    test()->post(partsUrl("projects/{$project->id}/tasks"), ['title' => 'Main task', 'priority' => 'high', ...$details])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Task::query()->where('project_id', $project->id)->latest('created_at')->orderByDesc('id')->firstOrFail());
}

function partsSubtask(Project $project, Task $parent, array $details = []): Task
{
    test()->post(partsUrl("projects/{$project->id}/tasks/{$parent->id}/subtasks"), ['title' => 'A subtask', ...$details])->assertSessionHasNoErrors();

    return test()->asTenant('acme', fn () => Task::query()->where('parent_id', $parent->id)->latest('created_at')->orderByDesc('id')->firstOrFail());
}

function partsPerson(string $name = 'Ayen', string $slug = 'acme'): Person
{
    return test()->asTenant($slug, fn () => Person::query()->create(['name' => $name]));
}

it('adds subtasks in the parent\'s list and milestone, numbered like any task', function () {
    $project = partsProject();
    $parent = partsTask($project);
    $subtask = partsSubtask($project, $parent);

    expect($subtask->number)->toBe('WASH-2')
        ->and($subtask->list_id)->toBe($parent->list_id)
        ->and($subtask->priority)->toBe($parent->priority);

    $this->get(partsUrl("projects/{$project->id}/tasks/{$parent->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('portal/projects/task')
        ->where('task.subtaskList.0.number', 'WASH-2')
        ->where('task.parent', null));
    $this->get(partsUrl("projects/{$project->id}/tasks/{$subtask->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('task.parent.number', 'WASH-1'));
});

it('goes only one level deep, and not under a finished task', function () {
    $project = partsProject();
    $parent = partsTask($project);
    $subtask = partsSubtask($project, $parent);

    $this->post(partsUrl("projects/{$project->id}/tasks/{$subtask->id}/subtasks"), ['title' => 'Too deep'])->assertSessionHasErrors('title');

    $this->post(partsUrl("projects/{$project->id}/tasks/{$subtask->id}/move"), ['status' => 'done'])->assertSessionHasNoErrors();
    $this->post(partsUrl("projects/{$project->id}/tasks/{$parent->id}/move"), ['status' => 'done'])->assertSessionHasNoErrors();
    $this->post(partsUrl("projects/{$project->id}/tasks/{$parent->id}/subtasks"), ['title' => 'Late'])->assertSessionHasErrors('title');
});

it('holds a parent back until its subtasks are done, and a subtask under a finished parent', function () {
    $project = partsProject();
    $parent = partsTask($project);
    $subtask = partsSubtask($project, $parent);

    $this->post(partsUrl("projects/{$project->id}/tasks/{$parent->id}/move"), ['status' => 'done'])->assertSessionHasErrors('status');
    $this->asTenant('acme', fn () => expect(Task::query()->whereKey($parent->id)->sole()->status)->toBe(Task::TODO));

    $this->post(partsUrl("projects/{$project->id}/tasks/{$subtask->id}/move"), ['status' => 'done'])->assertSessionHasNoErrors();
    $this->post(partsUrl("projects/{$project->id}/tasks/{$parent->id}/move"), ['status' => 'done'])->assertSessionHasNoErrors();

    $this->post(partsUrl("projects/{$project->id}/tasks/{$subtask->id}/move"), ['status' => 'todo'])->assertSessionHasErrors('status');
    $this->post(partsUrl("projects/{$project->id}/tasks/{$parent->id}/move"), ['status' => 'todo'])->assertSessionHasNoErrors();
    $this->post(partsUrl("projects/{$project->id}/tasks/{$subtask->id}/move"), ['status' => 'todo'])->assertSessionHasNoErrors();
});

it('shows top-level tasks on the board with subtasks counted, and finds subtasks when searching', function () {
    $project = partsProject();
    $parent = partsTask($project);
    $person = partsPerson();
    $subtask = partsSubtask($project, $parent, ['title' => 'Buy chlorine', 'assignee_id' => $person->id]);
    $this->post(partsUrl("projects/{$project->id}/tasks/{$subtask->id}/move"), ['status' => 'done'])->assertSessionHasNoErrors();
    partsSubtask($project, $parent, ['title' => 'Test the water']);

    $this->get(partsUrl("projects/{$project->id}/tasks"))->assertInertia(fn (Assert $page) => $page
        ->has('tasks', 1)
        ->where('tasks.0.number', 'WASH-1')
        ->where('tasks.0.subtasks', ['total' => 2, 'done' => 1]));
    $this->get(partsUrl("projects/{$project->id}/board"))->assertInertia(fn (Assert $page) => $page
        ->has('columns.0.tasks', 1)
        ->has('columns.3.tasks', 0));
    $this->get(partsUrl("projects/{$project->id}/timeline"))->assertOk();

    $this->get(partsUrl("projects/{$project->id}/tasks?search=chlorine"))->assertInertia(fn (Assert $page) => $page
        ->has('tasks', 1)->where('tasks.0.isSubtask', true));
    $this->get(partsUrl("projects/{$project->id}/tasks?assignee={$person->id}"))->assertInertia(fn (Assert $page) => $page
        ->has('tasks', 1)->where('tasks.0.title', 'Buy chlorine'));
});

it('counts progress over top-level tasks, and overdue subtasks as overdue', function () {
    $project = partsProject();
    $first = partsTask($project, ['estimate_hours' => 10]);
    partsTask($project, ['estimate_hours' => 10]);
    $subtask = partsSubtask($project, $first, ['due_on' => today()->subDays(3)->toDateString()]);
    $this->asTenant('acme', fn () => $subtask->forceFill(['estimate_hours' => 100])->save());

    $metrics = $this->asTenant('acme', fn () => ProjectMetrics::for($project));
    expect($metrics['tasks'])->toBe(2)->and($metrics['progress'])->toBe(0)->and($metrics['overdueTasks'])->toBe(1);

    $this->post(partsUrl("projects/{$project->id}/tasks/{$subtask->id}/move"), ['status' => 'done'])->assertSessionHasNoErrors();
    $this->post(partsUrl("projects/{$project->id}/tasks/{$first->id}/move"), ['status' => 'done'])->assertSessionHasNoErrors();

    $metrics = $this->asTenant('acme', fn () => ProjectMetrics::for($project));
    expect($metrics['progress'])->toBe(50)->and($metrics['tasksDone'])->toBe(1);
});

it('keeps a subtask in its parent\'s list, and moves it with the parent', function () {
    $this->worker = $this->actingAsMember('acme', [...TASK_WORKER, 'work.structure.manage']);
    $project = partsProject('edu');
    $parent = partsTask($project);
    $subtask = partsSubtask($project, $parent);
    $other = $this->asTenant('acme', function () use ($project, $parent) {
        return WorkStructure::createList($parent->list->space, $parent->list->folder, $project, 'Second', $this->worker);
    });
    $form = ['title' => 'Sub', 'priority' => 'high', 'list_id' => $other->id];

    $this->put(partsUrl("projects/{$project->id}/tasks/{$subtask->id}"), $form)->assertSessionHasErrors('list_id');
    $this->put(partsUrl("projects/{$project->id}/tasks/{$parent->id}"), ['title' => 'Main', 'priority' => 'high', 'list_id' => $other->id])->assertSessionHasNoErrors();

    $this->asTenant('acme', fn () => expect(Task::query()->whereKey($subtask->id)->sole()->list_id)->toBe($other->id));
});

it('adds collaborators, who see the task in My work; the lead is not one too', function () {
    $project = partsProject();
    // Opening the app makes their person.
    $this->get(partsUrl('my-work'))->assertOk();
    $me = $this->asTenant('acme', fn () => Person::forUser($this->worker));
    $lead = partsPerson('Lead');
    $task = partsTask($project, ['assignee_id' => $lead->id]);

    $this->post(partsUrl("projects/{$project->id}/tasks/{$task->id}/collaborators"), ['person_id' => $lead->id])->assertSessionHasErrors('person_id');
    $this->post(partsUrl("projects/{$project->id}/tasks/{$task->id}/collaborators"), ['person_id' => $me->id])->assertSessionHasNoErrors();
    $this->post(partsUrl("projects/{$project->id}/tasks/{$task->id}/collaborators"), ['person_id' => $me->id])->assertSessionHasNoErrors();

    $this->get(partsUrl("projects/{$project->id}/tasks/{$task->id}"))->assertInertia(fn (Assert $page) => $page->has('task.collaborators', 1));
    $this->get(partsUrl('my-work'))->assertInertia(fn (Assert $page) => $page->has('tasks', 1)->where('tasks.0.id', $task->id));
    $this->get(partsUrl("projects/{$project->id}/tasks?assignee={$me->id}"))->assertInertia(fn (Assert $page) => $page->has('tasks', 1));

    // Made the lead: no longer also a collaborator.
    $this->put(partsUrl("projects/{$project->id}/tasks/{$task->id}"), ['title' => 'Main', 'priority' => 'high', 'assignee_id' => $me->id])->assertSessionHasNoErrors();
    $this->get(partsUrl("projects/{$project->id}/tasks/{$task->id}"))->assertInertia(fn (Assert $page) => $page->has('task.collaborators', 0));

    $this->post(partsUrl("projects/{$project->id}/tasks/{$task->id}/collaborators"), ['person_id' => $lead->id])->assertSessionHasNoErrors();
    $this->delete(partsUrl("projects/{$project->id}/tasks/{$task->id}/collaborators/{$lead->id}"))->assertSessionHasNoErrors();
    $this->get(partsUrl("projects/{$project->id}/tasks/{$task->id}"))->assertInertia(fn (Assert $page) => $page->has('task.collaborators', 0));
});

it('ticks off a checklist, which does not hold the task back', function () {
    $project = partsProject();
    $task = partsTask($project);

    $this->post(partsUrl("projects/{$project->id}/tasks/{$task->id}/checklist"), ['body' => ''])->assertSessionHasErrors('body');
    $this->post(partsUrl("projects/{$project->id}/tasks/{$task->id}/checklist"), ['body' => 'Check the seal'])->assertSessionHasNoErrors();
    $this->post(partsUrl("projects/{$project->id}/tasks/{$task->id}/checklist"), ['body' => 'Sign the sheet'])->assertSessionHasNoErrors();
    $item = $this->asTenant('acme', fn () => TaskChecklistItem::query()->where('body', 'Check the seal')->sole());

    $this->post(partsUrl("projects/{$project->id}/tasks/{$task->id}/checklist/{$item->id}/toggle"))->assertSessionHasNoErrors();
    $this->get(partsUrl("projects/{$project->id}/tasks/{$task->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('task.checklistItems.0.done', true)
        ->where('task.checklistItems.0.doneBy', $this->worker->name)
        ->where('task.checklistItems.1.done', false));
    $this->get(partsUrl("projects/{$project->id}/tasks"))->assertInertia(fn (Assert $page) => $page->where('tasks.0.checklist', ['total' => 2, 'done' => 1]));

    $this->post(partsUrl("projects/{$project->id}/tasks/{$task->id}/checklist/{$item->id}/toggle"))->assertSessionHasNoErrors();
    $this->asTenant('acme', fn () => expect(TaskChecklistItem::query()->whereKey($item->id)->sole()->done_by)->toBeNull());

    $this->post(partsUrl("projects/{$project->id}/tasks/{$task->id}/move"), ['status' => 'done'])->assertSessionHasNoErrors();

    $this->delete(partsUrl("projects/{$project->id}/tasks/{$task->id}/checklist/{$item->id}"))->assertSessionHasNoErrors();
    $this->asTenant('acme', fn () => expect(TaskChecklistItem::query()->count())->toBe(1));
});

it('keeps files on the private disk, audited, removable by the uploader or a manager', function () {
    Storage::fake('local');
    $project = partsProject();
    $task = partsTask($project);
    $url = partsUrl("projects/{$project->id}/tasks/{$task->id}/attachments");

    $this->post($url, ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrors('file');
    $this->post($url, ['file' => UploadedFile::fake()->create('big.pdf', 20000, 'application/pdf')])->assertSessionHasErrors('file');
    $this->post($url, ['file' => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf')])->assertSessionHasNoErrors();

    $file = $this->asTenant('acme', fn () => TaskAttachment::query()->sole());
    Storage::disk('local')->assertExists($file->path);
    expect($file->path)->toContain("organizations/{$file->organization_id}/tasks/{$task->id}")
        ->and(AuditEvent::query()->where('action', 'task.file_attached')->exists())->toBeTrue();

    $this->get("{$url}/{$file->id}")->assertOk()->assertDownload('report.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');

    // A viewer can read it but not remove it; another worker cannot remove it either.
    $this->actingAsMember('acme', ['projects.view']);
    $this->get("{$url}/{$file->id}")->assertOk();
    $this->delete("{$url}/{$file->id}")->assertForbidden();
    $this->actingAsMember('acme', TASK_WORKER);
    $this->delete("{$url}/{$file->id}")->assertForbidden();

    $this->actingAs($this->worker);
    $this->delete("{$url}/{$file->id}")->assertSessionHasNoErrors();
    Storage::disk('local')->assertMissing($file->path);
    expect(AuditEvent::query()->where('action', 'task.file_removed')->exists())->toBeTrue();
});

it('lets viewers see all of it but change none of it', function () {
    Storage::fake('local');
    $project = partsProject();
    $task = partsTask($project);
    $person = partsPerson();
    $this->post(partsUrl("projects/{$project->id}/tasks/{$task->id}/checklist"), ['body' => 'Step'])->assertSessionHasNoErrors();
    $base = partsUrl("projects/{$project->id}/tasks/{$task->id}");
    $this->post("{$base}/collaborators", ['person_id' => $person->id])->assertSessionHasNoErrors();
    $item = $this->asTenant('acme', fn () => TaskChecklistItem::query()->sole());

    $this->actingAsMember('acme', ['projects.view']);
    $this->get($base)->assertOk();
    $this->post("{$base}/subtasks", ['title' => 'No'])->assertForbidden();
    $this->post("{$base}/collaborators", ['person_id' => $person->id])->assertForbidden();
    $this->delete("{$base}/collaborators/{$person->id}")->assertForbidden();
    $this->post("{$base}/checklist", ['body' => 'No'])->assertForbidden();
    $this->post("{$base}/checklist/{$item->id}/toggle")->assertForbidden();
    $this->delete("{$base}/checklist/{$item->id}")->assertForbidden();
    $this->post("{$base}/attachments", ['file' => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')])->assertForbidden();
});

it('changes nothing inside a task once the project is closed, but files still download', function () {
    Storage::fake('local');
    $project = partsProject();
    $task = partsTask($project);
    $person = partsPerson();
    $base = partsUrl("projects/{$project->id}/tasks/{$task->id}");
    $this->post("{$base}/checklist", ['body' => 'Step'])->assertSessionHasNoErrors();
    $this->post("{$base}/attachments", ['file' => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')])->assertSessionHasNoErrors();
    [$item, $file] = $this->asTenant('acme', fn () => [TaskChecklistItem::query()->sole(), TaskAttachment::query()->sole()]);

    $this->asTenant('acme', fn () => $project->forceFill(['status' => Project::COMPLETED])->save());

    $this->post("{$base}/subtasks", ['title' => 'No'])->assertSessionHasErrors('title');
    $this->post("{$base}/collaborators", ['person_id' => $person->id])->assertSessionHasErrors('person_id');
    $this->post("{$base}/checklist", ['body' => 'No'])->assertSessionHasErrors('body');
    $this->post("{$base}/checklist/{$item->id}/toggle")->assertSessionHasErrors('body');
    $this->delete("{$base}/checklist/{$item->id}")->assertSessionHasErrors('body');
    $this->post("{$base}/attachments", ['file' => UploadedFile::fake()->create('b.pdf', 1, 'application/pdf')])->assertSessionHasErrors('file');
    $this->delete("{$base}/attachments/{$file->id}")->assertSessionHasErrors('file');

    $this->get("{$base}/attachments/{$file->id}")->assertOk();
});

it('keeps each task\'s parts to itself, and another organization out', function () {
    Storage::fake('local');
    $project = partsProject();
    $task = partsTask($project);
    $other = partsTask($project, ['title' => 'Other']);
    $base = partsUrl("projects/{$project->id}/tasks/{$task->id}");
    $otherBase = partsUrl("projects/{$project->id}/tasks/{$other->id}");
    $this->post("{$base}/checklist", ['body' => 'Step'])->assertSessionHasNoErrors();
    $this->post("{$base}/attachments", ['file' => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')])->assertSessionHasNoErrors();
    [$item, $file] = $this->asTenant('acme', fn () => [TaskChecklistItem::query()->sole(), TaskAttachment::query()->sole()]);

    // Through another task of the same project: unknown.
    $this->get("{$otherBase}/attachments/{$file->id}")->assertNotFound();
    $this->delete("{$otherBase}/attachments/{$file->id}")->assertNotFound();
    $this->post("{$otherBase}/checklist/{$item->id}/toggle")->assertNotFound();
    $this->delete("{$otherBase}/checklist/{$item->id}")->assertNotFound();

    // Someone from another organization, on this organization's address: unknown.
    $this->actingAsMember('other', TASK_WORKER);
    $this->get("{$base}/attachments/{$file->id}")->assertForbidden();
    $this->post("{$base}/subtasks", ['title' => 'No'])->assertForbidden();
    $this->post("{$base}/checklist", ['body' => 'No'])->assertForbidden();

    // On their own address, this organization's task does not exist.
    $this->get(partsUrl("projects/{$project->id}/tasks/{$task->id}/attachments/{$file->id}", 'other'))->assertNotFound();
    $this->post(partsUrl("projects/{$project->id}/tasks/{$task->id}/subtasks", 'other'), ['title' => 'No'])->assertNotFound();

    // Nor can they name a person from elsewhere.
    $this->actingAs($this->worker);
    $foreign = partsPerson('Foreign', 'other');
    $this->organizationUrl('acme');
    $this->post("{$base}/collaborators", ['person_id' => $foreign->id])->assertSessionHasErrors('person_id');
    $this->post("{$base}/subtasks", ['title' => 'Sub', 'assignee_id' => $foreign->id])->assertSessionHasErrors('assignee_id');
});
