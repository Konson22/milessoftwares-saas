<?php

namespace App\Logbook;

use App\Logbook\Models\Dispatch;
use App\Logbook\Models\Entry;
use App\Logbook\Models\LogSheet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Trips a manager plans (ADR-0032 §4). A planned trip names the sheet,
 * the vehicle, the driver and the day; for that day it counts as an
 * assignment of the vehicle to the driver. The driver starts it, which
 * makes its entry; recording the entry finishes it.
 *
 * planned → started (entry saved as a draft) → done (entry recorded);
 * planned → cancelled by a manager. Discarding the draft puts it back to
 * planned.
 */
final class Dispatches
{
    public const LABELS = ['planned' => 'Planned', 'started' => 'Started', 'done' => 'Done', 'cancelled' => 'Cancelled'];

    /**
     * Plans a trip on a sheet the manager manages.
     *
     * @param  array<string, mixed>  $data
     */
    public static function plan(LogSheet $sheet, array $data, User $by): Dispatch
    {
        $errors = [];
        $version = $sheet->publishedVersion;
        $subject = Recording::subjectField($version);
        if (! $sheet->open() || $version === null) {
            $errors['sheet_id'] = 'This log sheet is not accepting entries.';
        } elseif ($subject === null) {
            $errors['sheet_id'] = 'This log sheet names no vehicle or other asset: add a Resource field and choose it as the main asset.';
        }
        $resourceId = is_string($data['resource_id'] ?? null) ? $data['resource_id'] : '';
        $userId = is_string($data['user_id'] ?? null) ? $data['user_id'] : '';
        if ($errors === []) {
            $field = collect($version->fields)->firstWhere('name', $subject);
            $linked = SheetAssets::linkedIds($sheet->id);
            if ($resourceId === '' || ! SheetAssets::allows($resourceId, $field['resource_type'] ?? null, true, $linked)) {
                $errors['resource_id'] = 'Choose an active vehicle this log sheet records.';
            }
            $member = $userId === '' ? null : Member::of($userId);
            if ($member === null || ! Access::reachesSheet($sheet, 'contribute', $member)) {
                $errors['user_id'] = 'Choose a person who writes on this log sheet.';
            }
        }
        $day = is_string($data['planned_on'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['planned_on']) === 1 ? $data['planned_on'] : null;
        if ($day === null || ! checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))) {
            $errors['planned_on'] = 'Give the day of the trip.';
        } elseif ($day < Assignments::today()) {
            $errors['planned_on'] = 'The day has passed.';
        }
        $time = $data['planned_time'] ?? null;
        $time = is_string($time) && $time !== '' ? $time : null;
        if ($time !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            $errors['planned_time'] = 'Give the time as hours and minutes.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $text = fn (string $key, int $max) => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? mb_substr(trim($data[$key]), 0, $max) : null;

        $dispatch = DB::transaction(function () use ($sheet, $resourceId, $userId, $day, $time, $text, $by) {
            $dispatch = Dispatch::query()->create([
                'logbook_id' => $sheet->logbook_id,
                'log_sheet_id' => $sheet->id,
                'log_resource_id' => $resourceId,
                'user_id' => $userId,
                'planned_on' => $day,
                'planned_time' => $time,
                'destination' => $text('destination', 200),
                'purpose' => $text('purpose', 500),
                'created_by' => $by->id,
            ]);
            AuditTrail::record('dispatch.planned', $dispatch, $by, [
                'sheet' => $sheet->code, 'resource_id' => $resourceId, 'user_id' => $userId, 'user' => Tasks::name($userId), 'day' => $day,
            ]);
            Notifications::later(self::class, 'notifyPlanned', [$dispatch->id, $by->id]);

            return $dispatch;
        });

        return $dispatch;
    }

