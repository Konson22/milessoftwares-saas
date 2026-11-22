<?php

namespace App\Logbook;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Simple schedules shared by recurring tasks and scheduled log sheets
 * (ADR-0026 §2.5, §2.6): daily, weekly on chosen weekdays, or monthly on a
 * day of the month.
 *
 * - Weekdays are ISO numbers, 1 (Monday) to 7 (Sunday).
 * - Day 29, 30 or 31 in a shorter month falls on the month's last day.
 * - Times are local wall times ("HH:MM") read in the schedule's time zone,
 *   and stored in UTC. A time that a daylight-saving change skips moves
 *   forward to the next valid time (PHP's rule); a repeated one takes the
 *   first.
 */
final class Recurrence
{
    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * Local dates between $from and $to (inclusive, both local dates) on
     * which the schedule falls.
     *
     * @param  list<int>|null  $weekdays
     * @return list<CarbonImmutable> dates at local midnight in $timezone
     */
    public static function dates(string $frequency, ?array $weekdays, ?int $dayOfMonth, CarbonImmutable $startsOn, ?CarbonImmutable $endsOn, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $start = CarbonImmutable::parse($startsOn->toDateString(), $timezone);
        $day = CarbonImmutable::parse($from->toDateString(), $timezone)->max($start);
        $last = CarbonImmutable::parse($to->toDateString(), $timezone);
        if ($endsOn !== null) {
            $last = $last->min(CarbonImmutable::parse($endsOn->toDateString(), $timezone));
        }
        $weekdays = $weekdays !== null && $weekdays !== [] ? array_map('intval', $weekdays) : [$start->isoWeekday()];

        $out = [];
        for ($guard = 0; $day->lte($last) && $guard < 400; $guard++, $day = $day->addDay()) {
            $falls = match ($frequency) {
                'daily' => true,
                'weekly' => in_array($day->isoWeekday(), $weekdays, true),
                'monthly' => $day->day === min($dayOfMonth ?? $start->day, $day->daysInMonth),
                default => false,
            };
            if ($falls) {
                $out[] = $day;
            }
        }

        return $out;
    }

    /** The UTC moment of a local wall time on a local date. */
    public static function at(CarbonImmutable $date, string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $date->toDateString().' '.$time, $timezone)->utc();
    }

    /**
     * Checks a schedule's settings; throws with errors keyed by field.
     *
     * @param  array<string, mixed>  $data  frequency, weekdays, day_of_month, timezone, and the time fields named in $times
     * @param  list<string>  $times
     */
    public static function check(array $data, array $times): void
    {
        $errors = [];
        $frequency = $data['frequency'] ?? null;
        if (! in_array($frequency, self::FREQUENCIES, true)) {
            $errors['frequency'] = 'Choose daily, weekly or monthly.';
        }
        if ($frequency === 'weekly') {
            $days = $data['weekdays'] ?? [];
            if (! is_array($days) || $days === [] || array_diff(array_map('intval', $days), range(1, 7)) !== []) {
                $errors['weekdays'] = 'Choose at least one day of the week.';
            }
        }
        if ($frequency === 'monthly') {
            $dom = $data['day_of_month'] ?? null;
            if (! is_numeric($dom) || (int) $dom < 1 || (int) $dom > 31) {
                $errors['day_of_month'] = 'Choose a day of the month, 1 to 31.';
            }
        }
        if (! in_array($data['timezone'] ?? null, timezone_identifiers_list(), true)) {
            $errors['timezone'] = 'Choose a time zone.';
        }
        foreach ($times as $name) {
            if (! is_string($data[$name] ?? null) || preg_match(self::TIME, $data[$name]) !== 1) {
                $errors[$name] = 'A time such as 08:00.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
