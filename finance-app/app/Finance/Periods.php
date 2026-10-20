<?php

namespace App\Finance;

use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\TaxReturn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Financial years and their months. Months are locked in order, so the books
 * are always "locked up to" one date. Before any year is set up nothing is
 * locked, as before periods existed.
 *
 * How a posting date is treated (the ledger asks on every journal):
 *
 *   ENFORCE  entries made here: refused on a locked day or before the books start
 *   MOVE     products' events: moved to the first open day, so they never fail
 *   CLOSING  the year-end close and its reopening, which post into locked months
 */
class Periods
{
    public const ENFORCE = 'enforce';

    public const MOVE = 'move';

    public const CLOSING = 'closing';

    /**
     * The first year (from a chosen first of the month) or the year after the
     * last one. A first or a changed year may be 1 to 24 months long.
     *
     * @throws ValidationException
     */
    public function addYear(?CarbonImmutable $startsOn, int $months = 12): FiscalYear
    {
        if ($months < 1 || $months > 24) {
            throw ValidationException::withMessages(['months' => 'A financial year is 1 to 24 months long.']);
        }

        return DB::transaction(function () use ($startsOn, $months) {
            $last = FiscalYear::query()->orderByDesc('starts_on')->lockForUpdate()->first();

            if ($last !== null) {
                $startsOn = $last->ends_on->addDay();
            } elseif ($startsOn === null || $startsOn->day !== 1) {
                throw ValidationException::withMessages(['starts_on' => 'The first financial year starts on the first day of a month.']);
            }

            $endsOn = $startsOn->addMonthsNoOverflow($months)->subDay();
            $year = FiscalYear::query()->create([
                'name' => $this->name($startsOn, $endsOn),
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
            ]);

            for ($number = 1, $from = $startsOn; $from->lessThanOrEqualTo($endsOn); $number++, $from = $from->addMonthNoOverflow()->startOfMonth()) {
                $year->periods()->create([
                    'number' => $number,
                    'starts_on' => $from,
                    'ends_on' => $from->endOfMonth()->min($endsOn)->startOfDay(),
                ]);
            }

            return $year;
        });
    }

    /**
     * Locks the month; every month before it must be locked already.
     *
     * @throws ValidationException
     */
    public function lock(FiscalPeriod $period, User $by): FiscalPeriod
    {
        return DB::transaction(function () use ($period, $by) {
            $locked = FiscalPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if ($locked->locked()) {
                return $locked;
            }

            $earlier = FiscalPeriod::query()->whereDate('starts_on', '<', $locked->starts_on)->whereNull('locked_at')->orderBy('starts_on')->first();
            if ($earlier !== null) {
                throw ValidationException::withMessages(['period' => "Lock {$earlier->label()} first: months are locked in order."]);
            }

            $locked->forceFill(['locked_at' => now(), 'locked_by' => $by->id])->save();

            return $locked;
        });
    }

    /**
     * Unlocks the month; every month after it must be open, and its year
     * must not be closed.
     *
     * @throws ValidationException
     */
    public function unlock(FiscalPeriod $period): FiscalPeriod
    {
        return DB::transaction(function () use ($period) {
            $locked = FiscalPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if (! $locked->locked()) {
                return $locked;
            }
            if ($locked->year->closed()) {
                throw ValidationException::withMessages(['period' => "{$locked->year->name} is closed; reopen the year first."]);
            }

            $later = FiscalPeriod::query()->whereDate('starts_on', '>', $locked->starts_on)->whereNotNull('locked_at')->orderByDesc('starts_on')->first();
            if ($later !== null) {
                throw ValidationException::withMessages(['period' => "Unlock {$later->label()} first: months are unlocked from the latest."]);
            }

            $locked->forceFill(['locked_at' => null, 'locked_by' => null])->save();

            return $locked;
        });
    }

    /**
     * The last locked day, or null when nothing is locked.
     */
    public function lockedUntil(): ?CarbonImmutable
    {
        $period = FiscalPeriod::query()->whereNotNull('locked_at')->orderByDesc('ends_on')->first();

        return $period?->ends_on;
    }

    /**
     * The first day of the first year, or null before any year is set up.
     */
    public function booksStart(): ?CarbonImmutable
    {
        return FiscalYear::query()->orderBy('starts_on')->first()?->starts_on;
    }

    /**
     * The day a journal dated $date is posted on (see the class comment).
     * Reads the covering month with a shared lock, so a month cannot be
     * locked while a posting into it is being made.
     *
     * A journal that touches the tax account ($tax) is also kept out of
     * periods covered by a filed tax return (App\Finance\Tax).
     *
     * @throws ValidationException under ENFORCE, when the day is not open
     */
    public function postingDate(CarbonImmutable $date, string $rule = self::ENFORCE, bool $tax = false): CarbonImmutable
    {
        if ($rule === self::CLOSING) {
            return $date;
        }

        FiscalPeriod::query()->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->sharedLock()->first();

        $start = $this->booksStart();
        $lockedUntil = $this->lockedUntil();
        // Shared-locked like the month, so a return cannot be filed under a posting.
        $taxFiledUntil = $tax ? TaxReturn::query()->orderByDesc('ends_on')->sharedLock()->first()?->ends_on : null;
        $taxLocks = $taxFiledUntil !== null && ($lockedUntil === null || $taxFiledUntil->greaterThan($lockedUntil));
        if ($taxLocks) {
            $lockedUntil = $taxFiledUntil;
        }
        $firstOpen = $lockedUntil?->addDay()->max($start ?? $lockedUntil) ?? $start;

        if ($firstOpen === null || $date->greaterThanOrEqualTo($firstOpen)) {
            return $date;
        }

        if ($rule === self::MOVE) {
            return $firstOpen;
        }

        $locked = $lockedUntil !== null && $date->lessThanOrEqualTo($lockedUntil);
        throw ValidationException::withMessages(['entry_date' => match (true) {
            $locked && $taxLocks => "Tax is filed up to {$lockedUntil->format('j M Y')}; date it {$firstOpen->format('j M Y')} or later.",
            $locked => "The books are locked up to {$lockedUntil->format('j M Y')}; date it {$firstOpen->format('j M Y')} or later.",
            default => "The books start on {$firstOpen->format('j M Y')}; date it then or later.",
        }]);
    }

    private function name(CarbonImmutable $startsOn, CarbonImmutable $endsOn): string
    {
        return $startsOn->year === $endsOn->year
            ? "FY {$startsOn->year}"
            : "FY {$startsOn->year}/".$endsOn->format('y');
    }
}