    /** Cancels a trip not started yet. */
    public static function cancel(Dispatch $dispatch, ?string $reason, User $by): Dispatch
    {
        return DB::transaction(function () use ($dispatch, $reason, $by) {
            $dispatch = Dispatch::query()->lockForUpdate()->findOrFail($dispatch->id);
            if ($dispatch->status !== 'planned') {
                throw ValidationException::withMessages(['dispatch' => $dispatch->status === 'cancelled'
                    ? 'This trip is already cancelled.'
                    : 'The driver has started this trip; it can no longer be cancelled.']);
            }
            $reason = is_string($reason) && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null;
            $dispatch->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => $reason])->save();
            AuditTrail::record('dispatch.cancelled', $dispatch, $by, ['reason' => $reason]);
            Notifications::later(self::class, 'notifyCancelled', [$dispatch->id, $by->id]);

            return $dispatch;
        });
    }

    /**
     * The planned trip an entry being made is for: the author's own, on
     * this sheet, still open, with no entry yet. Locked.
     */
    public static function forNewEntry(string $dispatchId, LogSheet $sheet, User $by): Dispatch
    {
        $dispatch = Dispatch::query()->lockForUpdate()->whereKey($dispatchId)->where('log_sheet_id', $sheet->id)->where('user_id', $by->id)->first();
        if ($dispatch === null || ! $dispatch->open() || $dispatch->log_entry_id !== null) {
            throw ValidationException::withMessages(['dispatch_id' => 'This planned trip is not yours to record, or it was already recorded or cancelled.']);
        }

        return $dispatch;
    }

    /** The entry was made: the trip is started (or done, when recorded at once). */
    public static function started(Dispatch $dispatch, Entry $entry): void
    {
        $dispatch->forceFill(['status' => 'started', 'log_entry_id' => $entry->id])->save();
    }

    /** Its entry was recorded. */
    public static function finished(Entry $entry): void
    {
        if ($entry->dispatch_id !== null) {
            Dispatch::query()->whereKey($entry->dispatch_id)->where('status', '!=', 'cancelled')->update(['status' => 'done', 'log_entry_id' => $entry->id]);
        }
    }

    /** Its draft was discarded: planned again. */
    public static function reopened(Entry $entry): void
    {
        if ($entry->dispatch_id !== null) {
            Dispatch::query()->whereKey($entry->dispatch_id)->where('status', 'started')->update(['status' => 'planned', 'log_entry_id' => null]);
        }
    }

    /**
     * At submission: a planned trip is recorded with the vehicle it was
     * planned for.
     *
     * @param  array<string, mixed>  $values
     */
    public static function check(Entry $entry, array $values): void
    {
        if ($entry->dispatch_id === null) {
            return;
        }
        $dispatch = Dispatch::query()->with('resource:id,code')->find($entry->dispatch_id);
        $field = Recording::subjectField($entry->version);
        if ($dispatch === null || $field === null) {
            return;
        }
        if ($dispatch->status === 'cancelled') {
            throw ValidationException::withMessages(['dispatch_id' => 'A manager cancelled this planned trip.']);
        }
        if (($values[$field] ?? null) !== $dispatch->log_resource_id) {
            throw ValidationException::withMessages(["values.{$field}" => "This trip was planned for {$dispatch->resource->code}. Record it with that vehicle, or ask a manager to plan another."]);
        }
    }

    /**
     * Ids of the assets a person has a planned trip with on a day: for that
     * day they count as assigned.
     *
     * @return list<string>
     */
    public static function assetIdsFor(string $userId, string $day): array
    {
        return array_values(array_map('strval', Dispatch::query()->where('user_id', $userId)->whereDate('planned_on', $day)
            ->where('status', '!=', 'cancelled')->pluck('log_resource_id')->all()));
    }

    /**
     * A driver's trips still to record (from yesterday on), for the phone
     * and the web list.
     *
     * @return list<array<string, mixed>>
     */
    public static function openFor(string $userId): array
    {
        $from = CarbonImmutable::parse(Assignments::today())->subDay()->toDateString();

        return array_values(Dispatch::query()->where('user_id', $userId)->whereIn('status', ['planned', 'started'])
            ->whereDate('planned_on', '>=', $from)->with(['resource:id,code,name', 'sheet:id,name,code'])
            ->orderBy('planned_on')->orderBy('planned_time')->limit(50)->get()
            ->map(fn (Dispatch $d) => self::row($d))->all());
    }

    /** @return array<string, mixed> */
    public static function row(Dispatch $d): array
    {
        return [
            'id' => $d->id, 'status' => $d->status, 'day' => $d->planned_on->toDateString(), 'time' => $d->planned_time,
            'destination' => $d->destination, 'purpose' => $d->purpose,
            'sheetId' => $d->log_sheet_id, 'sheet' => $d->sheet?->name,
            'resourceId' => $d->log_resource_id, 'resource' => $d->resource !== null ? "{$d->resource->code} · {$d->resource->name}" : null,
            'entryId' => $d->log_entry_id,
        ];
    }

    // ---- Notifications (run by Notifications::later) ----

    public static function notifyPlanned(string $dispatchId, ?string $actorId): void
    {
        $d = Dispatch::query()->with(['resource:id,code,name', 'sheet'])->find($dispatchId);
        if ($d === null || $d->status !== 'planned') {
            return;
        }
        Notifications::tell($d->user_id, [
            'category' => 'task', 'event' => 'dispatch.planned',
            'title' => "Trip planned: {$d->resource->code} on ".$d->planned_on->format('j M').($d->planned_time !== null ? " at {$d->planned_time}" : ''),
            'body' => implode(' · ', array_filter([$d->destination, $d->purpose])) ?: $d->sheet->name,
            'subject_type' => 'dispatch', 'subject_id' => $d->id, 'link' => 'trips', 'dedupe' => "dispatch:{$d->id}:planned",
        ], fn (Member $m) => Access::reachesSheet($d->sheet, 'contribute', $m), $actorId);
    }

    public static function notifyCancelled(string $dispatchId, ?string $actorId): void
    {
        $d = Dispatch::query()->with('resource:id,code')->find($dispatchId);
        if ($d === null || $d->status !== 'cancelled') {
            return;
        }
        Notifications::tell($d->user_id, [
            'category' => 'task', 'event' => 'dispatch.cancelled',
            'title' => "Trip cancelled: {$d->resource->code} on ".$d->planned_on->format('j M'),
            'body' => $d->cancel_reason,
            'subject_type' => 'dispatch', 'subject_id' => $d->id, 'link' => 'trips', 'dedupe' => "dispatch:{$d->id}:cancelled",
        ], null, $actorId);
    }
}
