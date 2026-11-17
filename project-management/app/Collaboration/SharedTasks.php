<?php

namespace App\Collaboration;

use App\Actions\Tasks;
use App\Collaboration\Models\Participant;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskComment;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tasks of the owner's project that organizations taking part in it work on
 * (Phase 5). The task stays the owner's record, in the owner's organization:
 *
 * - internal (the default): only the owner's people;
 * - shared: every participation that may view the project reads it;
 * - given to a participation (participant_id): that organization reads it and
 *   works on it: comments, files, and moving it to in progress or to review.
 *
 * Only the owner marks a task done: it accepts what was sent for review, or
 * returns it. A participation that is suspended or ended loses access at
 * once (Acting); its tasks, comments and files stay with the owner.
 */
final class SharedTasks
{
    /** What a participant may move its task to. Done is the owner's. */
    public const PARTICIPANT_STATUSES = [Task::TODO, Task::IN_PROGRESS, Task::REVIEW];

    /**
     * The tasks a party sees on the shared tasks page.
     *
     * @return Builder<Task>
     */
    public static function visible(Acting $acting): Builder
    {
        $query = Task::query()->where('project_id', $acting->project->id)->whereNull('parent_id');
        if ($acting->isOwner()) {
            return $query->where(fn (Builder $where) => $where->where('shared', true)->orWhereNotNull('participant_id'));
        }

        /** @var Participant $participant */
        $participant = $acting->participant;

        $seesShared = ParticipantAccess::allows($participant, Capability::VIEW);

        return $query->where(fn (Builder $where) => $where->where('participant_id', $participant->id)
            ->when($seesShared, fn (Builder $or) => $or->orWhere('shared', true)));
    }

    public static function canSee(Task $task, Acting $acting): bool
    {
        if ($task->project_id !== $acting->project->id) {
            return false;
        }
        if ($acting->isOwner()) {
            return true;
        }

        return $task->participant_id === $acting->partyId()
            || ($task->shared && ParticipantAccess::allows($acting->participant, Capability::VIEW));
    }

    /**
     * Whether this party works on the task: the owner's people who manage
     * tasks, or the participation it was given to, with the capability.
     */
    public static function canWork(Task $task, Acting $acting): bool
    {
        if (! self::canSee($task, $acting)) {
            return false;
        }
        if ($acting->isOwner()) {
            return $acting->may('projects.tasks.manage');
        }

        return $task->participant_id === $acting->partyId() && $acting->may('projects.tasks.manage', Capability::ACTIVITIES_MANAGE);
    }

    /**
     * The owner shares a task with every participation, gives it to one, or
     * takes it back. Only to a participation active now.
     *
     * @throws ValidationException
     */
    public static function share(Acting $acting, Task $task, bool $shared, ?string $participantId): void
    {
        abort_unless($acting->isOwner() && $task->project_id === $acting->project->id, 404);
        $acting->require('projects.tasks.manage');
        abort_if($task->parent_id !== null, 422, 'Share the task itself: its subtasks go with it.');

        $participant = null;
        if ($participantId !== null) {
            $participant = Participant::query()->where('project_id', $acting->project->id)->find($participantId);
            if ($participant === null || $participant->status !== Participant::ACTIVE) {
                throw ValidationException::withMessages(['participant_id' => 'Choose an organization taking part in this project now.']);
            }
        }

        DB::transaction(function () use ($acting, $task, $shared, $participant) {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            $before = ['shared' => $locked->shared, 'participant_id' => $locked->participant_id];
            $locked->forceFill(['shared' => $shared, 'participant_id' => $participant?->id])->save();
            if ($before === ['shared' => $shared, 'participant_id' => $participant?->id]) {
                return;
            }

            Reports::audit($acting, 'task.sharing_changed', $locked, [
                'number' => $locked->number,
                'shared' => $shared,
                'given_to' => $participant?->organization->name,
                'was_given_to' => $before['participant_id'],
            ]);
            if ($participant !== null && $before['participant_id'] !== $participant->id) {
                Notify::party($acting->project, $participant, 'projects.view', 'tasks', $locked->id, "{$acting->project->code}: {$locked->number} {$locked->title} is yours", 'Given to your organization by '.$acting->partyName().'.', $acting->user);
            }
        });
    }

