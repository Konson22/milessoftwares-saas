<?php

namespace App\Http\Controllers\Concerns;

use App\Logbook\Access;
use App\Logbook\Exceptions;
use App\Logbook\Member;
use App\Logbook\Models\AuditEvent;
use App\Logbook\Models\LogException;
use App\Logbook\Models\ScheduleOccurrence;
use App\Logbook\Models\Task;
use App\Logbook\Models\TaskSeries;
use App\Logbook\Models\WorkFile;
use App\Logbook\Tasks;
use App\Logbook\WorkFiles;
use Carbon\CarbonImmutable;

/**
 * Tasks, exceptions and expected entries as the web pages and the phone
 * read them. A linked entry is named only to those who can read it: a
 * task never opens a restricted entry.
 */
trait PresentsWork
{
    /**
     * @return array<string, mixed>
     */
    protected static function taskRow(Task $task, ?CarbonImmutable $now = null): array
    {
        return [
            'id' => $task->id,
            'reference' => $task->reference,
            'title' => $task->title,
            'priority' => $task->priority,
            'status' => $task->status,
            'overdue' => $task->overdue($now),
            'assignee' => ['id' => $task->assignee_id, 'name' => $task->assignee->name],
            'creator' => $task->creator->name,
            'logbook' => $task->logbook?->name,
            'sheet' => $task->sheet !== null ? ['id' => $task->sheet->id, 'name' => $task->sheet->name, 'code' => $task->sheet->code] : null,
            'resource' => $task->resource?->label(),
            'startsAt' => self::moment($task->starts_at),
            'dueAt' => self::moment($task->due_at),
            'completedAt' => self::moment($task->completed_at),
            'recurring' => $task->series_id !== null,
            'verificationRequired' => $task->verification_required,
            'revision' => $task->revision,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function taskDetail(Task $task, Member $viewer): array
    {
        $task->loadMissing(['assignee', 'creator', 'logbook', 'sheet', 'resource', 'files', 'exception']);
        $readable = fn (?string $entryId) => $entryId !== null && Access::entries($viewer)->whereKey($entryId)->exists();
        $entry = $task->log_entry_id !== null && $readable($task->log_entry_id) ? $task->entry : null;
        $completion = $task->completion_entry_id !== null && $readable($task->completion_entry_id) ? $task->completionEntry : null;
        $manages = Tasks::manages($task, $viewer);
        $mine = $task->assignee_id === $viewer->id;

        return [
            ...self::taskRow($task),
            'description' => $task->description,
            'evidenceRequired' => $task->evidence_required,
            'reminders' => $task->reminders ?? [],
            'reminderChannels' => $task->reminder_channels,
            // The phone's time for the work, and when the server heard of it.
            'completionReceivedAt' => self::moment($task->completion_received_at),
            'entry' => $entry !== null ? ['id' => $entry->id, 'reference' => $entry->reference] : ($task->log_entry_id !== null ? ['id' => null, 'reference' => 'An entry you cannot open'] : null),
            'exception' => $task->exception !== null && Exceptions::visible($viewer)->whereKey($task->exception->id)->exists()
                ? ['id' => $task->exception->id, 'reference' => $task->exception->reference, 'title' => $task->exception->title] : null,
            'completion' => [
                'notes' => $task->completion_notes,
                'entry' => $completion !== null ? ['id' => $completion->id, 'reference' => $completion->reference] : null,
                'by' => $task->completed_by !== null ? Tasks::name($task->completed_by) : null,
                'verifiedAt' => self::moment($task->verified_at),
                'verifiedBy' => $task->verified_by !== null ? Tasks::name($task->verified_by) : null,
                'returnComment' => $task->return_comment,
            ],
            'cancelled' => $task->cancelled_at !== null ? ['at' => self::moment($task->cancelled_at), 'by' => Tasks::name($task->cancelled_by), 'reason' => $task->cancel_reason] : null,
            'assignedAt' => self::moment($task->assigned_at),
            'startedAt' => self::moment($task->started_at),
            'reopenedAt' => self::moment($task->reopened_at),
            'files' => $task->files->map(fn (WorkFile $f) => WorkFiles::row($f))->values()->all(),
            'can' => [
                'manage' => $manages,
                'work' => $mine && $task->isOpen(),
                'start' => $mine && in_array($task->status, ['pending', 'returned'], true),
                'complete' => $mine && $task->isOpen(),
                'verify' => $manages && $task->status === 'awaiting_verification' && ! $mine && $task->completed_by !== $viewer->id,
                'reassign' => $manages && $task->isOpen(),
                'cancel' => $manages && ! in_array($task->status, ['completed', 'cancelled'], true),
                'reopen' => $manages && in_array($task->status, ['completed', 'cancelled', 'awaiting_verification'], true),
                'edit' => $manages && ! in_array($task->status, ['completed', 'cancelled'], true),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function seriesRow(TaskSeries $series): array
    {
        return [
            'id' => $series->id,
            'title' => $series->title,
            'description' => $series->description,
            'priority' => $series->priority,
            'assignee' => ['id' => $series->assignee_id, 'name' => $series->assignee->name],
            'logbookId' => $series->logbook_id,
            'logbook' => $series->logbook?->name,
            'sheetId' => $series->log_sheet_id,
            'resourceId' => $series->resource_id,
            'frequency' => $series->frequency,
            'weekdays' => $series->weekdays ?? [],
            'dayOfMonth' => $series->day_of_month,
            'startTime' => $series->start_time,
            'dueTime' => $series->due_time,
            'timezone' => $series->timezone,
            'startsOn' => $series->starts_on->toDateString(),
            'endsOn' => $series->ends_on?->toDateString(),
            'reminders' => $series->reminders ?? [],
            'verificationRequired' => $series->verification_required,
            'evidenceRequired' => $series->evidence_required,
            'reminderChannels' => $series->reminder_channels,
            'active' => $series->active,
            // active, paused or ended (ADR-0027 §2.1).
            'state' => Tasks::seriesState($series),
            'pausedAt' => self::moment($series->paused_at),
            'endedAt' => self::moment($series->ended_at),
            'nextDueAt' => self::moment(Tasks::nextDue($series)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function exceptionRow(LogException $exception, ?CarbonImmutable $now = null): array
    {
        return [
            'id' => $exception->id,
            'reference' => $exception->reference,
            'title' => $exception->title,
            'source' => $exception->source,
            'severity' => $exception->severity,
            'status' => $exception->status,
            'overdue' => $exception->overdue($now),
            'logbook' => $exception->logbook?->name,
            'sheet' => $exception->sheet !== null ? ['id' => $exception->sheet->id, 'name' => $exception->sheet->name, 'code' => $exception->sheet->code] : null,
            'assignee' => $exception->assignee !== null ? ['id' => $exception->assignee->id, 'name' => $exception->assignee->name] : null,
            'dueAt' => self::moment($exception->due_at),
            'createdAt' => self::moment($exception->created_at),
            'resolvedAt' => self::moment($exception->resolved_at),
            'revision' => $exception->revision,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function occurrenceRow(ScheduleOccurrence $occurrence, ?CarbonImmutable $now = null): array
    {
        return [
            'id' => $occurrence->id,
            'state' => $occurrence->state($now),
            'occursOn' => $occurrence->occurs_on->toDateString(),
            'opensAt' => self::moment($occurrence->opens_at),
            'dueAt' => self::moment($occurrence->due_at),
            'closesAt' => self::moment($occurrence->closes_at),
            'sheet' => ['id' => $occurrence->sheet->id, 'name' => $occurrence->sheet->name, 'code' => $occurrence->sheet->code],
            'logbook' => $occurrence->logbook->name,
            // ADR-0031: the asset this reading is expected for.
            'resourceId' => $occurrence->resource_id,
            'resource' => $occurrence->resource_id !== null ? $occurrence->resource?->label() : null,
            'responsible' => $occurrence->responsible?->name,
            'entry' => $occurrence->entry !== null ? ['id' => $occurrence->entry->id, 'reference' => $occurrence->entry->reference] : null,
            'submittedAt' => self::moment($occurrence->submitted_at),
            'reviewedAt' => self::moment($occurrence->reviewed_at),
            // on_time or late, apart from the review.
            'timeliness' => $occurrence->timeliness,
            'excuseReason' => $occurrence->excuse_reason,
        ];
    }

    /**
     * The audit events of one record, oldest first: its history.
     *
     * @return list<array<string, mixed>>
     */
    protected static function history(string $type, string $id): array
    {
        return array_values(AuditEvent::query()->where('subject_type', $type)->where('subject_id', $id)
            ->orderBy('created_at')->limit(200)->get()
            ->map(fn (AuditEvent $e) => ['id' => $e->id, 'action' => $e->action, 'user' => $e->user_name, 'at' => self::moment($e->created_at), 'details' => $e->details])
            ->all());
    }

    protected static function moment(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }
}
