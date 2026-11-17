<?php

namespace App\Http\Controllers\Collaboration;

use App\Actions\Tasks;
use App\Collaboration\Acting;
use App\Collaboration\Models\Participant;
use App\Collaboration\SharedTasks;
use App\Http\Controllers\Collaboration\Concerns\ActsOnProject;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared project tasks (App\Collaboration\SharedTasks), the same routes on
 * both sides: projects/{project}/shared-tasks for the owner,
 * shared-projects/{participant}/shared-tasks for a participant. Reads run in
 * the owner's organization, where the tasks are; a participant reads only
 * what SharedTasks::canSee lets it, and only these fields.
 */
class SharedTaskController extends Controller
{
    use ActsOnProject;

    public function index(Request $request): Response
    {
        $acting = $this->acting($request);
        $tasks = $this->inOwner($acting, fn () => SharedTasks::visible($acting)->with('milestone:id,name')
            ->orderByRaw("case status when 'review' then 0 when 'in_progress' then 1 when 'todo' then 2 else 3 end")
            ->orderByRaw('due_on is null')->orderBy('due_on')->orderBy('number')->get());
        $names = $this->participantNames($acting);

        return Inertia::render('portal/collaboration/tasks', [
            'frame' => $this->frame($request, $acting),
            'tasks' => $tasks->map(fn (Task $task) => [
                ...$this->row($task, $names),
                'url' => $this->sideUrl($acting, 'shared-tasks.show', ['task' => $task->id]),
            ])->values(),
        ]);
    }

