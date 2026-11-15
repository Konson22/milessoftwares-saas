<?php

namespace App\Collaboration;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The periods an obligation covers, from its first day to its last: whole
 * months, quarters or years from the start, the last one cut at the end.
 * "Once" and "custom" are a single period.
 */
final class ReportPeriods
{
    public const MAX = 120;

    /**
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable}>
     *
     * @throws ValidationException
     */
    public static function between(string $frequency, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($end->lt($start)) {
            throw ValidationException::withMessages(['ends_on' => 'The last day must be on or after the first.']);
        }

        $months = match ($frequency) {
            'monthly' => 1,
            'quarterly' => 3,
            'annual' => 12,
            default => null,
        };
        if ($months === null) {
            return [['start' => $start, 'end' => $end]];
        }

        $periods = [];
        // Counted from the start each time, so a 31st does not drift.
        for ($i = 0; ($from = $start->addMonthsNoOverflow($i * $months))->lte($end); $i++) {
            if ($i >= self::MAX) {
                throw ValidationException::withMessages(['ends_on' => 'That makes more than '.self::MAX.' reports. Choose a shorter span or a longer frequency.']);
            }
            $to = $start->addMonthsNoOverflow(($i + 1) * $months)->subDay();
            $periods[] = ['start' => $from, 'end' => $to->gt($end) ? $end : $to];
        }

        return $periods;
    }
}
