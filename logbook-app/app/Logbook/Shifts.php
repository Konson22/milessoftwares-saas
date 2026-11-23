<?php

namespace App\Logbook;

use App\Logbook\Models\Entry;
use App\Logbook\Models\Logbook;
use App\Logbook\Models\Shift;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Optional operational shifts (ADR-0030 §4.5). A sheet's template says
 * whether entries name a shift (off, optional, required); the form
 * suggests one from the time the activity happened; the entry keeps the
 * shift id it was given, so renaming a shift never changes the past.
 * No rostering, attendance or payroll.
 */
final class Shifts
{
    public const SETTINGS = ['off', 'optional', 'required'];

    /**
     * Saves a shift. Active shifts that apply to the same logbook may not
     * overlap, so a time matches at most one of them.
     *
     * @param  array<string, mixed>  $data  name, starts_time, ends_time, logbook_id, active, member_ids
     */
    public static function save(?Shift $shift, array $data, User $by): Shift
    {
        $errors = [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 60) {
            $errors['name'] = 'Give the shift a name of up to 60 characters.';
        }
        foreach (['starts_time', 'ends_time'] as $key) {
            if (! is_string($data[$key] ?? null) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $data[$key])) {
                $errors[$key] = 'Use a time as HH:MM.';
            }
        }
        if ($errors === [] && $data['starts_time'] === $data['ends_time']) {
            $errors['ends_time'] = 'The end must differ from the start.';
        }
        $logbookId = is_string($data['logbook_id'] ?? null) && $data['logbook_id'] !== '' ? $data['logbook_id'] : null;
        if ($logbookId !== null && ! Logbook::query()->whereKey($logbookId)->exists()) {
            $errors['logbook_id'] = 'Choose a logbook of this workspace.';
        }
        $memberIds = array_values(array_unique(array_filter((array) ($data['member_ids'] ?? []), 'is_string')));
        $known = WorkspaceMember::query()->whereIn('user_id', $memberIds)->pluck('user_id')->all();
        if (count($known) !== count($memberIds)) {
            $errors['member_ids'] = 'Choose people of this workspace.';
        }
        $active = array_key_exists('active', $data) ? (bool) $data['active'] : true;
        if ($errors === [] && $active && ($clash = self::overlapping($shift?->id, (string) $data['starts_time'], (string) $data['ends_time'], $logbookId)) !== null) {
            $errors['starts_time'] = "Overlaps {$clash->label()}. Shifts that apply to the same logbook may not overlap.";
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($shift, $name, $data, $logbookId, $active, $memberIds, $by) {
            $before = $shift?->only(['name', 'starts_time', 'ends_time', 'logbook_id', 'active']);
            $shift ??= new Shift(['created_by' => $by->id]);
            $shift->fill([
                'name' => $name,
                'starts_time' => $data['starts_time'],
                'ends_time' => $data['ends_time'],
                'logbook_id' => $logbookId,
                'active' => $active,
            ])->save();
            $orgId = $shift->organization_id;
            $shift->members()->sync(collect($memberIds)->mapWithKeys(fn (string $id) => [$id => ['id' => (string) Str::uuid7(), 'organization_id' => $orgId]])->all());
            AuditTrail::record($before === null ? 'shift.created' : 'shift.updated', $shift, $by, $before === null
                ? ['shift' => $shift->label()]
                : ['from' => $before, 'to' => $shift->only(['name', 'starts_time', 'ends_time', 'logbook_id', 'active'])]);

            return $shift;
        });
    }

    /** The first active shift whose hours overlap these, in the same scope. */
    private static function overlapping(?string $exceptId, string $starts, string $ends, ?string $logbookId): ?Shift
    {
        $spans = self::spans($starts, $ends);
        $candidates = Shift::query()->where('active', true)
            ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId))
            // A workspace-wide shift applies everywhere; a logbook's shift meets those and its own.
            ->when($logbookId !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('logbook_id')->orWhere('logbook_id', $logbookId)))
            ->get();
        foreach ($candidates as $other) {
            foreach (self::spans($other->starts_time, $other->ends_time) as [$a, $b]) {
                foreach ($spans as [$c, $d]) {
                    if ($a < $d && $c < $b) {
                        return $other;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Minutes of the day covered, split at midnight.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function spans(string $starts, string $ends): array
    {
        $m = fn (string $hm) => (int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2);
        [$s, $e] = [$m($starts), $m($ends)];

        return $e > $s ? [[$s, $e]] : [[$s, 1440], [0, $e]];
    }

    /** @return Builder<Shift> Active shifts that apply to a logbook. */
    public static function forLogbook(string $logbookId): Builder
    {
        return Shift::query()->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('logbook_id')->orWhere('logbook_id', $logbookId))
            ->orderBy('starts_time');
    }

    /** The template's setting: off, optional or required. */
    public static function setting(Entry $entry): string
    {
        return Recording::settings($entry->version)['shifts'] ?? 'off';
    }

    /**
     * The shift an entry names: kept only on sheets that take shifts, and
     * only an active shift of its logbook (or the whole workspace).
     */
    public static function forEntry(string $setting, string $logbookId, mixed $shiftId): ?string
    {
        if ($setting === 'off' || $shiftId === null || $shiftId === '') {
            return null;
        }
        if (! is_string($shiftId) || ! self::forLogbook($logbookId)->whereKey($shiftId)->exists()) {
            throw ValidationException::withMessages(['shift_id' => 'Choose one of this logbook\'s shifts.']);
        }

        return $shiftId;
    }

    /** Submitting on a sheet that requires a shift. */
    public static function checkRequired(Entry $entry): void
    {
        if (self::setting($entry) === 'required' && $entry->shift_id === null) {
            throw ValidationException::withMessages(['shift_id' => 'This log sheet needs the shift the entry was recorded in.']);
        }
    }

    /**
     * Shifts as options, with the people linked to each.
     *
     * @return list<array{value: string, label: string, starts: string, ends: string, members: list<string>}>
     */
    public static function options(string $logbookId): array
    {
        return array_values(self::forLogbook($logbookId)->with('members:id')->get()->map(fn (Shift $s) => [
            'value' => $s->id, 'label' => $s->label(), 'starts' => $s->starts_time, 'ends' => $s->ends_time,
            'members' => array_values($s->members->pluck('id')->all()),
        ])->all());
    }
}
