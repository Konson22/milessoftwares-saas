<?php

namespace App\Logbook;

use App\Logbook\Models\Entry;
use App\Logbook\Models\LogException;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\Reminder;
use App\Logbook\Models\Task;
use App\Logbook\Models\TaskSeries;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tasks and their lifecycle (ADR-0026 §2.4), every change made here with
 * the task row locked and its revision raised:
 *
 * - created, assigned and reassigned by holders of logbook.tasks.manage,
 *   only to someone who reaches what the task is about;
 * - started and completed by the assignee only;
 * - verified or returned by a holder of logbook.tasks.manage who is not
 *   the assignee;
 * - cancelled (with a reason) and reopened; never deleted.
 *
 * $revision, when given, is the revision the person acted on: a task
 * changed since (reassigned, cancelled, completed elsewhere) is refused
 * as stale instead of being overwritten.
 */
final class Tasks
{
    public const MANAGE = 'logbook.tasks.manage';

    /**
     * Tasks a member may see: their own (assigned or created), and for
     * holders of logbook.tasks.manage every task within the logbooks they
     * reach (and tasks about no logbook).
     *
     * @return Builder<Task>
     */
    public static function visible(?Member $member = null): Builder
    {
        $member ??= Member::current();

        return Task::query()->where(function (Builder $q) use ($member) {
            $q->where('assignee_id', $member->id)->orWhere('created_by', $member->id);
            if ($member->can(self::MANAGE)) {
                $q->orWhereIn('logbook_id', Access::logbooks($member)->select('id'));
                // Tasks about no logbook belong to the whole workspace.
                if ($member->everywhere(self::MANAGE)) {
                    $q->orWhereNull('logbook_id');
                }
            }
        });
    }

    public static function find(string $id): Task
    {
        return self::visible()->findOrFail($id);
    }

    public static function manages(Task $task, ?Member $member = null): bool
    {
        $member ??= Member::current();

        return $member->can(self::MANAGE) && ($task->logbook_id === null
            ? $member->everywhere(self::MANAGE)
            : Access::reachesLogbook($task->logbook_id, $member));
    }

    /**
     * Whether the member reaches what the task is about: the entry, else
     * the sheet, else the logbook. A task about nothing is reachable.
     */
    public static function reaches(Task|TaskSeries $task, Member $member): bool
    {
        $entryId = $task instanceof Task ? $task->log_entry_id : null;
        if ($entryId !== null && Access::entries($member)->whereKey($entryId)->exists()) {
            return true;
        }
        if ($task->log_sheet_id !== null) {
            $sheet = LogSheet::query()->find($task->log_sheet_id);

            return $sheet !== null && Access::limit(LogSheet::query()->whereKey($sheet->id), array_keys(Access::CAPABILITIES), 'logbook_id', 'id', $member)->exists();
        }

        return $task->logbook_id === null || Access::reachesLogbook($task->logbook_id, $member);
    }

    /**
     * @param  array<string, mixed>  $data  title, description, priority, assignee_id, logbook_id, log_sheet_id, log_entry_id, exception_id, resource_id, starts_at, due_at, reminders, verification_required, evidence_required
     */
    public static function create(array $data, User $by): Task
    {
        $fields = self::fields($data);

        return DB::transaction(function () use ($fields, $by) {
            $task = new Task([
                ...$fields,
                'reference' => References::next(References::TASKS['sequence'], References::TASKS['prefix']),
                'status' => 'pending',
                'created_by' => $by->id,
                'assigned_at' => now(),
            ]);
            self::mustReach($task, $task->assignee_id);
            $task->save();
            self::planReminders($task);
            AuditTrail::record('task.created', $task, $by, [
                'assignee' => self::name($task->assignee_id),
                'due' => $task->due_at?->toIso8601String(),
                'priority' => $task->priority,
            ]);
            Notifications::later(self::class, 'notifyAssigned', [$task->id, $by->id, 'task.assigned']);

            return $task;
        });
    }

    /**
     * Changes what the task says, its dates or its reminders (not who does
     * it: see assign()).
     *
     * @param  array<string, mixed>  $data
     */
    public static function update(Task $task, array $data, User $by, ?int $revision = null): Task
    {
        $fields = self::fields([...self::current($task), ...$data]);
        unset($fields['assignee_id']);

        return DB::transaction(function () use ($task, $fields, $by, $revision) {
            $task = self::lock($task, $revision);
            if (in_array($task->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['task' => 'A '.$task->status.' task is not changed. Reopen it first.']);
            }
            $before = ['due' => $task->due_at?->toIso8601String(), 'starts' => $task->starts_at?->toIso8601String(), 'priority' => $task->priority];
            $task->fill($fields);
            self::mustReach($task, $task->assignee_id);
            $task->revision++;
            $task->save();
            self::planReminders($task);
            $after = ['due' => $task->due_at?->toIso8601String(), 'starts' => $task->starts_at?->toIso8601String(), 'priority' => $task->priority];
            AuditTrail::record('task.updated', $task, $by, self::changes($before, $after));
            if ($before['due'] !== $after['due'] || $before['starts'] !== $after['starts']) {
                Notifications::later(self::class, 'notifyEvent', [$task->id, $by->id, 'task.rescheduled']);
            }

            return $task;
        });
    }