    /**
     * @throws ValidationException
     */
    public static function comment(Acting $acting, Task $task, string $body): TaskComment
    {
        self::requireWork($task, $acting);

        return self::inOwner($acting, function () use ($acting, $task, $body) {
            $comment = app(Tasks::class)->addComment($task, $body, $acting->user);
            $comment->forceFill(['participant_id' => $acting->partyId()])->save();
            self::tellOtherSide($acting, $task, "{$acting->partyName()} commented on {$task->number}", mb_substr($body, 0, 200));

            return $comment;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function attach(Acting $acting, Task $task, UploadedFile $file): TaskAttachment
    {
        self::requireWork($task, $acting);

        return self::inOwner($acting, function () use ($acting, $task, $file) {
            $attachment = app(Tasks::class)->attach($task, $file, $acting->user);
            $attachment->forceFill(['participant_id' => $acting->partyId()])->save();
            Reports::audit($acting, 'task.partner_file_attached', $task, ['number' => $task->number, 'file' => $attachment->original_name]);

            return $attachment;
        });
    }

    /**
     * The participation moves its task along: to in progress, or to review
     * when it is done on its side. The owner hears of a review.
     *
     * @throws ValidationException
     */
    public static function progress(Acting $acting, Task $task, string $status): void
    {
        abort_if($acting->isOwner(), 404);
        self::requireWork($task, $acting);
        if (! in_array($status, self::PARTICIPANT_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'The project owner marks a task done, once it has reviewed it.']);
        }
        if ($task->status === Task::DONE) {
            throw ValidationException::withMessages(['status' => "{$task->number} is done already. Ask the project owner to reopen it."]);
        }

        self::inOwner($acting, function () use ($acting, $task, $status) {
            app(Tasks::class)->move($task, $status, null, $acting->user);
            Reports::audit($acting, 'task.partner_progress', $task, ['number' => $task->number, 'status' => $status]);
            if ($status === Task::REVIEW) {
                self::tellOtherSide($acting, $task, "{$acting->partyName()} sent {$task->number} {$task->title} for review", 'Accept it as done, or return it with a note.');
            }
        });
    }

    /**
     * The owner accepts a task sent for review as done, or returns it to the
     * participation with a note.
     *
     * @throws ValidationException
     */
    public static function review(Acting $acting, Task $task, bool $accept, ?string $note): void
    {
        abort_unless($acting->isOwner(), 404);
        self::requireWork($task, $acting);
        if ($task->status !== Task::REVIEW || $task->participant_id === null) {
            throw ValidationException::withMessages(['review' => "{$task->number} is not waiting for your review."]);
        }
        $note = trim((string) $note);
        if (! $accept && $note === '') {
            throw ValidationException::withMessages(['note' => 'Say what is missing, so they can finish it.']);
        }

        DB::transaction(function () use ($acting, $task, $accept, $note) {
            $tasks = app(Tasks::class);
            $tasks->move($task, $accept ? Task::DONE : Task::IN_PROGRESS, null, $acting->user);
            if ($note !== '') {
                $tasks->addComment($task, ($accept ? 'Accepted: ' : 'Returned: ').$note, $acting->user);
            }
            Reports::audit($acting, $accept ? 'task.partner_work_accepted' : 'task.partner_work_returned', $task, ['number' => $task->number, 'note' => $note === '' ? null : $note]);

            $participant = Participant::query()->find($task->participant_id);
            if ($participant !== null) {
                Notify::party($acting->project, $participant, 'projects.view', 'tasks', $task->id, $accept ? "{$task->number} accepted as done" : "{$task->number} returned to you", $note === '' ? null : $note, $acting->user);
            }
        });
    }

    /**
     * Open tasks given to a participation: what is left when it ends.
     */
    public static function openFor(Participant $participant): int
    {
        return app(CurrentOrganization::class)->run($participant->owner, fn () => Task::query()
            ->where('participant_id', $participant->id)->where('status', '!=', Task::DONE)->count());
    }

    private static function requireWork(Task $task, Acting $acting): void
    {
        abort_unless(self::canSee($task, $acting), 404);
        abort_unless(self::canWork($task, $acting), 403);
        abort_unless($acting->project->isOpen(), 409, 'This project is closed.');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function inOwner(Acting $acting, callable $callback): mixed
    {
        $owner = $acting->isOwner() ? null : $acting->participant?->owner;

        $inTransaction = fn () => DB::transaction(fn () => $callback());

        return $owner === null ? $inTransaction() : app(CurrentOrganization::class)->run($owner, $inTransaction);
    }

    private static function tellOtherSide(Acting $acting, Task $task, string $title, ?string $excerpt): void
    {
        if ($acting->isOwner()) {
            $participant = $task->participant_id === null ? null : Participant::query()->find($task->participant_id);
            if ($participant !== null) {
                Notify::party($acting->project, $participant, 'projects.view', 'tasks', $task->id, $title, $excerpt, $acting->user);
            }

            return;
        }

        Notify::party($acting->project, null, 'projects.tasks.manage', 'tasks', $task->id, $title, $excerpt, $acting->user);
    }
}
