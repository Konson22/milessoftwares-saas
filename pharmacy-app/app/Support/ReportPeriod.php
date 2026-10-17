<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The period a report covers: ?from=&to= (dates), by default the month so
 * far, never longer than a year.
 */
final class ReportPeriod
{
    /** The longest period one report covers, in days. */
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $period = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $to = isset($period['to']) ? CarbonImmutable::parse($period['to']) : CarbonImmutable::today();
        $from = isset($period['from']) ? CarbonImmutable::parse($period['from']) : $to->startOfMonth();
        // A period too long to read at a glance is cut to its last year.
        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }

        return new self($from->startOfDay(), $to->startOfDay());
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function between(): array
    {
        return [$this->from->startOfDay(), $this->to->endOfDay()];
    }

    /**
     * @return array{from: string, to: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }
}