    public static function assign(Task $task, string $assigneeId, User $by, ?int $revision = null): Task
    {
        return DB::transaction(function () use ($task, $assigneeId, $by, $revision) {
            $task = self::lock($task, $revision);
            if (! in_array($task->status, ['pending', 'in_progress', 'returned'], true)) {
                throw ValidationException::withMessages(['assignee_id' => 'Only a task still to be done is reassigned.']);
            }
            if ($task->assignee_id === $assigneeId) {
                return $task;
            }
            self::mustBeMember($assigneeId);
            self::mustReach($task, $assigneeId);
            $previous = $task->assignee_id;
            $task->forceFill([
                'assignee_id' => $assigneeId,
                'assigned_at' => now(),
                // The new person starts it themselves.
                'status' => 'pending',
                'started_at' => null,
                'revision' => $task->revision + 1,
            ])->save();
            AuditTrail::record('task.reassigned', $task, $by, ['from' => self::name($previous), 'to' => self::name($assigneeId)]);
            Notifications::later(self::class, 'notifyAssigned', [$task->id, $by->id, 'task.reassigned']);
            Notifications::later(self::class, 'notifyUnassigned', [$task->id, $previous, $by->id]);

            return $task;
        });
    }

    public static function start(Task $task, User $by, ?int $revision = null): Task
    {
        return DB::transaction(function () use ($task, $by, $revision) {
            $task = self::lockForAssignee($task, $by, $revision);
            if ($task->status === 'in_progress') {
                return $task;
            }
            if (! in_array($task->status, ['pending', 'returned'], true)) {
                throw ValidationException::withMessages(['task' => 'This task is '.self::statusLabel($task->status).'.']);
            }
            $task->forceFill(['status' => 'in_progress', 'started_at' => $task->started_at ?? now(), 'revision' => $task->revision + 1])->save();
            AuditTrail::record('task.started', $task, $by);

            return $task;
        });
    }

    /**
     * The assignee reports the work done: notes, the moment, a linked
     * entry, evidence already uploaded. With $key (the phone), a repeat of
     * the same completion answers the task as the first one left it.
     */
    public static function complete(Task $task, User $by, ?string $notes, ?string $entryId = null, ?string $completedAt = null, ?string $key = null, ?int $revision = null): Task
    {
        return DB::transaction(function () use ($task, $by, $notes, $entryId, $completedAt, $key, $revision) {
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            if ($key !== null && $locked->completion_key === $key) {
                return $locked;
            }
            $task = self::lockForAssignee($locked, $by, $revision);
            if (! $task->isOpen()) {
                throw ValidationException::withMessages(['task' => 'This task is '.self::statusLabel($task->status).'.']);
            }
            if ($task->evidence_required && ! $task->files()->where('kind', 'evidence')->exists()) {
                throw ValidationException::withMessages(['evidence' => 'Add evidence of the work (a photo or a document) before completing.']);
            }
            if ($entryId !== null && ! Access::entries()->whereKey($entryId)->where('status', '!=', 'draft')->exists()) {
                throw ValidationException::withMessages(['completion_entry_id' => 'Link a submitted entry you can read.']);
            }
            $at = $completedAt !== null ? CarbonImmutable::parse($completedAt) : CarbonImmutable::now();
            if ($at->gt(CarbonImmutable::now()->addMinutes(5))) {
                throw ValidationException::withMessages(['completed_at' => 'The completion time cannot be in the future.']);
            }

            $task->forceFill([
                'status' => $task->verification_required ? 'awaiting_verification' : 'completed',
                'completed_at' => $at,
                'completed_by' => $by->id,
                'completion_notes' => self::text($notes),
                'completion_entry_id' => $entryId,
                'completion_key' => $key,
                // When the server heard of it: the phone's clock and its time offline are both visible.
                'completion_received_at' => CarbonImmutable::now(),
                'started_at' => $task->started_at ?? $at,
                'return_comment' => null,
                'revision' => $task->revision + 1,
            ])->save();
            Reminders::cancel('task', $task->id);
            AuditTrail::record($task->status === 'completed' ? 'task.completed' : 'task.submitted', $task, $by, [
                'completed' => $at->toIso8601String(),
                'entry' => $entryId !== null ? Entry::query()->whereKey($entryId)->value('reference') : null,
            ]);
            Notifications::later(self::class, 'notifyEvent', [$task->id, $by->id, $task->status === 'completed' ? 'task.completed' : 'task.awaiting_verification']);

            return $task;
        });
    }

