<?php

namespace App\Logbook;

use App\Logbook\Models\Entry;
use App\Logbook\Models\LogException;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\Reminder;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exceptions and their follow-up (ADR-0026 §2.7): open, in progress,
 * resolved, closed; reopened when needed.
 *
 * - Raised on submission when a field asks for it (a number outside its
 *   normal range with raise_exception, or a yes/no or single choice
 *   answer listed in exception_values), once per entry and field; or
 *   reported by hand, from an entry or alone.
 * - Assigned and closed by holders of logbook.tasks.manage; worked on and
 *   resolved by the assignee (or a manager). Whoever resolved it does not
 *   close it.
 * - The entry and its reading never change. Corrective action is a task
 *   linked to the exception.
 */
final class Exceptions
{
    /**
     * Exceptions a member may see: about an entry they can read, a logbook
     * they reach, or none (for task managers); and always the ones they
     * raised or hold.
     *
     * @return Builder<LogException>
     */
    public static function visible(?Member $member = null): Builder
    {
        $member ??= Member::current();

        return LogException::query()->where(function (Builder $q) use ($member) {
            $q->where('assignee_id', $member->id)->orWhere('raised_by', $member->id)
                ->orWhere(fn (Builder $e) => $e->whereNotNull('log_entry_id')->whereIn('log_entry_id', Access::entries($member)->select('id')))
                ->orWhere(fn (Builder $l) => $l->whereNull('log_entry_id')->whereNotNull('logbook_id')->whereIn('logbook_id', Access::logbooks($member)->select('id')));
            // Exceptions about no logbook belong to the whole workspace.
            if ($member->everywhere(Tasks::MANAGE)) {
                $q->orWhereNull('logbook_id');
            }
        });
    }

    public static function find(string $id): LogException
    {
        return self::visible()->findOrFail($id);
    }

    public static function manages(LogException $exception, ?Member $member = null): bool
    {
        $member ??= Member::current();

        return $member->can(Tasks::MANAGE) && ($exception->logbook_id === null
            ? $member->everywhere(Tasks::MANAGE)
            : Access::reachesLogbook($exception->logbook_id, $member));
    }

    public static function reaches(LogException $exception, Member $member): bool
    {
        // About an entry: whoever reads it, or works in its logbook (a contributor
        // following up a colleague's reading does not read the entry itself).
        if ($exception->log_entry_id !== null && Access::entries($member)->whereKey($exception->log_entry_id)->exists()) {
            return true;
        }

        return $exception->logbook_id === null
            ? $member->everywhere(Tasks::MANAGE) || $exception->log_entry_id === null
            : Access::reachesLogbook($exception->logbook_id, $member);
    }

