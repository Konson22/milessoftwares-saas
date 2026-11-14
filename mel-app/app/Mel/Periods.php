<?php

namespace App\Mel;

use App\Models\Programme;
use App\Models\ReportingPeriod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reporting periods. Generated periods follow the calendar (months,
 * quarters, half-years, years), cut to the programme's dates; custom ones
 * are entered by hand. Periods of one frequency never overlap, checked
 * under a lock on the programme.
 */
class Periods
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Creates the periods of a frequency across the programme's dates,
     * skipping those that exist. Returns how many were added.
     */
    public function generate(Programme $programme, string $frequency, int $dueAfterDays, User $user): int
    {
        if (! in_array($frequency, ['monthly', 'quarterly', 'semiannual', 'annual'], true)) {
            throw ValidationException::withMessages(['frequency' => 'Choose monthly, quarterly, half-yearly or yearly.']);
        }

        return DB::transaction(function () use ($programme, $frequency, $dueAfterDays, $user) {
            Programme::query()->whereKey($programme->id)->lockForUpdate()->first();

            $added = 0;
            foreach (self::calendar($programme->starts_on, $programme->ends_on, $frequency) as [$start, $end, $label]) {
                if ($this->overlaps($programme, $frequency, $start, $end)) {
                    continue;
                }

                ReportingPeriod::query()->create([
                    'programme_id' => $programme->id,
                    'frequency' => $frequency,
                    'label' => $label,
                    'starts_on' => $start,
                    'ends_on' => $end,
                    'due_on' => $end->addDays($dueAfterDays),
                    'status' => ReportingPeriod::OPEN,
                ]);
                $added++;
            }

            if ($added > 0) {
                $this->audit->record('periods.generated', $programme, $user, ['frequency' => $frequency, 'added' => $added]);
            }

            return $added;
        });
    }

    public function addCustom(Programme $programme, string $label, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $due, User $user): ReportingPeriod
    {
        if ($end->lessThan($start)) {
            throw ValidationException::withMessages(['ends_on' => 'The period must end on or after its start.']);
        }

        return DB::transaction(function () use ($programme, $label, $start, $end, $due, $user) {
            Programme::query()->whereKey($programme->id)->lockForUpdate()->first();

            if ($this->overlaps($programme, 'custom', $start, $end)) {
                throw ValidationException::withMessages(['starts_on' => 'This overlaps another custom period.']);
            }

            $period = ReportingPeriod::query()->create([
                'programme_id' => $programme->id,
                'frequency' => 'custom',
                'label' => $label,
                'starts_on' => $start,
                'ends_on' => $end,
                'due_on' => $due,
                'status' => ReportingPeriod::OPEN,
            ]);
            $this->audit->record('period.created', $period, $user);

            return $period;
        });
    }

    /**
     * A closed period takes no new submissions or changes to drafts;
     * review of what was submitted goes on.
     */
    public function setStatus(ReportingPeriod $period, string $status, User $user): void
    {
        DB::transaction(function () use ($period, $status, $user) {
            $period->update(['status' => $status]);
            $this->audit->record($status === ReportingPeriod::CLOSED ? 'period.closed' : 'period.reopened', $period, $user);
        });
    }

    private function overlaps(Programme $programme, string $frequency, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return ReportingPeriod::query()
            ->where('programme_id', $programme->id)
            ->where('frequency', $frequency)
            ->where('starts_on', '<=', $end->toDateString())
            ->where('ends_on', '>=', $start->toDateString())
            ->exists();
    }

    /**
     * Calendar periods of a frequency covering [from, to], cut to it.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}>
     */
    public static function calendar(CarbonImmutable $from, CarbonImmutable $to, string $frequency): array
    {
        $months = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12][$frequency];
        $cursor = $from->startOfMonth()->subMonths(($from->month - 1) % $months);
        $periods = [];

        while ($cursor->lessThanOrEqualTo($to) && count($periods) < 600) {
            $end = $cursor->addMonths($months)->subDay();
            $label = match ($frequency) {
                'monthly' => $cursor->format('M Y'),
                'quarterly' => $cursor->year.' Q'.(intdiv($cursor->month - 1, 3) + 1),
                'semiannual' => $cursor->year.' H'.($cursor->month <= 6 ? 1 : 2),
                default => (string) $cursor->year,
            };
            $periods[] = [$cursor->max($from), $end->min($to), $label];
            $cursor = $cursor->addMonths($months);
        }

        return $periods;
    }
}