    /**
     * Verifies the reported work, or returns it with what to put right.
     */
    public static function verify(Task $task, User $by, string $decision, ?string $comment, ?int $revision = null): Task
    {
        return DB::transaction(function () use ($task, $by, $decision, $comment, $revision) {
            $task = self::lock($task, $revision);
            if ($task->status !== 'awaiting_verification') {
                throw ValidationException::withMessages(['decision' => 'Only work awaiting verification is verified. This task is '.self::statusLabel($task->status).'.']);
            }
            if ($task->assignee_id === $by->id || $task->completed_by === $by->id) {
                throw ValidationException::withMessages(['decision' => 'Nobody verifies their own work.']);
            }
            $comment = self::text($comment);
            if ($decision === 'returned' && $comment === null) {
                throw ValidationException::withMessages(['comment' => 'Say what needs doing again.']);
            }

            $task->forceFill($decision === 'verified'
                ? ['status' => 'completed', 'verified_at' => now(), 'verified_by' => $by->id, 'revision' => $task->revision + 1]
                : ['status' => 'returned', 'return_comment' => $comment, 'completion_key' => null, 'revision' => $task->revision + 1])->save();
            if ($decision === 'returned') {
                self::planReminders($task);
            }
            AuditTrail::record($decision === 'verified' ? 'task.verified' : 'task.returned', $task, $by, ['comment' => $comment]);
            Notifications::later(self::class, 'notifyEvent', [$task->id, $by->id, $decision === 'verified' ? 'task.verified' : 'task.returned']);

            return $task;
        });
    }