    public function show(Request $request): Response
    {
        $acting = $this->acting($request);
        $task = $this->task($request, $acting);

        return $this->inOwner($acting, function () use ($request, $acting, $task) {
            $names = $this->participantNames($acting);
            $comments = $task->comments()->with('author:id,name')->oldest()->get();
            $attachments = $task->attachments()->with('uploader:id,name')->oldest('created_at')->get();
            // Run in the owner's organization, on either side.
            $owner = app(CurrentOrganization::class)->require()->name;

            return Inertia::render('portal/collaboration/task', [
                'frame' => $this->frame($request, $acting),
                'task' => [
                    ...$this->row($task, $names),
                    'description' => $task->description,
                    'startOn' => $task->start_on?->toDateString(),
                ],
                'comments' => $comments->map(fn (TaskComment $comment) => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'by' => $comment->author->name,
                    'for' => $comment->participant_id === null ? $owner : ($names[$comment->participant_id] ?? 'A partner'),
                    'at' => $comment->created_at->toIso8601String(),
                ])->values(),
                'attachments' => $attachments->map(fn (TaskAttachment $attachment) => [
                    'id' => $attachment->id,
                    'name' => $attachment->original_name,
                    'size' => $attachment->size,
                    'by' => $attachment->uploader->name,
                    'for' => $attachment->participant_id === null ? $owner : ($names[$attachment->participant_id] ?? 'A partner'),
                    'url' => $this->sideUrl($acting, 'shared-tasks.attachments.show', ['task' => $task->id, 'attachment' => $attachment->id]),
                ])->values(),
                'canWork' => SharedTasks::canWork($task, $acting) && $acting->project->isOpen(),
                'canReview' => $acting->isOwner() && $task->status === Task::REVIEW && $task->participant_id !== null && SharedTasks::canWork($task, $acting),
                'statuses' => $acting->isOwner() ? [] : array_map(fn (string $status) => ['value' => $status, 'label' => Tasks::label($status)], SharedTasks::PARTICIPANT_STATUSES),
                'urls' => [
                    'comment' => $this->sideUrl($acting, 'shared-tasks.comments.store', ['task' => $task->id]),
                    'attach' => $this->sideUrl($acting, 'shared-tasks.attachments.store', ['task' => $task->id]),
                    'progress' => $acting->isOwner() ? null : $this->sideUrl($acting, 'shared-tasks.progress', ['task' => $task->id]),
                    'review' => $acting->isOwner() ? $this->sideUrl($acting, 'shared-tasks.review', ['task' => $task->id]) : null,
                    'index' => $this->sideUrl($acting, 'shared-tasks.index'),
                    'ownerTask' => $acting->isOwner() ? route('projects.tasks.show', ['project' => $acting->project->id, 'task' => $task->id]) : null,
                ],
            ]);
        });
    }

    /**
     * The owner shares a task with every participation, gives it to one, or
     * keeps it internal.
     */
    public function share(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $task = $this->ownTask($request, $acting);
        $validated = $request->validate([
            'shared' => ['required', 'boolean'],
            'participant_id' => ['nullable', 'uuid'],
        ]);

        SharedTasks::share($acting, $task, filter_var($validated['shared'], FILTER_VALIDATE_BOOLEAN), $validated['participant_id'] ?? null);

        return back()->with('success', "{$task->number}: who works on it and who sees it saved.");
    }

    public function comment(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $task = $this->task($request, $acting);
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        SharedTasks::comment($acting, $task, trim((string) $validated['body']));

        return back()->with('success', 'Comment added.');
    }

    public function attach(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $task = $this->task($request, $acting);
        $validated = $request->validate(['file' => ['required', ...TaskAttachment::RULES]]);

        SharedTasks::attach($acting, $task, $validated['file']);

        return back()->with('success', 'File added.');
    }

    public function download(Request $request): StreamedResponse
    {
        $acting = $this->acting($request);
        $task = $this->task($request, $acting);
        $attachment = $this->inOwner($acting, fn () => $task->attachments()->find((string) $request->route('attachment'))) ?? abort(404);

        return Storage::disk(Tasks::DISK)->download($attachment->path, $attachment->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function progress(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $task = $this->task($request, $acting);
        $validated = $request->validate(['status' => ['required', 'string', Rule::in(Task::STATUSES)]]);

        SharedTasks::progress($acting, $task, (string) $validated['status']);

        return back()->with('success', $validated['status'] === Task::REVIEW ? "{$task->number} sent for review." : "{$task->number} updated.");
    }

    public function review(Request $request): RedirectResponse
    {
        $acting = $this->acting($request);
        $task = $this->task($request, $acting);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'return'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        SharedTasks::review($acting, $task, $validated['decision'] === 'accept', $validated['note'] ?? null);

        return back()->with('success', $validated['decision'] === 'accept' ? "{$task->number} accepted as done." : "{$task->number} returned.");
    }

    /**
     * A task this party may see, or 404.
     */
    private function task(Request $request, Acting $acting): Task
    {
        $task = $this->inOwner($acting, fn () => Task::query()->where('project_id', $acting->project->id)->find((string) $request->route('task')));
        abort_unless($task instanceof Task && SharedTasks::canSee($task, $acting), 404);

        return $task;
    }

    /**
     * Any task of the project, for the owner to share.
     */
    private function ownTask(Request $request, Acting $acting): Task
    {
        abort_unless($acting->isOwner(), 404);
        $task = Task::query()->where('project_id', $acting->project->id)->find((string) $request->route('task'));
        abort_unless($task instanceof Task, 404);

        return $task;
    }

    /**
     * The names of the project's participating organizations, by participation.
     *
     * @return array<string, string>
     */
    private function participantNames(Acting $acting): array
    {
        return Participant::query()->with('organization:id,name')->where('project_id', $acting->project->id)->get()
            ->mapWithKeys(fn (Participant $participant) => [$participant->id => $participant->organization->name])->all();
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function row(Task $task, array $names): array
    {
        return [
            'id' => $task->id,
            'number' => $task->number,
            'title' => $task->title,
            'status' => $task->status,
            'statusLabel' => Tasks::label($task->status),
            'priority' => $task->priority->value,
            'dueOn' => $task->due_on?->toDateString(),
            'overdue' => $task->due_on !== null && $task->status !== Task::DONE && $task->due_on->isPast(),
            'milestone' => $task->milestone?->name,
            'shared' => $task->shared,
            'givenTo' => $task->participant_id === null ? null : ($names[$task->participant_id] ?? null),
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inOwner(Acting $acting, callable $callback): mixed
    {
        $owner = $acting->participant?->owner;

        return $owner === null ? $callback() : app(CurrentOrganization::class)->run($owner, $callback);
    }
}
