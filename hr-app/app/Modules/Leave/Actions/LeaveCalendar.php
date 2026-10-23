<?php

namespace App\Modules\Leave\Actions;

use App\Modules\Leave\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Which days count as leave: Monday to Friday, less the organization's
 * public holidays.
 */
class LeaveCalendar
{
    /** @var list<int> */
    public const WEEKEND = [CarbonInterface::SATURDAY, CarbonInterface::SUNDAY];

    /**
     * Working days from one date to another, both included.
     */
    public function workingDays(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $holidays = PublicHoliday::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->pluck('date')
            ->map(fn (mixed $date) => $date instanceof CarbonInterface ? $date->toDateString() : (string) $date)
            ->flip();

        $days = 0;
        for ($day = $from->startOfDay(); $day->lte($to); $day = $day->addDay()) {
            if (! in_array($day->dayOfWeek, self::WEEKEND, true) && ! $holidays->has($day->toDateString())) {
                $days++;
            }
        }

        return $days;
    }
}
