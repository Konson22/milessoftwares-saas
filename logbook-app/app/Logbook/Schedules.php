<?php

namespace App\Logbook;

use App\Logbook\Models\Entry;
use App\Logbook\Models\LogResource;
use App\Logbook\Models\LogSheet;
use App\Logbook\Models\Reminder;
use App\Logbook\Models\Schedule;
use App\Logbook\Models\ScheduleOccurrence;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Scheduled log sheets (ADR-0026 §2.6).
 *
 * Every expected entry is an occurrence row, made a day ahead by
 * logbook:operations: (schedule, date) is unique, so runs never double.
 * An occurrence counts entries from its opening time, is due at its due
 * time, and accepts a late entry until its late window closes.
 *
 * Completion: the first submitted entry on the sheet (by the responsible
 * person, when one is set) whose time falls in that window. Its time is
 * when the entry says it happened, so a reading taken on time on a phone
 * without signal and sent later still counts on time; submission and
 * review times are kept beside it. With the "reviewed" rule the
 * occurrence completes when the review lands. Nothing by the close:
 * missed. A manager may excuse an occurrence, with a reason.
 */
final class Schedules
{
    /**
     * @param  array<string, mixed>  $data  frequency, weekdays, day_of_month, opens_time, due_time, late_minutes, timezone, starts_on, ends_on, responsible_id, completion, reminders, active, resource_id (ADR-0031: one asset's readings)
     */
    public static function save(LogSheet $sheet, ?Schedule $schedule, array $data, User $by): Schedule
    {
        // Several readings a day (ADR-0030 §4.2): a list of {opens, due}; older forms send one pair.
        $times = self::timesInput($data);
        $data['opens_time'] = $times[0]['opens'];
        $data['due_time'] = $times[0]['due'];
        Recurrence::check($data, ['opens_time', 'due_time']);
        // One owner of "when is this sheet expected" (ADR-0030 §3): only a scheduled sheet takes schedules.
        if ($schedule === null && ! Recording::allowsSchedules($sheet)) {
            throw ValidationException::withMessages(['frequency' => 'This log sheet is recorded '.mb_strtolower(Recording::LABELS[Recording::mode($sheet)]).'. Make it a scheduled sheet in its template first.']);
        }
        $errors = self::timesErrors($times);
        $late = (int) ($data['late_minutes'] ?? 240);
        if ($late < 0 || $late > 10080) {
            $errors['late_minutes'] = 'Up to 7 days (10,080 minutes).';
        }
        $completion = $data['completion'] ?? 'submitted';
        if (! in_array($completion, ['submitted', 'reviewed'], true)) {
            $errors['completion'] = 'Choose when an occurrence counts as done.';
        }
        if ($completion === 'reviewed' && ! $sheet->review_required) {
            $errors['completion'] = 'This log sheet does not ask for review: an entry completes the occurrence.';
        }
        $responsible = is_string($data['responsible_id'] ?? null) && $data['responsible_id'] !== '' ? $data['responsible_id'] : null;
        if ($responsible !== null) {
            $member = WorkspaceMember::query()->ofUser($responsible)->exists() ? Member::of($responsible) : null;
            if ($member === null || ! Access::reachesSheet($sheet, 'contribute', $member)) {
                $errors['responsible_id'] = 'Choose someone who writes entries on this log sheet.';
            }
        }
        // ADR-0031: a schedule for one asset. Its occurrences are met only by entries naming it.
        $resourceId = is_string($data['resource_id'] ?? null) && $data['resource_id'] !== '' ? $data['resource_id'] : ($schedule?->resource_id);
        if ($schedule !== null && array_key_exists('resource_id', $data) && $resourceId !== $schedule->resource_id) {
            $errors['resource_id'] = 'A schedule keeps its asset. End it and set up one for the other asset.';
        } elseif ($resourceId !== null && $schedule === null) {
            $resource = LogResource::query()->find($resourceId);
            $linked = SheetAssets::linkedIds($sheet->id);
            $why = $resource === null ? 'Choose an asset of this workspace.' : (SheetAssets::refusal($sheet, $resource)
                ?? ($resource->status !== 'active' ? "{$resource->code} is not active." : null)
                ?? ($linked !== [] && ! in_array($resource->id, $linked, true) ? "Link {$resource->code} to this log sheet first." : null));
            if ($why !== null) {
                $errors['resource_id'] = $why;
            }
        }
        $tokens = array_values(array_filter((array) ($data['reminders'] ?? []), fn ($t) => is_string($t) && ! str_starts_with($t, 'at:') && $t !== 'start'));
        $errors += Reminders::errors($tokens, null, CarbonImmutable::now());
        $startsOn = CarbonImmutable::parse((string) ($data['starts_on'] ?? 'today'))->toDateString();
        $endsOn = isset($data['ends_on']) && $data['ends_on'] !== '' ? CarbonImmutable::parse((string) $data['ends_on'])->toDateString() : null;
        if ($endsOn !== null && $endsOn < $startsOn) {
            $errors['ends_on'] = 'The end date is before the start date.';
        }
        if (! $sheet->open()) {
            $errors['schedule'] = 'Publish the log sheet before scheduling it.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $channels = Reminders::channelsInput($data['reminder_channels'] ?? null);
        $wantsActive = array_key_exists('active', $data) ? (bool) $data['active'] : null;

        $schedule = DB::transaction(function () use ($sheet, $schedule, $data, $times, $late, $completion, $responsible, $tokens, $channels, $startsOn, $endsOn, $by, $resourceId) {
            $schedule ??= new Schedule(['logbook_id' => $sheet->logbook_id, 'log_sheet_id' => $sheet->id, 'created_by' => $by->id, 'active' => true, 'resource_id' => $resourceId]);
            if ($schedule->ended_at !== null) {
                throw ValidationException::withMessages(['schedule' => 'This schedule has ended. Set up a new one instead.']);
            }
            $before = $schedule->exists ? self::summary($schedule) : null;
            $schedule->fill([
                'frequency' => $data['frequency'],
                'weekdays' => $data['frequency'] === 'weekly' ? array_values(array_map('intval', (array) $data['weekdays'])) : null,
                'day_of_month' => $data['frequency'] === 'monthly' ? (int) $data['day_of_month'] : null,
                'opens_time' => $data['opens_time'],
                'due_time' => $data['due_time'],
                'times' => $times,
                'late_minutes' => $late,
                'timezone' => $data['timezone'],
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'responsible_id' => $responsible,
                'completion' => $completion,
                'reminders' => $tokens,
                'reminder_channels' => $channels,
                // New settings apply from now: past days keep the occurrences they had.
                'generate_from' => now(),
            ])->save();
            $schedule->setRelation('sheet', $sheet);

            // Occurrences not yet open follow the new settings; settled ones stay as they were.
            self::dropUpcoming($schedule);
            AuditTrail::record($before === null ? 'schedule.created' : 'schedule.updated', $schedule, $by, $before === null
                ? ['schedule' => self::summary($schedule)]
                : ['from' => $before, 'to' => self::summary($schedule)]);

            return $schedule;
        });

        // The older form sends "active"; it means pause or resume.
        if ($wantsActive === false && $schedule->paused_at === null) {
            return self::pause($schedule, $by);
        }
        if ($wantsActive === true && $schedule->paused_at !== null) {
            return self::resume($schedule, $by);
        }

        return $schedule;
    }

    /** No expected entries until resumed; those not yet open are withdrawn. */
    public static function pause(Schedule $schedule, User $by): Schedule
    {
        return DB::transaction(function () use ($schedule, $by) {
            $schedule = Schedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($schedule->ended_at !== null || $schedule->paused_at !== null) {
                return $schedule;
            }
            $schedule->forceFill(['paused_at' => now(), 'active' => false])->save();
            $dropped = self::dropUpcoming($schedule);
            AuditTrail::record('schedule.paused', $schedule, $by, ['withdrawn' => $dropped]);

            return $schedule;
        });
    }

    /** Expected entries again from now on: the paused days are not counted as missed. */
    public static function resume(Schedule $schedule, User $by): Schedule
    {
        return DB::transaction(function () use ($schedule, $by) {
            $schedule = Schedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($schedule->ended_at !== null) {
                throw ValidationException::withMessages(['schedule' => 'This schedule has ended. Set up a new one instead.']);
            }
            if ($schedule->paused_at === null) {
                return $schedule;
            }
            $schedule->forceFill(['paused_at' => null, 'active' => true, 'generate_from' => now()])->save();
            AuditTrail::record('schedule.resumed', $schedule, $by);

            return $schedule;
        });
    }

    /** Ends the schedule for good; its history stays. */
    public static function end(Schedule $schedule, User $by): Schedule
    {
        return DB::transaction(function () use ($schedule, $by) {
            $schedule = Schedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($schedule->ended_at !== null) {
                return $schedule;
            }
            $today = CarbonImmutable::now($schedule->timezone)->toDateString();
            $schedule->forceFill([
                'ended_at' => now(),
                'active' => false,
                'ends_on' => $schedule->ends_on !== null && $schedule->ends_on->toDateString() < $today ? $schedule->ends_on : $today,
            ])->save();
            $dropped = self::dropUpcoming($schedule);
            AuditTrail::record('schedule.ended', $schedule, $by, ['withdrawn' => $dropped]);

            return $schedule;
        });
    }

    /** active, paused or ended. */
    public static function state(Schedule $schedule): string
    {
        return match (true) {
            $schedule->ended_at !== null || ($schedule->ends_on !== null && $schedule->ends_on->lt(CarbonImmutable::today($schedule->timezone))) => 'ended',
            $schedule->paused_at !== null || ! $schedule->active => 'paused',
            default => 'active',
        };
    }

    /** Withdraws occurrences not yet open with nothing in: they were only made a day ahead. */
    private static function dropUpcoming(Schedule $schedule): int
    {
        $future = $schedule->occurrences()->where('status', 'open')->whereNull('log_entry_id')->where('opens_at', '>', now())->get();
        foreach ($future as $occurrence) {
            Reminders::cancel('occurrence', $occurrence->id);
            Entry::query()->where('schedule_occurrence_id', $occurrence->id)->update(['schedule_occurrence_id' => null]);
            $occurrence->delete();
        }

        return $future->count();
    }

    /**
     * Makes the occurrences of the next day for every running schedule,
     * and plans their reminders. After the clock has been down it catches
     * up (up to logbook.catch_up_days): those days were still expected, so
     * they are matched against entries written meanwhile, or settle as
     * missed. Never before the schedule was set up, changed or resumed
     * (`generate_from`), so paused days are never counted.
     */
    public static function generate(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $made = 0;
        $lookback = max(1, (int) config('logbook.catch_up_days', 7));
        $running = Schedule::query()->where('active', true)->whereNull('paused_at')->whereNull('ended_at')->with('sheet')->get();
        foreach ($running as $schedule) {
            if (! $schedule->sheet->open()) {
                continue;
            }
            $tz = $schedule->timezone;
            $local = $now->setTimezone($tz);
            $from = $schedule->generate_from ?? $schedule->updated_at;
            $dates = Recurrence::dates($schedule->frequency, $schedule->weekdays, $schedule->day_of_month, $schedule->starts_on, $schedule->ends_on, $local->subDays($lookback), $local->addDay(), $tz);
            if ($dates === []) {
                continue;
            }
            $existing = ScheduleOccurrence::query()->where('schedule_id', $schedule->id)->where('occurs_on', '>=', $dates[0]->toDateString())
                ->get(['occurs_on', 'slot'])->map(fn (ScheduleOccurrence $o) => $o->occurs_on->toDateString().'#'.$o->slot)->flip();
            foreach ($dates as $date) {
                foreach (self::times($schedule) as $slot => $time) {
                    [$opensAt, $dueAt] = self::window($date, $time, $tz);
                    $closesAt = $dueAt->addMinutes($schedule->late_minutes);
                    if ($opensAt->gt($now->addDay()) || ($from !== null && $dueAt->lt($from))) {
                        continue;
                    }
                    if ($existing->has($date->toDateString().'#'.$slot)) {
                        continue;
                    }
                    $made += self::makeOccurrence($schedule, $date, $slot, $opensAt, $dueAt, $closesAt);
                }
            }
        }

        return $made;
    }

    /** One expected reading; 0 when another run made it first. */
    private static function makeOccurrence(Schedule $schedule, CarbonImmutable $date, int $slot, CarbonImmutable $opensAt, CarbonImmutable $dueAt, CarbonImmutable $closesAt): int
    {
        try {
            return DB::transaction(function () use ($schedule, $date, $slot, $opensAt, $dueAt, $closesAt) {
                $occurrence = ScheduleOccurrence::query()->create([
                    'schedule_id' => $schedule->id,
                    'logbook_id' => $schedule->logbook_id,
                    'log_sheet_id' => $schedule->log_sheet_id,
                    'occurs_on' => $date->toDateString(),
                    'slot' => $slot,
                    'opens_at' => $opensAt,
                    'due_at' => $dueAt,
                    'closes_at' => $closesAt,
                    'responsible_id' => $schedule->responsible_id,
                    'resource_id' => $schedule->resource_id,
                    'status' => 'open',
                ]);
                // Entries already written in the window (made offline, or early) count.
                self::matchExisting($occurrence, $schedule);
                if ($occurrence->status === 'open' && $occurrence->log_entry_id === null) {
                    Reminders::plan('occurrence', $occurrence->id, $schedule->reminders ?? [], null, $dueAt);
                }

                return 1;
            });
        } catch (UniqueConstraintViolationException) {
            // Made by another run meanwhile.
            return 0;
        }
    }

    /**
     * The schedule's readings: its times list, or the one pair saved
     * before ADR-0030.
     *
     * @return list<array{opens: string, due: string}>
     */
    public static function times(Schedule $schedule): array
    {
        $times = is_array($schedule->times) && $schedule->times !== [] ? $schedule->times : [['opens' => $schedule->opens_time, 'due' => $schedule->due_time]];

        return array_map(fn (array $t) => ['opens' => (string) $t['opens'], 'due' => (string) $t['due']], $times);
    }

    /**
     * When one reading opens and is due on a day. A due time before the
     * opening time is on the next day (opens 23:30, due 00:30): the
     * occurrence belongs to the day it opens.
     *
     * @param  array{opens: string, due: string}  $time
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(CarbonImmutable $date, array $time, string $tz): array
    {
        $opensAt = Recurrence::at($date, $time['opens'], $tz);
        $dueAt = Recurrence::at($time['due'] < $time['opens'] ? $date->addDay() : $date, $time['due'], $tz);

        return [$opensAt, $dueAt];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{opens: string, due: string}>
     */
    private static function timesInput(array $data): array
    {
        $raw = is_array($data['times'] ?? null) && $data['times'] !== []
            ? $data['times']
            : [['opens' => $data['opens_time'] ?? null, 'due' => $data['due_time'] ?? null]];
        $times = [];
        foreach (array_values($raw) as $i => $t) {
            $opens = is_array($t) ? ($t['opens'] ?? null) : null;
            $due = is_array($t) ? ($t['due'] ?? null) : null;
            if (! is_string($opens) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $opens) || ! is_string($due) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $due)) {
                throw ValidationException::withMessages([$i === 0 && ! isset($data['times']) ? 'opens_time' : "times.{$i}" => 'Use times as HH:MM, for example 05:30 and 06:00.']);
            }
            $times[] = ['opens' => $opens, 'due' => $due];
        }
        if (count($times) > 24) {
            throw ValidationException::withMessages(['times' => 'At most 24 readings a day.']);
        }
        usort($times, fn ($a, $b) => strcmp($a['opens'], $b['opens']));

