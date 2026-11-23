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
     * @return array{mode: string, shifts: string}|null null: nothing chosen (on demand, or as the sheet's schedules say)
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

        return ['mode' => (string) $input['mode'], 'shifts' => (string) $shifts];
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

    public static function hasLiveSchedules(LogSheet $sheet): bool
    {
        return Schedule::query()->where('log_sheet_id', $sheet->id)->whereNull('ended_at')->exists();
    }
}
