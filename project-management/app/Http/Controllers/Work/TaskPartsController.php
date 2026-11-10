<?php

namespace App\Http\Controllers\Work;

use App\Actions\Tasks;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskChecklistItem;
use App\Support\ActivityLog;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What sits inside a task: its subtasks, collaborators, checklist and
 * attachments. Nothing here changes while the project is closed, though
 * files can still be downloaded.
 */
class TaskPartsController extends Controller
{
    public function addSubtask(Request $request, Project $project, Task $task, Tasks $tasks): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'assignee_id' => ['nullable', 'uuid', $this->activePerson()],
            'due_on' => ['nullable', 'date'],
        ]);

        $subtask = $tasks->addSubtask($task, [
            'title' => trim($validated['title']),
            'priority' => $task->priority,
            'assignee_id' => $validated['assignee_id'] ?? null,
            'due_on' => $validated['due_on'] ?? null,
        ], $this->member($request));

        return back()->with('success', "{$subtask->number} added.");
    }

    public function addCollaborator(Request $request, Project $project, Task $task, Tasks $tasks): RedirectResponse
    {
        $validated = $request->validate(['person_id' => ['required', 'uuid', $this->activePerson()]]);
        $person = Person::query()->whereKey($validated['person_id'])->firstOrFail();

        $tasks->addCollaborator($task, $person, $this->member($request));

        return back()->with('success', "{$person->name} is on {$task->number}.");
    }

    public function removeCollaborator(Request $request, Project $project, Task $task, Person $collaborator, Tasks $tasks): RedirectResponse
    {
        $tasks->removeCollaborator($task, $collaborator, $this->member($request));

        return back()->with('success', "{$collaborator->name} is off {$task->number}.");
    }

    public function addChecklistItem(Request $request, Project $project, Task $task, Tasks $tasks): RedirectResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'max:255']]);

        $tasks->addChecklistItem($task, trim($validated['body']));

        return back();
    }

    public function toggleChecklistItem(Request $request, Project $project, Task $task, TaskChecklistItem $checklistItem, Tasks $tasks): RedirectResponse
    {
        $tasks->toggleChecklistItem($task, $checklistItem, $this->member($request));

        return back();
    }

    public function removeChecklistItem(Project $project, Task $task, TaskChecklistItem $checklistItem, Tasks $tasks): RedirectResponse
    {
        $tasks->removeChecklistItem($task, $checklistItem);

        return back();
    }

    public function attach(Request $request, Project $project, Task $task, Tasks $tasks): RedirectResponse
    {
        $validated = $request->validate(['file' => ['required', ...TaskAttachment::RULES]]);

        $attachment = $tasks->attach($task, $validated['file'], $this->member($request));
        ActivityLog::record($project, $this->member($request), "Attached {$attachment->original_name} to {$task->number}");

        return back()->with('success', 'File attached.');
    }

    public function detach(Request $request, Project $project, Task $task, TaskAttachment $attachment, Tasks $tasks): RedirectResponse
    {
        $me = $this->member($request);
        abort_unless($this->can($request, 'projects.manage') || $attachment->uploaded_by === $me->id, 403);

        $tasks->detach($task, $attachment, $me);

        return back()->with('success', 'File removed.');
    }

    public function download(Project $project, Task $task, TaskAttachment $attachment): StreamedResponse
    {
        return Storage::disk(Tasks::DISK)->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * An active person of this organization.
     */
    private function activePerson(): Exists
    {
        return Rule::exists('people', 'id')->where('organization_id', app(CurrentOrganization::class)->id())->where('active', true);
    }
}