    public static function cancel(Task $task, User $by, string $reason, ?int $revision = null): Task
    {
        $reason = self::text($reason) ?? throw ValidationException::withMessages(['reason' => 'Say why the task is cancelled.']);

        return DB::transaction(function () use ($task, $by, $reason, $revision) {
            $task = self::lock($task, $revision);
            if (in_array($task->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['task' => 'This task is already '.self::statusLabel($task->status).'.']);
            }
            $from = $task->status;
            $task->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => $reason, 'revision' => $task->revision + 1])->save();
            Reminders::cancel('task', $task->id);
            AuditTrail::record('task.cancelled', $task, $by, ['from' => $from, 'reason' => $reason]);
            Notifications::later(self::class, 'notifyEvent', [$task->id, $by->id, 'task.cancelled']);

            return $task;
        });
    }

    /**
     * A completed or cancelled task back to work: in progress if it was
     * started, else pending. What happened before stays in its history.
     */
    public static function reopen(Task $task, User $by, ?string $reason, ?int $revision = null): Task
    {
        return DB::transaction(function () use ($task, $by, $reason, $revision) {
            $task = self::lock($task, $revision);
            if (! in_array($task->status, ['completed', 'cancelled', 'awaiting_verification'], true)) {
                throw ValidationException::withMessages(['task' => 'Only a completed or cancelled task is reopened.']);
            }
            $from = $task->status;
            self::mustReach($task, $task->assignee_id);
            $task->forceFill([
                'status' => $task->started_at !== null ? 'in_progress' : 'pending',
                'reopened_at' => now(),
                'completed_at' => null,
                'completed_by' => null,
                'completion_key' => null,
                'verified_at' => null,
                'verified_by' => null,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancel_reason' => null,
                'revision' => $task->revision + 1,
            ])->save();
            self::planReminders($task);
            AuditTrail::record('task.reopened', $task, $by, ['from' => $from, 'reason' => self::text($reason)]);
            Notifications::later(self::class, 'notifyEvent', [$task->id, $by->id, 'task.reopened']);

            return $task;
        });
    }

    // ---- Recurring tasks ----

    /**
     * @param  array<string, mixed>  $data
     */
    public static function saveSeries(?TaskSeries $series, array $data, User $by): TaskSeries
    {
        Recurrence::check($data, ['start_time', 'due_time']);
        $fields = self::fields([...$data, 'starts_at' => null, 'due_at' => null, 'log_entry_id' => null, 'exception_id' => null]);
        // Fixed moments do not repeat: a series keeps start and deadline reminders only.
        $tokens = $fields['reminders'] ?? [];
        $values = [
            'title' => $fields['title'],
            'description' => $fields['description'],
            'priority' => $fields['priority'],
            'logbook_id' => $fields['logbook_id'],
            'log_sheet_id' => $fields['log_sheet_id'],
            'resource_id' => $fields['resource_id'],
            'assignee_id' => $fields['assignee_id'],
            'verification_required' => $fields['verification_required'],
            'evidence_required' => $fields['evidence_required'],
            'frequency' => $data['frequency'],
            'weekdays' => $data['frequency'] === 'weekly' ? array_values(array_map('intval', (array) $data['weekdays'])) : null,
            'day_of_month' => $data['frequency'] === 'monthly' ? (int) $data['day_of_month'] : null,
            'start_time' => $data['start_time'],
            'due_time' => $data['due_time'],
            'timezone' => $data['timezone'],
            'starts_on' => CarbonImmutable::parse((string) $data['starts_on'])->toDateString(),
            'ends_on' => isset($data['ends_on']) && $data['ends_on'] !== '' ? CarbonImmutable::parse((string) $data['ends_on'])->toDateString() : null,
            'reminders' => array_values(array_filter($tokens, fn (string $t) => ! str_starts_with($t, 'at:'))),
            'reminder_channels' => $fields['reminder_channels'],
        ];
        if ($values['ends_on'] !== null && $values['ends_on'] < $values['starts_on']) {
            throw ValidationException::withMessages(['ends_on' => 'The end date is before the start date.']);
        }
        $wantsActive = array_key_exists('active', $data) ? (bool) $data['active'] : null;

        $series = DB::transaction(function () use ($series, $values, $by) {
            $series ??= new TaskSeries(['created_by' => $by->id]);
            if ($series->ended_at !== null) {
                throw ValidationException::withMessages(['series' => 'This recurring task has ended. Set up a new one instead.']);
            }
            $before = $series->exists ? self::seriesSummary($series) : null;
            $series->fill($values);
            if (! $series->exists) {
                // Nothing is made for times already past when it is set up.
                $series->forceFill(['generate_from' => now(), 'active' => true]);
            }
            if (! self::reaches($series, Member::of($series->assignee_id) ?? throw ValidationException::withMessages(['assignee_id' => 'This person has no access to Logbook.']))) {
                throw ValidationException::withMessages(['assignee_id' => 'This person does not reach the logbook or log sheet of this task.']);
            }
            $series->save();
            // Tasks already made keep their assignee and dates: they are history. Changes apply from the next one.
            AuditTrail::record($before === null ? 'task_series.created' : 'task_series.updated', $series, $by, $before === null
                ? self::seriesSummary($series)
                : self::changes($before, self::seriesSummary($series)));

            return $series;
        });

        // The older form sends "active"; it means pause or resume.
        if ($wantsActive === false && $series->paused_at === null) {
            return self::pauseSeries($series, $by);
        }
        if ($wantsActive === true && $series->paused_at !== null) {
            return self::resumeSeries($series, $by);
        }

        return $series;
    }

    /**
     * Stops making tasks until resumed. Tasks already made but not yet
     * begun (tomorrow's, made a day ahead) are cancelled, so a pause takes
     * effect at once; tasks under way are left to finish.
     */
    public static function pauseSeries(TaskSeries $series, User $by): TaskSeries
    {
        return DB::transaction(function () use ($series, $by) {
            $series = TaskSeries::query()->whereKey($series->id)->lockForUpdate()->firstOrFail();
            if ($series->ended_at !== null || $series->paused_at !== null) {
                return $series;
            }
            $series->forceFill(['paused_at' => now(), 'active' => false])->save();
            $cancelled = self::cancelUpcoming($series, $by, 'Recurring task paused.');
            AuditTrail::record('task_series.paused', $series, $by, ['cancelled' => $cancelled]);

            return $series;
        });
    }

    /** Makes tasks again from now on: the paused days are not filled in. */
    public static function resumeSeries(TaskSeries $series, User $by): TaskSeries
    {
        return DB::transaction(function () use ($series, $by) {
            $series = TaskSeries::query()->whereKey($series->id)->lockForUpdate()->firstOrFail();
            if ($series->ended_at !== null) {
                throw ValidationException::withMessages(['series' => 'This recurring task has ended. Set up a new one instead.']);
            }
            if ($series->paused_at === null) {
                return $series;
            }
            $member = Member::of($series->assignee_id);
            if ($member === null || ! self::reaches($series, $member)) {
                throw ValidationException::withMessages(['assignee_id' => self::name($series->assignee_id).' no longer reaches what this task is about. Choose another person first.']);
            }
            $series->forceFill(['paused_at' => null, 'active' => true, 'generate_from' => now()])->save();
            AuditTrail::record('task_series.resumed', $series, $by);

            return $series;
        });
    }

    /** Ends the series for good: no more tasks; the not-yet-begun one is cancelled. */
    public static function endSeries(TaskSeries $series, User $by): TaskSeries
    {
        return DB::transaction(function () use ($series, $by) {
            $series = TaskSeries::query()->whereKey($series->id)->lockForUpdate()->firstOrFail();
            if ($series->ended_at !== null) {
                return $series;
            }
            $today = CarbonImmutable::now($series->timezone)->toDateString();
            $series->forceFill([
                'ended_at' => now(),
                'active' => false,
                'ends_on' => $series->ends_on !== null && $series->ends_on->toDateString() < $today ? $series->ends_on : $today,
            ])->save();
            $cancelled = self::cancelUpcoming($series, $by, 'Recurring task ended.');
            AuditTrail::record('task_series.ended', $series, $by, ['cancelled' => $cancelled]);

            return $series;
        });
    }

    /** active, paused or ended. */
    public static function seriesState(TaskSeries $series): string
    {
        return match (true) {
            $series->ended_at !== null || ($series->ends_on !== null && $series->ends_on->lt(CarbonImmutable::today($series->timezone))) => 'ended',
            $series->paused_at !== null || ! $series->active => 'paused',
            default => 'active',
        };
    }

    /** When the next task of a running series falls due (within 62 days), for the Recurring list. */
    public static function nextDue(TaskSeries $series, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        if (self::seriesState($series) !== 'active') {
            return null;
        }
        $now ??= CarbonImmutable::now();
        $tz = $series->timezone;
        $local = $now->setTimezone($tz);
        foreach (Recurrence::dates($series->frequency, $series->weekdays, $series->day_of_month, $series->starts_on, $series->ends_on, $local, $local->addDays(62), $tz) as $date) {
            $dueAt = Recurrence::at($series->due_time <= $series->start_time ? $date->addDay() : $date, $series->due_time, $tz);
            if ($dueAt->gt($now)) {
                return $dueAt;
            }
        }

        return null;
    }

    private static function cancelUpcoming(TaskSeries $series, User $by, string $reason): int
    {
        $upcoming = Task::query()->where('series_id', $series->id)->where('status', 'pending')
            ->where('starts_at', '>', now())->get();
        foreach ($upcoming as $task) {
            self::cancel($task, $by, $reason);
        }

        return $upcoming->count();
    }

    /** @return array<string, mixed> */
    private static function seriesSummary(TaskSeries $series): array
    {
        return [
            'title' => $series->title,
            'assignee' => self::name($series->assignee_id),
            'frequency' => $series->frequency,
            'weekdays' => $series->weekdays,
            'day_of_month' => $series->day_of_month,
            'start' => $series->start_time,
            'due' => $series->due_time,
            'timezone' => $series->timezone,
            'priority' => $series->priority,
            'starts_on' => $series->starts_on->toDateString(),
            'ends_on' => $series->ends_on instanceof CarbonImmutable ? $series->ends_on->toDateString() : $series->ends_on,
        ];
    }

    /**
     * Makes the tasks of every running series starting within the next 24
     * hours, and catches up on those missed while the clock was down (up
     * to logbook.catch_up_days, never before the series was set up or
     * resumed: `generate_from`). Safe to run again: (series, date) is
     * unique, so a repeat or two runs at once make one task. A series
     * whose assignee no longer reaches its logbook makes nothing (and its
     * creator is told once per day).
     *
     * @return int tasks made
     */
    public static function generate(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $made = 0;
        $lookback = max(1, (int) config('logbook.catch_up_days', 7));
        $running = TaskSeries::query()->where('active', true)->whereNull('paused_at')->whereNull('ended_at')->get();
        foreach ($running as $series) {
            $tz = $series->timezone;
            $local = $now->setTimezone($tz);
            $from = $series->generate_from ?? $series->created_at;
            $dates = Recurrence::dates($series->frequency, $series->weekdays, $series->day_of_month, $series->starts_on, $series->ends_on, $local->subDays($lookback), $local->addDay(), $tz);
            if ($dates === []) {
                continue;
            }
            $existing = Task::query()->where('series_id', $series->id)->where('occurrence_on', '>=', $dates[0]->toDateString())
                ->pluck('occurrence_on')->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())->flip();
            foreach ($dates as $date) {
                $startsAt = Recurrence::at($date, $series->start_time, $tz);
                $dueAt = Recurrence::at($series->due_time <= $series->start_time ? $date->addDay() : $date, $series->due_time, $tz);
                // Within the next day, and not already over when the series was set up or resumed.
                if ($startsAt->gt($now->addDay()) || ($from !== null && $dueAt->lt($from))) {
                    continue;
                }
                if ($existing->has($date->toDateString())) {
                    continue;
                }
                $member = Member::of($series->assignee_id);
                if ($member === null || ! self::reaches($series, $member)) {
                    Notifications::tell($series->created_by, [
                        'category' => 'task', 'event' => 'task_series.blocked',
                        'title' => "Recurring task not made: {$series->title}",
                        'body' => self::name($series->assignee_id).' no longer reaches what this task is about. Choose another person.',
                        'subject_type' => 'task_series', 'subject_id' => $series->id,
                        'link' => 'tasks?tab=recurring', 'dedupe' => "series:{$series->id}:blocked:{$local->toDateString()}", 'email' => true,
                    ]);

                    continue;
                }
                try {
                    DB::transaction(function () use ($series, $date, $startsAt, $dueAt, &$made) {
                        $task = Task::query()->create([
                            'reference' => References::next(References::TASKS['sequence'], References::TASKS['prefix']),
                            'title' => $series->title,
                            'description' => $series->description,
                            'priority' => $series->priority,
                            'logbook_id' => $series->logbook_id,
                            'log_sheet_id' => $series->log_sheet_id,
                            'resource_id' => $series->resource_id,
                            'series_id' => $series->id,
                            'occurrence_on' => $date->toDateString(),
                            'assignee_id' => $series->assignee_id,
                            'created_by' => $series->created_by,
                            'verification_required' => $series->verification_required,
                            'evidence_required' => $series->evidence_required,
                            'starts_at' => $startsAt,
                            'due_at' => $dueAt,
                            'reminders' => $series->reminders,
                            'reminder_channels' => $series->reminder_channels,
                            'status' => 'pending',
                            'assigned_at' => now(),
                        ]);
                        self::planReminders($task);
                        AuditTrail::record('task.created', $task, null, ['series' => $series->title, 'due' => $dueAt->toIso8601String()]);
                        Notifications::later(self::class, 'notifyAssigned', [$task->id, null, 'task.assigned']);
                        $made++;
                    });
                } catch (UniqueConstraintViolationException) {
                    // Made by another run meanwhile.
                }
            }
        }

        return $made;
    }

    // ---- Notifications (run by Notifications::later) ----

    public static function notifyAssigned(string $taskId, ?string $actorId, string $event): void
    {
        $task = Task::query()->find($taskId);
        if ($task === null || ! $task->isOpen()) {
            return;
        }
        $due = $task->due_at !== null ? ' Due '.self::when($task->due_at).'.' : '';
        Notifications::tell($task->assignee_id, [
            'category' => 'task', 'event' => $event,
            'title' => ($event === 'task.reassigned' ? 'Task passed to you: ' : 'New task: ').$task->reference,
            'body' => $task->title.'.'.$due,
            'subject_type' => 'task', 'subject_id' => $task->id, 'link' => "tasks/{$task->id}",
            'dedupe' => "task:{$task->id}:assigned:{$task->assignee_id}:{$task->revision}", 'email' => true,
        ], fn (Member $m) => self::reaches($task, $m), $actorId);
    }

    public static function notifyUnassigned(string $taskId, string $previousId, ?string $actorId): void
    {
        $task = Task::query()->find($taskId);
        if ($task === null) {
            return;
        }
        Notifications::tell($previousId, [
            'category' => 'task', 'event' => 'task.unassigned',
            'title' => "{$task->reference} was given to someone else",
            'body' => $task->title.'. You no longer need to do it.',
            'subject_type' => 'task', 'subject_id' => $task->id, 'link' => 'tasks',
            'dedupe' => "task:{$task->id}:unassigned:{$previousId}:{$task->revision}",
        ], null, $actorId);
    }

    /** One notice per event, to whoever it concerns. */
    public static function notifyEvent(string $taskId, ?string $actorId, string $event): void
    {
        $task = Task::query()->find($taskId);
        if ($task === null) {
            return;
        }
        [$to, $title, $body, $email] = match ($event) {
            'task.completed' => [[$task->created_by], "{$task->reference} completed", $task->title.' was completed by '.self::name($task->completed_by).'.', false],
            'task.awaiting_verification' => [self::verifiers($task), "{$task->reference} waits for verification", $task->title.' was reported done by '.self::name($task->completed_by).'.', true],
            'task.verified' => [[$task->assignee_id], "{$task->reference} verified", $task->title.' was verified.', false],
            'task.returned' => [[$task->assignee_id], "{$task->reference} returned for correction", $task->return_comment, true],
            'task.cancelled' => [[$task->assignee_id], "{$task->reference} cancelled", $task->cancel_reason, true],
            'task.reopened' => [[$task->assignee_id], "{$task->reference} reopened", $task->title.' needs work again.', true],
            'task.rescheduled' => [[$task->assignee_id], "{$task->reference} rescheduled", $task->due_at !== null ? 'Now due '.self::when($task->due_at).'.' : 'It no longer has a due time.', false],
            default => [[], '', null, false],
        };
        foreach (array_unique($to) as $userId) {
            Notifications::tell($userId, [
                'category' => 'task', 'event' => $event, 'title' => $title, 'body' => $body,
                'subject_type' => 'task', 'subject_id' => $task->id, 'link' => "tasks/{$task->id}",
                'dedupe' => "task:{$task->id}:{$event}:{$task->revision}", 'email' => $email,
            ], fn (Member $m) => $m->id === $task->assignee_id || $m->id === $task->created_by || self::manages($task, $m), $actorId);
        }
    }

    /** A due reminder of this task: sent to the assignee as they are now. */
    public static function remind(Reminder $reminder): string
    {
        $task = Task::query()->find($reminder->subject_id);
        if ($task === null || ! $task->isOpen()) {
            return 'skipped: finished';
        }
        if ($reminder->kind === 'start' && $task->status !== 'pending') {
            return 'skipped: started';
        }
        $title = match ($reminder->kind) {
            'start' => "{$task->reference} starts now",
            'before' => "{$task->reference} is due in ".Reminders::relative($reminder->offset_minutes),
            'due' => "{$task->reference} is due now",
            'after' => "{$task->reference} is overdue",
            default => "Reminder: {$task->reference}",
        };
        $sent = Notifications::notify($task->assignee_id, [
            'category' => 'task', 'event' => 'task.reminder.'.$reminder->kind, 'title' => $title, 'body' => $task->title,
            'subject_type' => 'task', 'subject_id' => $task->id, 'link' => "tasks/{$task->id}",
            'dedupe' => "reminder:{$reminder->id}", 'channels' => Reminders::channels($task->reminder_channels, $reminder->kind),
        ], fn (Member $m) => self::reaches($task, $m));

        if ($reminder->kind === 'after' && $reminder->offset_minutes === 0) {
            // The person who set the task hears of it once too.
            Notifications::notify($task->created_by, [
                'category' => 'task', 'event' => 'task.overdue', 'title' => "{$task->reference} is overdue",
                'body' => $task->title.' · '.self::name($task->assignee_id),
                'subject_type' => 'task', 'subject_id' => $task->id, 'link' => "tasks/{$task->id}",
                'dedupe' => "reminder:{$reminder->id}:creator",
            ], fn (Member $m) => self::manages($task, $m), $task->assignee_id);
        }

        return $sent !== null ? 'sent' : 'skipped: no access';
    }

    // ---- Helpers ----

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'in_progress' => 'in progress',
            'awaiting_verification' => 'awaiting verification',
            'returned' => 'returned for correction',
            default => $status,
        };
    }

    /**
     * Validated, normalized task fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function fields(array $data): array
    {
        $errors = [];
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 160) {
            $errors['title'] = 'A title of up to 160 characters.';
        }
        $priority = $data['priority'] ?? 'normal';
        if (! in_array($priority, Task::PRIORITIES, true)) {
            $errors['priority'] = 'Choose a priority.';
        }
        $assignee = is_string($data['assignee_id'] ?? null) ? $data['assignee_id'] : null;
        if ($assignee === null || ! WorkspaceMember::query()->ofUser($assignee)->exists()) {
            $errors['assignee_id'] = 'Choose someone who uses Logbook here.';
        }
        $startsAt = self::moment($data['starts_at'] ?? null);
        $dueAt = self::moment($data['due_at'] ?? null);
        if ($startsAt !== null && $dueAt !== null && $dueAt->lt($startsAt)) {
            $errors['due_at'] = 'The due time is before the start.';
        }
        $tokens = array_values(array_filter((array) ($data['reminders'] ?? []), 'is_string'));
        $errors += Reminders::errors($tokens, $startsAt, $dueAt);

        // What the task is about: each link must exist here (tenant scoped); the chain is made consistent.
        $entryId = self::id($data['log_entry_id'] ?? null);
        $sheetId = self::id($data['log_sheet_id'] ?? null);
        $logbookId = self::id($data['logbook_id'] ?? null);
        $exceptionId = self::id($data['exception_id'] ?? null);
        if ($entryId !== null) {
            $entry = Access::entries()->whereKey($entryId)->first();
            if ($entry === null) {
                $errors['log_entry_id'] = 'Choose an entry you can read.';
            } else {
                [$sheetId, $logbookId] = [$entry->log_sheet_id, $entry->logbook_id];
            }
        }
        if ($sheetId !== null && $entryId === null) {
            $sheet = Access::sheets()->whereKey($sheetId)->first();
            if ($sheet === null) {
                $errors['log_sheet_id'] = 'Choose a log sheet you reach.';
            } else {
                $logbookId = $sheet->logbook_id;
            }
        }
        if ($logbookId !== null && ! Access::reachesLogbook($logbookId)) {
            $errors['logbook_id'] = 'Choose a logbook you reach.';
        }
        if ($exceptionId !== null && ! Exceptions::visible()->whereKey($exceptionId)->exists()) {
            $errors['exception_id'] = 'Choose an exception you can see.';
        }
        // ADR-0031: a corrective task follows the asset of its exception or entry unless another is named.
        $resourceId = self::id($data['resource_id'] ?? null)
            ?? ($exceptionId !== null ? LogException::query()->whereKey($exceptionId)->value('resource_id') : null)
            ?? ($entryId !== null ? Entry::query()->whereKey($entryId)->value('resource_id') : null);
        if ($resourceId !== null && ! LogResource::query()->whereKey($resourceId)->exists()) {
            $errors['resource_id'] = 'Choose a resource of this workspace.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'title' => $title,
            'description' => self::text($data['description'] ?? null),
            'priority' => $priority,
            'assignee_id' => $assignee,
            'logbook_id' => $logbookId,
            'log_sheet_id' => $sheetId,
            'log_entry_id' => $entryId,
            'exception_id' => $exceptionId,
            'resource_id' => $resourceId,
            'starts_at' => $startsAt,
            'due_at' => $dueAt,
            'reminders' => $tokens,
            'reminder_channels' => Reminders::channelsInput($data['reminder_channels'] ?? null),
            'verification_required' => (bool) ($data['verification_required'] ?? false),
            'evidence_required' => (bool) ($data['evidence_required'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private static function current(Task $task): array
    {
        return [
            'title' => $task->title, 'description' => $task->description, 'priority' => $task->priority,
            'assignee_id' => $task->assignee_id, 'logbook_id' => $task->logbook_id, 'log_sheet_id' => $task->log_sheet_id,
            'log_entry_id' => $task->log_entry_id, 'exception_id' => $task->exception_id, 'resource_id' => $task->resource_id,
            'starts_at' => $task->starts_at?->toIso8601String(), 'due_at' => $task->due_at?->toIso8601String(),
            'reminders' => $task->reminders ?? [], 'reminder_channels' => $task->reminder_channels,
            'verification_required' => $task->verification_required, 'evidence_required' => $task->evidence_required,
        ];
    }

    private static function mustBeMember(string $userId): void
    {
        if (! WorkspaceMember::query()->ofUser($userId)->exists()) {
            throw ValidationException::withMessages(['assignee_id' => 'Choose someone who uses Logbook here.']);
        }
    }

    /** The assignee must reach what the task is about; a restricted entry is never opened by a task. */
    private static function mustReach(Task $task, string $userId): void
    {
        $member = Member::of($userId);
        if ($member === null) {
            throw ValidationException::withMessages(['assignee_id' => self::name($userId).' has no access to Logbook in this workspace.']);
        }
        if (! self::reaches($task, $member)) {
            throw ValidationException::withMessages(['assignee_id' => self::name($userId).' does not reach the logbook, log sheet or entry of this task. Give them access first.']);
        }
    }

    private static function lock(Task $task, ?int $revision): Task
    {
        $task = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
        if ($revision !== null && $task->revision !== $revision) {
            throw new StaleRecord($task->revision, 'This task was changed since you opened it.');
        }

        return $task;
    }

    private static function lockForAssignee(Task $task, User $by, ?int $revision): Task
    {
        $task = self::lock($task, $revision);
        if ($task->assignee_id !== $by->id) {
            throw new StaleRecord($task->revision, 'This task is no longer assigned to you.', 'not_assignee');
        }

        return $task;
    }

    private static function planReminders(Task $task): void
    {
        if ($task->isOpen()) {
            Reminders::plan('task', $task->id, $task->reminders ?? [], $task->starts_at, $task->due_at);
        } else {
            Reminders::cancel('task', $task->id);
        }
    }

    /**
     * People who may verify: task managers reaching it, never the one who did it.
     *
     * @return list<string>
     */
    private static function verifiers(Task $task): array
    {
        $ids = Notifications::holders(self::MANAGE, fn (Member $m) => self::manages($task, $m));
        $ids[] = $task->created_by;

        return array_values(array_diff(array_unique($ids), [$task->assignee_id, (string) $task->completed_by]));
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private static function changes(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $out[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }

        return $out;
    }

    public static function name(?string $userId): string
    {
        return $userId === null ? 'Nobody' : (string) (User::query()->whereKey($userId)->value('name') ?? 'Someone');
    }

    private static function when(CarbonImmutable $at): string
    {
        $tz = Workspace::current()->timezone ?? 'UTC';

        return $at->setTimezone($tz)->format('j M Y, H:i');
    }

    private static function moment(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            // A time without an offset (a datetime-local field) is the workspace's.
            return CarbonImmutable::parse($value, Workspace::current()->timezone ?? 'UTC')->utc();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['due_at' => 'A date and time such as 2026-11-21 08:00.']);
        }
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, 5000);
    }
}