        return $times;
    }

    /**
     * Each reading's window, from opening to due, must not overlap the
     * next one's: otherwise an entry could be meant for either.
     *
     * @param  list<array{opens: string, due: string}>  $times
     * @return array<string, string>
     */
    private static function timesErrors(array $times): array
    {
        $minutes = fn (string $hm) => (int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2);
        $spans = [];
        foreach ($times as $i => $t) {
            $start = $minutes($t['opens']);
            $end = $minutes($t['due']);
            if ($end === $start) {
                return [count($times) === 1 ? 'due_time' : "times.{$i}" => 'The due time must differ from the opening time.'];
            }
            $spans[] = [$start, $end < $start ? $end + 1440 : $end];
        }
        foreach ($spans as $i => [$start, $end]) {
            $next = $spans[$i + 1][0] ?? $spans[0][0] + 1440;
            if (count($spans) > 1 && $end > $next) {
                return ['times' => 'Two readings overlap: each must be due before the next one opens.'];
            }
        }

        return [];
    }

    /**
     * Occurrences whose late window has closed with nothing in: missed,
     * and the people concerned are told.
     */
    public static function settle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $missed = 0;
        $ids = ScheduleOccurrence::query()->where('status', 'open')->whereNull('log_entry_id')
            ->where('closes_at', '<', $now)->limit(500)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $now, &$missed) {
                $occurrence = ScheduleOccurrence::query()->whereKey($id)->lockForUpdate()->first();
                if ($occurrence === null || $occurrence->status !== 'open' || $occurrence->log_entry_id !== null) {
                    return;
                }
                $occurrence->forceFill(['status' => 'missed', 'settled_at' => $now])->save();
                Reminders::cancel('occurrence', $occurrence->id);
                AuditTrail::record('occurrence.missed', $occurrence, null);
                Notifications::later(self::class, 'notifyMissed', [$occurrence->id]);
                $missed++;
            });
        }

        return $missed;
    }

    /**
     * A submitted entry meets the earliest open (or already missed: the
     * phone may have been offline) occurrence of its sheet whose window
     * holds its time. Called inside the submitting transaction.
     */
    public static function entrySubmitted(Entry $entry): void
    {
        // An extra reading (ADR-0030 §4.3) counts for no expected one.
        if ($entry->schedule_link === 'extra') {
            return;
        }
        // The occurrence the entry was written for (opened from its reminder), when it still fits.
        $named = $entry->schedule_occurrence_id === null ? null : ScheduleOccurrence::query()
            ->whereKey($entry->schedule_occurrence_id)
            ->where('log_sheet_id', $entry->log_sheet_id)
            ->whereIn('status', ['open', 'missed'])
            ->whereNull('log_entry_id')
            ->where('opens_at', '<=', $entry->occurred_at)
            ->where('closes_at', '>=', $entry->occurred_at)
            ->where(fn (Builder $q) => $q->whereNull('responsible_id')->orWhere('responsible_id', $entry->created_by))
            ->where(fn (Builder $q) => self::forAsset($q, $entry->resource_id))
            ->lockForUpdate()
            ->first();

        // Named explicitly but no longer eligible: it stays an extra reading. Only entries from
        // clients that say nothing (older phone builds) are matched by their time.
        if ($named === null && $entry->schedule_link === 'named') {
            return;
        }
        $occurrence = $named ?? ScheduleOccurrence::query()
            ->where('log_sheet_id', $entry->log_sheet_id)
            ->whereIn('status', ['open', 'missed'])
            ->whereNull('log_entry_id')
            ->where('opens_at', '<=', $entry->occurred_at)
            ->where('closes_at', '>=', $entry->occurred_at)
            ->where(fn (Builder $q) => $q->whereNull('responsible_id')->orWhere('responsible_id', $entry->created_by))
            ->where(fn (Builder $q) => self::forAsset($q, $entry->resource_id))
            // The asset's own expected reading before one for the whole sheet.
            ->orderByRaw('CASE WHEN resource_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->lockForUpdate()
            ->first();
        if ($occurrence === null) {
            return;
        }
        $schedule = Schedule::query()->find($occurrence->schedule_id);
        if ($schedule === null) {
            return;
        }
        self::meet($occurrence, $schedule, $entry);
    }

    /** A review landed: an occurrence waiting on it completes, or waits for the correction. */
    public static function entryReviewed(Entry $entry): void
    {
        $occurrence = ScheduleOccurrence::query()->where('log_entry_id', $entry->id)->where('status', 'open')->lockForUpdate()->first();
        if ($occurrence === null) {
            return;
        }
        if ($entry->status === 'reviewed') {
            $occurrence->forceFill([
                'status' => $entry->occurred_at->lte($occurrence->due_at) ? 'completed' : 'late',
                'reviewed_at' => $entry->reviewed_at,
                'settled_at' => now(),
            ])->save();
            AuditTrail::record('occurrence.'.$occurrence->status, $occurrence, null, ['entry' => $entry->reference]);
        }
    }

    public static function excuse(ScheduleOccurrence $occurrence, User $by, string $reason): ScheduleOccurrence
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why this occurrence is excused.']);
        }

        return DB::transaction(function () use ($occurrence, $by, $reason) {
            $occurrence = ScheduleOccurrence::query()->whereKey($occurrence->id)->lockForUpdate()->firstOrFail();
            if (! in_array($occurrence->status, ['open', 'missed'], true) || $occurrence->log_entry_id !== null) {
                throw ValidationException::withMessages(['reason' => 'Only an occurrence with no entry is excused.']);
            }
            $from = $occurrence->status;
            $occurrence->forceFill(['status' => 'excused', 'excused_by' => $by->id, 'excuse_reason' => mb_substr($reason, 0, 2000), 'settled_at' => now()])->save();
            Reminders::cancel('occurrence', $occurrence->id);
            AuditTrail::record('occurrence.excused', $occurrence, $by, ['from' => $from, 'reason' => $reason]);

            return $occurrence;
        });
    }

    /**
     * Occurrences a member may see: those of sheets they reach (any
     * capability).
     *
     * @return Builder<ScheduleOccurrence>
     */
    public static function visible(?Member $member = null): Builder
    {
        return Access::limit(ScheduleOccurrence::query(), array_keys(Access::CAPABILITIES), member: $member);
    }

    /**
     * Inspections due for a person now or today: open occurrences of
     * sheets they write on, theirs or nobody's in particular.
     *
     * @return Builder<ScheduleOccurrence>
     */
    public static function dueFor(Member $member, CarbonImmutable $until): Builder
    {
        return Access::limit(ScheduleOccurrence::query(), ['contribute'], member: $member)
            ->where('status', 'open')->whereNull('log_entry_id')
            ->where('opens_at', '<=', $until)
            ->where(fn (Builder $q) => $q->whereNull('responsible_id')->orWhere('responsible_id', $member->id))
            ->orderBy('due_at');
    }

    /**
     * Figures over a set of occurrences: expected, completed, late,
     * missed, excused, waiting on review, and the completion rate (on time
     * or late, out of everything settled or due).
     *
     * @param  Builder<ScheduleOccurrence>  $query
     * @return array{expected: int, completed: int, late: int, missed: int, excused: int, awaitingReview: int, open: int, rate: float|null}
     */
    public static function figures(Builder $query, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $rows = (clone $query)->where('due_at', '<=', $now)->get(['status', 'log_entry_id']);
        $count = fn (string $status) => $rows->where('status', $status)->count();
        $awaiting = $rows->where('status', 'open')->whereNotNull('log_entry_id')->count();
        $open = $rows->where('status', 'open')->whereNull('log_entry_id')->count();
        $expected = $rows->count() - $count('excused');
        $done = $count('completed') + $count('late');

        return [
            'expected' => $expected,
            'completed' => $count('completed'),
            'late' => $count('late'),
            'missed' => $count('missed'),
            'excused' => $count('excused'),
            'awaitingReview' => $awaiting,
            'open' => $open,
            'rate' => $expected > 0 ? round(100 * ($done + $awaiting) / $expected, 1) : null,
        ];
    }

    // ---- Notifications ----

    public static function remind(Reminder $reminder): string
    {
        $occurrence = ScheduleOccurrence::query()->with(['sheet', 'schedule'])->find($reminder->subject_id);
        if ($occurrence === null || $occurrence->status !== 'open' || $occurrence->log_entry_id !== null) {
            return 'skipped: done';
        }
        if ($occurrence->responsible_id === null) {
            return 'skipped: nobody responsible';
        }
        $sheet = $occurrence->sheet;
        // ADR-0031: which asset, when the reading is for one.
        $what = $sheet->code.($occurrence->resource_id !== null ? ' · '.(LogResource::query()->find($occurrence->resource_id)->code ?? '') : '');
        $title = match ($reminder->kind) {
            'before' => "{$what} due in ".Reminders::relative($reminder->offset_minutes),
            'due' => "{$what} is due now",
            'after' => "{$what} is overdue",
            default => "{$what} reminder",
        };
        $sent = Notifications::notify($occurrence->responsible_id, [
            'category' => 'schedule', 'event' => 'schedule.reminder.'.$reminder->kind, 'title' => $title,
            'body' => $sheet->name.' · due '.self::when($occurrence->due_at).'.',
            'subject_type' => 'occurrence', 'subject_id' => $occurrence->id, 'link' => "entries/new?sheet={$sheet->id}&occurrence={$occurrence->id}",
            'dedupe' => "reminder:{$reminder->id}", 'channels' => Reminders::channels($occurrence->schedule->reminder_channels, $reminder->kind),
        ], fn (Member $m) => Access::reachesSheet($sheet, 'contribute', $m));

        return $sent !== null ? 'sent' : 'skipped: no access';
    }

    public static function notifyMissed(string $occurrenceId): void
    {
        $occurrence = ScheduleOccurrence::query()->with(['sheet', 'schedule'])->find($occurrenceId);
        if ($occurrence === null || $occurrence->status !== 'missed') {
            return;
        }
        $sheet = $occurrence->sheet;
        $notice = [
            'category' => 'schedule', 'event' => 'schedule.missed',
            // Several readings a day: the title says which one.
            'title' => "Missed: {$sheet->code}".($occurrence->resource_id !== null ? ' · '.(LogResource::query()->find($occurrence->resource_id)->code ?? '') : '').' of '.$occurrence->occurs_on->format('j M')
                .(count(self::times($occurrence->schedule)) > 1 ? ' '.$occurrence->due_at->setTimezone($occurrence->schedule->timezone)->format('H:i') : ''),
            'body' => $sheet->name.' was due '.self::when($occurrence->due_at).' and no entry came in.',
            'subject_type' => 'occurrence', 'subject_id' => $occurrence->id, 'link' => 'schedules',
            'dedupe' => "occurrence:{$occurrence->id}:missed", 'email' => true,
        ];
        $to = array_filter([$occurrence->responsible_id, $occurrence->schedule->created_by]);
        foreach (array_unique($to) as $userId) {
            Notifications::tell((string) $userId, $notice, fn (Member $m) => Access::reachesSheet($sheet, 'contribute', $m) || Access::manages($m));
        }
    }

    // ---- Helpers ----

    private static function matchExisting(ScheduleOccurrence $occurrence, Schedule $schedule): void
    {
        $entry = Entry::query()->where('log_sheet_id', $occurrence->log_sheet_id)
            ->whereIn('status', ['submitted', 'reviewed', 'finalized', 'returned'])
            ->whereBetween('occurred_at', [$occurrence->opens_at, $occurrence->closes_at])
            ->when($occurrence->responsible_id !== null, fn (Builder $q) => $q->where('created_by', $occurrence->responsible_id))
            ->when($occurrence->resource_id !== null, fn (Builder $q) => $q->where('resource_id', $occurrence->resource_id))
            // Only entries that did not say what they are for (ADR-0030 §4.3).
            ->whereNull('schedule_link')
            ->whereNotIn('id', ScheduleOccurrence::query()->whereNotNull('log_entry_id')->select('log_entry_id'))
            ->orderBy('occurred_at')->first();
        if ($entry !== null) {
            self::meet($occurrence, $schedule, $entry);
        }
    }

    /**
     * Occurrences an entry about this asset may meet: those for the whole
     * sheet, and the asset's own. A reading for STORE-001 never meets
     * STORE-002's expected reading (ADR-0031 §9).
     *
     * @param  Builder<ScheduleOccurrence>  $query
     */
    private static function forAsset(Builder $query, ?string $resourceId): void
    {
        $query->whereNull('resource_id')->when($resourceId !== null, fn (Builder $q) => $q->orWhere('resource_id', $resourceId));
    }

    private static function meet(ScheduleOccurrence $occurrence, Schedule $schedule, Entry $entry): void
    {
        $onTime = $entry->occurred_at->lte($occurrence->due_at);
        $waitForReview = $schedule->completion === 'reviewed' && $entry->status !== 'reviewed';
        $occurrence->forceFill([
            'log_entry_id' => $entry->id,
            'submitted_at' => $entry->submitted_at,
            'reviewed_at' => $entry->reviewed_at,
            // Kept apart from the review: an entry in on time stays on time however long review takes.
            'timeliness' => $onTime ? 'on_time' : 'late',
            'status' => $waitForReview ? 'open' : ($onTime ? 'completed' : 'late'),
            'settled_at' => $waitForReview ? null : now(),
        ])->save();
        Reminders::cancel('occurrence', $occurrence->id);
        AuditTrail::record($waitForReview ? 'occurrence.entry_in' : 'occurrence.'.$occurrence->status, $occurrence, null, ['entry' => $entry->reference]);
    }

    /** @return array<string, mixed> */
    private static function summary(Schedule $schedule): array
    {
        return [
            'frequency' => $schedule->frequency,
            'resource' => $schedule->resource_id !== null ? LogResource::query()->find($schedule->resource_id)?->label() : null,
            'weekdays' => $schedule->weekdays,
            'day_of_month' => $schedule->day_of_month,
            'times' => self::times($schedule),
            'late_minutes' => $schedule->late_minutes,
            'responsible' => $schedule->responsible_id !== null ? Tasks::name($schedule->responsible_id) : null,
            'completion' => $schedule->completion,
            'reminders' => $schedule->reminders,
            'reminder_channels' => $schedule->reminder_channels,
            'state' => self::state($schedule),
        ];
    }

    private static function when(CarbonImmutable $at): string
    {
        return $at->setTimezone(Workspace::current()->timezone ?? 'UTC')->format('j M Y, H:i');
    }
}