    /**
     * A problem reported by a person, from an entry (that they can read)
     * or about a logbook.
     *
     * @param  array<string, mixed>  $data  title, description, severity, log_entry_id, logbook_id, resource_id, field, assignee_id, due_at
     */
    public static function report(array $data, User $by): LogException
    {
        $errors = [];
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 160) {
            $errors['title'] = 'A title of up to 160 characters.';
        }
        $severity = $data['severity'] ?? 'medium';
        if (! in_array($severity, LogException::SEVERITIES, true)) {
            $errors['severity'] = 'Choose a severity.';
        }
        $entry = null;
        $logbookId = is_string($data['logbook_id'] ?? null) && $data['logbook_id'] !== '' ? $data['logbook_id'] : null;
        if (is_string($data['log_entry_id'] ?? null) && $data['log_entry_id'] !== '') {
            $entry = Access::entries()->where('status', '!=', 'draft')->whereKey($data['log_entry_id'])->first();
            if ($entry === null) {
                $errors['log_entry_id'] = 'Choose a submitted entry you can read.';
            } else {
                $logbookId = $entry->logbook_id;
            }
        }
        if ($logbookId !== null && ! Access::reachesLogbook($logbookId)) {
            $errors['logbook_id'] = 'Choose a logbook you reach.';
        }
        if ($logbookId === null && ! Member::current()->can(Tasks::MANAGE)) {
            $errors['logbook_id'] = 'Choose the logbook this is about.';
        }
        $resourceId = is_string($data['resource_id'] ?? null) && $data['resource_id'] !== '' ? $data['resource_id'] : ($entry?->resource_id);
        if ($resourceId !== null && ! LogResource::query()->whereKey($resourceId)->exists()) {
            $errors['resource_id'] = 'Choose a resource of this workspace.';
        }
        $field = null;
        if ($entry !== null && is_string($data['field'] ?? null) && $data['field'] !== '') {
            $field = Fields::byName($entry->version->fields)[$data['field']] ?? null;
            if ($field === null) {
                $errors['field'] = 'This entry has no such field.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($data, $by, $title, $severity, $entry, $logbookId, $resourceId, $field) {
            $exception = LogException::query()->create([
                'reference' => References::next(References::EXCEPTIONS['sequence'], References::EXCEPTIONS['prefix']),
                'title' => $title,
                'description' => self::text($data['description'] ?? null),
                'source' => 'manual',
                'severity' => $severity,
                'logbook_id' => $logbookId,
                'log_sheet_id' => $entry?->log_sheet_id,
                'log_entry_id' => $entry?->id,
                'resource_id' => $resourceId,
                'field' => $field['name'] ?? null,
                'field_label' => $field['label'] ?? null,
                'recorded_value' => $field !== null ? mb_substr((string) Fields::text($field, $entry->values[$field['name']] ?? null), 0, 500) : null,
                'raised_by' => $by->id,
            ]);
            AuditTrail::record('exception.raised', $exception, $by, ['severity' => $severity, 'entry' => $entry?->reference]);
            Notifications::later(self::class, 'notifyRaised', [$exception->id, $by->id]);

            if (is_string($data['assignee_id'] ?? null) && $data['assignee_id'] !== '' && self::manages($exception)) {
                return self::assign($exception, $data['assignee_id'], $data['due_at'] ?? null, $by);
            }

            return $exception;
        });
    }

    /**
     * Exceptions a submission raises by the sheet's rules. Called inside
     * the submitting transaction; one per entry and field, ever (a
     * resubmission with the same problem adds nothing).
     */
    public static function fromSubmission(Entry $entry): void
    {
        $fields = $entry->version->fields;
        $deviations = array_column(Fields::deviations($fields, $entry->values), null, 'name');
        foreach ($fields as $field) {
            $value = $entry->values[$field['name']] ?? null;
            [$source, $expected] = match (true) {
                ($field['raise_exception'] ?? false) === true && isset($deviations[$field['name']]) => ['reading', 'Normal range '.$deviations[$field['name']]['range']],
                is_array($field['exception_values'] ?? null) && $value !== null && self::listed($field, $value) => ['check', 'Not '.self::answers($field, array_values($field['exception_values']))],
                default => [null, null],
            };
            if ($source === null || ! Fields::visible($field, $entry->values, $fields)) {
                continue;
            }
            $key = "{$entry->id}:{$field['name']}";
            if (LogException::query()->where('auto_key', $key)->exists()) {
                continue;
            }
            try {
                DB::transaction(function () use ($entry, $field, $value, $source, $expected, $key) {
                    $exception = LogException::query()->create([
                        'reference' => References::next(References::EXCEPTIONS['sequence'], References::EXCEPTIONS['prefix']),
                        'title' => mb_substr(($source === 'reading' ? 'Abnormal reading: ' : 'Failed check: ').$field['label'], 0, 160),
                        'source' => $source,
                        'severity' => in_array($field['exception_severity'] ?? null, LogException::SEVERITIES, true) ? $field['exception_severity'] : 'medium',
                        'logbook_id' => $entry->logbook_id,
                        'log_sheet_id' => $entry->log_sheet_id,
                        'log_entry_id' => $entry->id,
                        // ADR-0031: the asset the entry is about, so it shows on the asset's page and report.
                        'resource_id' => $entry->resource_id,
                        'field' => $field['name'],
                        'field_label' => mb_substr($field['label'], 0, 160),
                        'recorded_value' => mb_substr((string) Fields::text($field, $value), 0, 500),
                        'expected' => mb_substr((string) $expected, 0, 200),
                        'auto_key' => $key,
                        'raised_by' => $entry->created_by,
                    ]);
                    AuditTrail::record('exception.raised', $exception, null, ['entry' => $entry->reference, 'field' => $field['label'], 'source' => $source]);
                    Notifications::later(self::class, 'notifyRaised', [$exception->id, null]);
                });
            } catch (UniqueConstraintViolationException) {
                // Raised meanwhile.
            }
        }
    }

    public static function assign(LogException $exception, string $assigneeId, mixed $dueAt, User $by, ?int $revision = null): LogException
    {
        return DB::transaction(function () use ($exception, $assigneeId, $dueAt, $by, $revision) {
            $exception = self::lock($exception, $revision);
            if (! $exception->isOpen()) {
                throw ValidationException::withMessages(['assignee_id' => 'Reopen the exception before assigning it.']);
            }
            if (! WorkspaceMember::query()->ofUser($assigneeId)->exists()) {
                throw ValidationException::withMessages(['assignee_id' => 'Choose someone who uses Logbook here.']);
            }
            $member = Member::of($assigneeId);
            if ($member === null || ! self::reaches($exception, $member)) {
                throw ValidationException::withMessages(['assignee_id' => Tasks::name($assigneeId).' does not reach the entry or logbook of this exception.']);
            }
            $due = is_string($dueAt) && $dueAt !== '' ? CarbonImmutable::parse($dueAt, Workspace::current()->timezone ?? 'UTC')->utc() : null;
            $before = ['assignee' => $exception->assignee_id !== null ? Tasks::name($exception->assignee_id) : null, 'due' => $exception->due_at?->toIso8601String()];
            $exception->forceFill(['assignee_id' => $assigneeId, 'due_at' => $due, 'revision' => $exception->revision + 1])->save();
            Reminders::plan('exception', $exception->id, $due !== null ? ['before:1440', 'after:0'] : [], null, $due);
            AuditTrail::record('exception.assigned', $exception, $by, ['from' => $before, 'to' => ['assignee' => Tasks::name($assigneeId), 'due' => $due?->toIso8601String()]]);
            Notifications::later(self::class, 'notifyEvent', [$exception->id, $by->id, 'exception.assigned']);

            return $exception;
        });
    }

    public static function start(LogException $exception, User $by, ?int $revision = null): LogException
    {
        return DB::transaction(function () use ($exception, $by, $revision) {
            $exception = self::lock($exception, $revision);
            self::mustWork($exception, $by);
            if ($exception->status === 'in_progress') {
                return $exception;
            }
            if ($exception->status !== 'open') {
                throw ValidationException::withMessages(['exception' => 'This exception is '.$exception->status.'.']);
            }
            $exception->forceFill(['status' => 'in_progress', 'revision' => $exception->revision + 1])->save();
            AuditTrail::record('exception.started', $exception, $by);

            return $exception;
        });
    }

    public static function resolve(LogException $exception, User $by, ?string $notes, ?int $revision = null): LogException
    {
        $notes = self::text($notes) ?? throw ValidationException::withMessages(['resolution_notes' => 'Say how it was resolved.']);

        return DB::transaction(function () use ($exception, $by, $notes, $revision) {
            $exception = self::lock($exception, $revision);
            self::mustWork($exception, $by);
            if (! $exception->isOpen()) {
                throw ValidationException::withMessages(['exception' => 'This exception is '.$exception->status.'.']);
            }
            $open = $exception->tasks()->whereIn('status', ['pending', 'in_progress', 'returned', 'awaiting_verification'])->count();
            if ($open > 0) {
                throw ValidationException::withMessages(['resolution_notes' => "{$open} corrective ".($open === 1 ? 'task is' : 'tasks are').' still open. Finish or cancel '.($open === 1 ? 'it' : 'them').' first.']);
            }
            $exception->forceFill(['status' => 'resolved', 'resolution_notes' => $notes, 'resolved_at' => now(), 'resolved_by' => $by->id, 'revision' => $exception->revision + 1])->save();
            Reminders::cancel('exception', $exception->id);
            AuditTrail::record('exception.resolved', $exception, $by);
            Notifications::later(self::class, 'notifyEvent', [$exception->id, $by->id, 'exception.resolved']);

            return $exception;
        });
    }

    public static function close(LogException $exception, User $by, ?int $revision = null): LogException
    {
        return DB::transaction(function () use ($exception, $by, $revision) {
            $exception = self::lock($exception, $revision);
            if ($exception->status !== 'resolved') {
                throw ValidationException::withMessages(['exception' => 'Only a resolved exception is closed.']);
            }
            if ($exception->resolved_by === $by->id) {
                throw ValidationException::withMessages(['exception' => 'Someone other than whoever resolved it closes it.']);
            }
            $exception->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by->id, 'revision' => $exception->revision + 1])->save();
            AuditTrail::record('exception.closed', $exception, $by);
            Notifications::later(self::class, 'notifyEvent', [$exception->id, $by->id, 'exception.closed']);

            return $exception;
        });
    }

    public static function reopen(LogException $exception, User $by, ?string $reason, ?int $revision = null): LogException
    {
        $reason = self::text($reason) ?? throw ValidationException::withMessages(['reason' => 'Say why it is reopened.']);

        return DB::transaction(function () use ($exception, $by, $reason, $revision) {
            $exception = self::lock($exception, $revision);
            if ($exception->isOpen()) {
                throw ValidationException::withMessages(['exception' => 'This exception is still open.']);
            }
            $from = $exception->status;
            $exception->forceFill([
                'status' => $exception->assignee_id !== null ? 'in_progress' : 'open',
                'reopened_at' => now(), 'closed_at' => null, 'closed_by' => null, 'resolved_at' => null, 'resolved_by' => null,
                'revision' => $exception->revision + 1,
            ])->save();
            Reminders::plan('exception', $exception->id, $exception->due_at !== null ? ['before:1440', 'after:0'] : [], null, $exception->due_at);
            AuditTrail::record('exception.reopened', $exception, $by, ['from' => $from, 'reason' => $reason]);
            Notifications::later(self::class, 'notifyEvent', [$exception->id, $by->id, 'exception.reopened']);

            return $exception;
        });
    }

    // ---- Notifications ----

    public static function notifyRaised(string $exceptionId, ?string $actorId): void
    {
        $exception = LogException::query()->find($exceptionId);
        if ($exception === null || ! $exception->isOpen()) {
            return;
        }
        $notice = [
            'category' => 'exception', 'event' => 'exception.raised',
            'title' => "{$exception->reference} opened: ".ucfirst($exception->severity).' severity',
            'body' => $exception->title,
            'subject_type' => 'exception', 'subject_id' => $exception->id, 'link' => "exceptions/{$exception->id}",
            'dedupe' => "exception:{$exception->id}:raised", 'email' => in_array($exception->severity, ['high', 'critical'], true),
        ];
        foreach (Notifications::holders(Tasks::MANAGE, fn (Member $m) => self::manages($exception, $m)) as $userId) {
            Notifications::tell($userId, $notice, null, $actorId);
        }
    }

    public static function notifyEvent(string $exceptionId, ?string $actorId, string $event): void
    {
        $exception = LogException::query()->find($exceptionId);
        if ($exception === null) {
            return;
        }
        [$to, $title, $body, $email] = match ($event) {
            'exception.assigned' => [[$exception->assignee_id], "{$exception->reference} assigned to you", $exception->title.($exception->due_at !== null ? ' · due '.$exception->due_at->setTimezone(Workspace::current()->timezone ?? 'UTC')->format('j M Y, H:i') : ''), true],
            'exception.resolved' => [[$exception->raised_by], "{$exception->reference} resolved", $exception->title, false],
            'exception.closed' => [[$exception->assignee_id, $exception->raised_by], "{$exception->reference} closed", $exception->title, false],
            'exception.reopened' => [[$exception->assignee_id], "{$exception->reference} reopened", $exception->title, true],
            default => [[], '', null, false],
        };
        foreach (array_unique(array_filter($to)) as $userId) {
            Notifications::tell((string) $userId, [
                'category' => 'exception', 'event' => $event, 'title' => $title, 'body' => $body,
                'subject_type' => 'exception', 'subject_id' => $exception->id, 'link' => "exceptions/{$exception->id}",
                'dedupe' => "exception:{$exception->id}:{$event}:{$exception->revision}", 'email' => $email,
            ], fn (Member $m) => self::reaches($exception, $m) || $m->id === $exception->assignee_id || $m->id === $exception->raised_by, $actorId);
        }
    }

    public static function remind(Reminder $reminder): string
    {
        $exception = LogException::query()->find($reminder->subject_id);
        if ($exception === null || ! $exception->isOpen() || $exception->assignee_id === null) {
            return 'skipped: done';
        }
        $sent = Notifications::notify($exception->assignee_id, [
            'category' => 'exception', 'event' => 'exception.reminder.'.$reminder->kind,
            'title' => $reminder->kind === 'after' ? "{$exception->reference} is overdue" : "{$exception->reference} is due in ".Reminders::relative($reminder->offset_minutes),
            'body' => $exception->title,
            'subject_type' => 'exception', 'subject_id' => $exception->id, 'link' => "exceptions/{$exception->id}",
            'dedupe' => "reminder:{$reminder->id}", 'email' => $reminder->kind === 'after',
        ], fn (Member $m) => self::reaches($exception, $m));

        return $sent !== null ? 'sent' : 'skipped: no access';
    }

    // ---- Helpers ----

    /** @param array<string, mixed> $field */
    private static function listed(array $field, mixed $value): bool
    {
        $listed = array_map('strval', (array) $field['exception_values']);

        return match ($field['type']) {
            'yes_no' => in_array($value === true ? 'yes' : ($value === false ? 'no' : (string) $value), $listed, true),
            'select_one' => in_array((string) $value, $listed, true),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<mixed>  $values
     */
    private static function answers(array $field, array $values): string
    {
        $labels = [];
        foreach ($values as $value) {
            $labels[] = match ($field['type']) {
                'yes_no' => $value === 'yes' ? 'Yes' : 'No',
                default => (array_column($field['choices'] ?? [], 'label', 'value')[$value] ?? (string) $value),
            };
        }

        return implode(' or ', $labels);
    }

    private static function lock(LogException $exception, ?int $revision): LogException
    {
        $exception = LogException::query()->whereKey($exception->id)->lockForUpdate()->firstOrFail();
        if ($revision !== null && $exception->revision !== $revision) {
            throw new StaleRecord($exception->revision, 'This exception was changed since you opened it.');
        }

        return $exception;
    }

    private static function mustWork(LogException $exception, User $by): void
    {
        if ($exception->assignee_id !== $by->id && ! self::manages($exception)) {
            abort(403, 'Only the person it is assigned to, or a manager, works on this exception.');
        }
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, 5000);
    }
}
