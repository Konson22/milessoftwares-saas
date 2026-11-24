<?php

namespace App\Logbook;

use App\Logbook\Models\LogSheet;
use App\Logbook\Models\LogSheetVersion;
use App\Logbook\Models\Schedule;
use Illuminate\Validation\ValidationException;

/**
 * How a log sheet is recorded (ADR-0030 §4.1), stored on the template
 * version like its GPS settings:
 *
 * - on_demand: entries whenever needed (the default);
 * - scheduled: entries expected at the times its schedules set (the
 *   Schedules module stays the one owner of those times);
 * - event: an activity with a start and an end (a vehicle trip, a
 *   breakdown); never scheduled.
 *
 * Every mode uses the same entry engine: drafts, revisions, review, audit.
 */
final class Recording
{
    public const MODES = ['on_demand', 'scheduled', 'event'];

    public const LABELS = [
        'on_demand' => 'On demand',
        'scheduled' => 'Scheduled',
        'event' => 'Event-based',
    ];

    /**
     * The settings sent by the template builder, checked.
     *
     * @return array{mode: string, shifts: string, subject?: string, activity?: array<string, string|null>, assigned_only?: true}|null null: nothing chosen (on demand, or as the sheet's schedules say)
     */
    public static function settingsInput(mixed $input): ?array
    {
        if ($input === null || $input === []) {
            return null;
        }
        if (! is_array($input) || ! in_array($input['mode'] ?? null, self::MODES, true)) {
            throw ValidationException::withMessages(['recording.mode' => 'Choose scheduled, event-based or on demand.']);
        }
        $shifts = $input['shifts'] ?? 'off';
        if (! in_array($shifts, Shifts::SETTINGS, true)) {
            throw ValidationException::withMessages(['recording.shifts' => 'Choose whether entries name a shift: no, optionally, or always.']);
        }

        $settings = ['mode' => (string) $input['mode'], 'shifts' => (string) $shifts];
        // ADR-0031: the resource field naming the asset an entry is about.
        if (is_string($input['subject'] ?? null) && $input['subject'] !== '') {
            $settings['subject'] = $input['subject'];
        }
        $activity = self::activityInput($input['activity'] ?? null);
        if ($activity !== null) {
            $settings['activity'] = $activity;
        }
        // ADR-0032 §5: the points a trip stopped at.
        $stops = $input['stops'] ?? 'off';
        if (! in_array($stops, Stops::SETTINGS, true)) {
            throw ValidationException::withMessages(['recording.stops' => 'Choose whether trips record their stops: no, optionally, or always.']);
        }
        if ($stops !== 'off') {
            $settings['stops'] = (string) $stops;
        }
        // ADR-0032: only the people the asset is assigned to may record it.
        if (filter_var($input['assigned_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $settings['assigned_only'] = true;
        }

        return $settings;
    }

    /**
     * The activity measured on an entry, for the fuel estimate (ADR-0030
     * §4.7): the resource field, how the quantity is read, and which
     * number fields hold the readings, the fuel measured and the refill.
     * Field names are checked against the template by checkActivity().
     *
     * @return array{resource_field: string, measure: string, start_field: string|null, end_field: string|null, actual_field: string|null, refill_field: string|null}|null
     */
    private static function activityInput(mixed $input): ?array
    {
        if (! is_array($input) || in_array($input['measure'] ?? null, [null, ''], true)) {
            return null;
        }
        if (! in_array($input['measure'], self::MEASURES, true)) {
            throw ValidationException::withMessages(['recording.activity' => 'Choose an odometer, an hour meter or the recorded duration.']);
        }
        $name = fn (string $key) => is_string($input[$key] ?? null) && $input[$key] !== '' ? $input[$key] : null;

        return [
            'resource_field' => (string) $name('resource_field'),
            'measure' => (string) $input['measure'],
            'start_field' => $input['measure'] === 'duration' ? null : $name('start_field'),
            'end_field' => $input['measure'] === 'duration' ? null : $name('end_field'),
            'actual_field' => $name('actual_field'),
            'refill_field' => $name('refill_field'),
        ];
    }

    /** How an activity's quantity is read. */
    public const MEASURES = ['odometer', 'hour_meter', 'duration'];

    /**
     * The activity settings agree with the template's fields: a resource
     * field, number fields for the readings, nothing used twice.
     *
     * @param  array<string, mixed>|null  $settings
     * @param  list<array<string, mixed>>  $fields
     */
    public static function checkActivity(?array $settings, array $fields, string $key = 'recording.activity'): void
    {
        $subject = $settings['subject'] ?? null;
        if (is_string($subject) && ! collect($fields)->contains(fn (array $f) => $f['name'] === $subject && $f['type'] === 'resource')) {
            throw ValidationException::withMessages(['recording.subject' => 'Choose a Resource field of this template as the main asset.']);
        }
        if (($settings['stops'] ?? 'off') !== 'off' && ($settings['mode'] ?? null) !== 'event') {
            throw ValidationException::withMessages(['recording.stops' => 'Only event-based sheets (a trip with a start and an end) record stops.']);
        }
        if (($settings['assigned_only'] ?? false) === true && self::subjectFieldOf($settings, $fields) === null) {
            throw ValidationException::withMessages(['recording.assigned_only' => 'Only assigned people can record an asset the sheet names: add a Resource field and choose it as the main asset.']);
        }
        $activity = $settings['activity'] ?? null;
        if (! is_array($activity)) {
            return;
        }
        $types = collect($fields)->mapWithKeys(fn (array $f) => [$f['name'] => $f['type']])->all();
        $number = fn (?string $n) => $n !== null && in_array($types[$n] ?? null, ['integer', 'decimal'], true);
        $error = match (true) {
            ($types[$activity['resource_field']] ?? null) !== 'resource' => 'Choose the Resource field the activity is about.',
            $activity['measure'] === 'duration' && ($settings['mode'] ?? null) !== 'event' => 'Only event-based sheets have a start and an end to measure.',
            $activity['measure'] !== 'duration' && (! $number($activity['start_field']) || ! $number($activity['end_field'])) => 'Choose the number fields for the start and end readings.',
            $activity['measure'] !== 'duration' && $activity['start_field'] === $activity['end_field'] => 'The start and end readings need two different fields.',
            $activity['actual_field'] !== null && ! $number($activity['actual_field']) => 'The fuel measured must be a number field.',
            $activity['refill_field'] !== null && ! $number($activity['refill_field']) => 'The refill must be a number field.',
            count(array_filter([$activity['start_field'], $activity['end_field'], $activity['actual_field'], $activity['refill_field']])) !== count(array_unique(array_filter([$activity['start_field'], $activity['end_field'], $activity['actual_field'], $activity['refill_field']]))) => 'Each reading needs its own field.',
            default => null,
        };
        if ($error !== null) {
            throw ValidationException::withMessages([$key => $error]);
        }
    }

    /**
     * @return array{resource_field: string, measure: string, start_field: string|null, end_field: string|null, actual_field: string|null, refill_field: string|null}|null
     */
    public static function activity(?LogSheetVersion $version): ?array
    {
        $activity = $version?->recording['activity'] ?? null;
        if (! is_array($activity) || ! in_array($activity['measure'] ?? null, self::MEASURES, true)) {
            return null;
        }
        $name = fn (string $key) => is_string($activity[$key] ?? null) ? $activity[$key] : null;

        return [
            'resource_field' => (string) $name('resource_field'), 'measure' => (string) $activity['measure'],
            'start_field' => $name('start_field'), 'end_field' => $name('end_field'),
            'actual_field' => $name('actual_field'), 'refill_field' => $name('refill_field'),
        ];
    }

    /**
     * The resource field naming the asset an entry is about (ADR-0031):
     * the one the builder chose, else the fuel estimate's, else the only
     * resource field. Null when the sheet names no asset, or several
     * without saying which.
     */
    public static function subjectField(?LogSheetVersion $version): ?string
    {
        if ($version === null) {
            return null;
        }
        $resourceFields = array_values(array_map(fn (array $f) => (string) $f['name'], array_filter($version->fields ?? [], fn (array $f) => $f['type'] === 'resource')));
        foreach ([$version->recording['subject'] ?? null, self::activity($version)['resource_field'] ?? null] as $named) {
            if (is_string($named) && in_array($named, $resourceFields, true)) {
                return $named;
            }
        }

        return count($resourceFields) === 1 ? $resourceFields[0] : null;
    }

    /**
     * subjectField() for settings not saved yet.
     *
     * @param  array<string, mixed>  $settings
     * @param  list<array<string, mixed>>  $fields
     */
    private static function subjectFieldOf(array $settings, array $fields): ?string
    {
        $resourceFields = array_values(array_map(fn (array $f) => (string) $f['name'], array_filter($fields, fn (array $f) => $f['type'] === 'resource')));
        foreach ([$settings['subject'] ?? null, $settings['activity']['resource_field'] ?? null] as $named) {
            if (is_string($named) && in_array($named, $resourceFields, true)) {
                return $named;
            }
        }

        return count($resourceFields) === 1 ? $resourceFields[0] : null;
    }

    /** @return array{mode: string, shifts: string}|null */
    public static function settings(?LogSheetVersion $version): ?array
    {
        $recording = $version?->recording;
        if (! is_array($recording) || ! in_array($recording['mode'] ?? null, self::MODES, true)) {
            return null;
        }

        return ['mode' => $recording['mode'], 'shifts' => in_array($recording['shifts'] ?? null, Shifts::SETTINGS, true) ? $recording['shifts'] : 'off'];
    }

    /**
     * The mode a version is recorded in. A version saved before ADR-0030
     * has none: scheduled when its sheet has a schedule still running,
     * on demand otherwise. Nothing changes for those sheets.
     */
    public static function mode(LogSheet $sheet, ?LogSheetVersion $version = null): string
    {
        $version ??= $sheet->publishedVersion;

        return self::settings($version)['mode'] ?? (self::hasLiveSchedules($sheet) ? 'scheduled' : 'on_demand');
    }

    /** Only a scheduled sheet (or one from before ADR-0030) takes schedules. */
    public static function allowsSchedules(LogSheet $sheet): bool
    {
        $explicit = self::settings($sheet->publishedVersion)['mode'] ?? null;
        $draft = self::settings($sheet->draftVersion)['mode'] ?? null;

        return ($explicit === null || $explicit === 'scheduled') && ($draft === null || $draft === 'scheduled');
    }

    /**
     * Publishing a mode other than scheduled while schedules still run
     * would leave two answers to "when is this sheet expected": refused
     * until they are ended.
     */
    public static function checkPublish(LogSheet $sheet, LogSheetVersion $draft): void
    {
        $mode = self::settings($draft)['mode'] ?? null;
        if ($mode !== null && $mode !== 'scheduled' && self::hasLiveSchedules($sheet)) {
            throw ValidationException::withMessages([
                'recording.mode' => self::LABELS[$mode].' sheets have no schedule. End this sheet\'s schedules first, or keep it scheduled.',
            ]);
        }
    }

    /**
     * The sheets recorded in a mode, as their published version says (or,
     * from before ADR-0030, as their running schedules say).
     *
     * @return list<string>
     */
    public static function sheetIdsInMode(string $mode): array
    {
        $scheduled = array_flip(Schedule::query()->whereNull('ended_at')->distinct()->pluck('log_sheet_id')->all());

        return array_values(LogSheet::query()->with('publishedVersion:id,recording')->get(['id', 'published_version_id'])
            ->filter(fn (LogSheet $s) => (self::settings($s->publishedVersion)['mode'] ?? (isset($scheduled[$s->id]) ? 'scheduled' : 'on_demand')) === $mode)
            ->pluck('id')->all());
    }

    public static function hasLiveSchedules(LogSheet $sheet): bool
    {
        return Schedule::query()->where('log_sheet_id', $sheet->id)->whereNull('ended_at')->exists();
    }
}
