<?php

use App\Logbook\Recurrence;
use Carbon\CarbonImmutable;

/*
 * The calendar shared by recurring tasks and scheduled log sheets
 * (ADR-0026 §2.5, ADR-0027 §2.2): daily, weekdays, monthly with month ends,
 * local wall times in an IANA zone, daylight-saving gaps and repeats.
 */

function days(string $frequency, ?array $weekdays, ?int $dom, string $from, string $to, string $tz = 'UTC', string $starts = '2026-01-01', ?string $ends = null): array
{
    return array_map(fn (CarbonImmutable $d) => $d->toDateString(), Recurrence::dates(
        $frequency, $weekdays, $dom, CarbonImmutable::parse($starts), $ends !== null ? CarbonImmutable::parse($ends) : null,
        CarbonImmutable::parse($from), CarbonImmutable::parse($to), $tz,
    ));
}

it('falls every day, between the start and the end date', function () {
    expect(days('daily', null, null, '2026-11-18', '2026-11-22', starts: '2026-11-19', ends: '2026-11-21'))
        ->toBe(['2026-11-19', '2026-11-20', '2026-11-21']);
});

it('falls on the chosen weekdays only', function () {
    // Monday 23 Nov 2026 to Sunday 29 Nov: Monday, Wednesday, Friday.
    expect(days('weekly', [1, 3, 5], null, '2026-11-23', '2026-11-29'))->toBe(['2026-11-23', '2026-11-25', '2026-11-27']);
    // Weekly without chosen days: the start date's weekday.
    expect(days('weekly', null, null, '2026-11-23', '2026-12-06', starts: '2026-11-24'))->toBe(['2026-11-24', '2026-12-01']);
});

it('moves day 29 to 31 to the last day of shorter months', function () {
    expect(days('monthly', null, 31, '2026-01-01', '2026-05-31'))
        ->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31']);
    expect(days('monthly', null, 29, '2028-02-01', '2028-03-31'))->toBe(['2028-02-29', '2028-03-29']);
});

it('reads local times in the workspace zone and stores UTC', function () {
    $date = CarbonImmutable::parse('2026-11-20', 'Africa/Juba');
    expect(Recurrence::at($date, '08:00', 'Africa/Juba')->toIso8601String())->toBe('2026-11-20T06:00:00+00:00');
    expect(Recurrence::at(CarbonImmutable::parse('2026-11-20', 'Asia/Kolkata'), '08:00', 'Asia/Kolkata')->toIso8601String())->toBe('2026-11-20T02:30:00+00:00');
});

it('moves a time skipped by daylight saving forward, and takes the first of a repeated one', function () {
    // New York springs forward at 02:00 on 8 March 2026: 02:30 does not exist.
    $spring = Recurrence::at(CarbonImmutable::parse('2026-03-08', 'America/New_York'), '02:30', 'America/New_York');
    expect($spring->toIso8601String())->toBe('2026-03-08T07:30:00+00:00');
    // It falls back at 02:00 on 1 November 2026: 01:30 happens twice.
    $fall = Recurrence::at(CarbonImmutable::parse('2026-11-01', 'America/New_York'), '01:30', 'America/New_York');
    expect($fall->toIso8601String())->toBe('2026-11-01T05:30:00+00:00');
    // The same wall time a day either side keeps its local hour.
    expect(Recurrence::at(CarbonImmutable::parse('2026-03-07', 'America/New_York'), '08:00', 'America/New_York')->toIso8601String())->toBe('2026-03-07T13:00:00+00:00')
        ->and(Recurrence::at(CarbonImmutable::parse('2026-03-09', 'America/New_York'), '08:00', 'America/New_York')->toIso8601String())->toBe('2026-03-09T12:00:00+00:00');
});

it('counts the local date, not the UTC one', function () {
    // 23:30 UTC on the 20th is already the 21st in Juba.
    $from = CarbonImmutable::parse('2026-11-20 23:30:00', 'UTC')->setTimezone('Africa/Juba');
    expect(days('daily', null, null, $from->toDateString(), $from->toDateString(), 'Africa/Juba'))->toBe(['2026-11-21']);
});
