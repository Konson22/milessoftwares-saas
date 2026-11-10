<?php

namespace App\Http\Controllers;

use App\Finance\Audit;
use App\Finance\Periods;
use App\Finance\YearEnd;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Financial years and months: locking, year-end close and reopening, each
 * on the audit trail with whoever did it.
 */
class PeriodController extends Controller
{
    public function index(Periods $periods): Response
    {
        $years = FiscalYear::query()
            ->with(['periods.locker', 'closer', 'closingJournal'])
            ->orderByDesc('starts_on')
            ->get();

        return Inertia::render('periods/index', [
            'lockedUntil' => $periods->lockedUntil()?->toDateString(),
            'booksStart' => $periods->booksStart()?->toDateString(),
            'suggestedStart' => CarbonImmutable::today()->startOfYear()->toDateString(),
            'years' => $years->map(fn (FiscalYear $year) => [
                'id' => $year->id,
                'name' => $year->name,
                'startsOn' => $year->starts_on->toDateString(),
                'endsOn' => $year->ends_on->toDateString(),
                'closedAt' => $year->closed_at?->toIso8601String(),
                'closedBy' => $year->closer?->name,
                'closingJournal' => $year->closingJournal === null ? null : ['id' => $year->closingJournal->id, 'number' => $year->closingJournal->number],
                'periods' => $year->periods->map(fn (FiscalPeriod $period) => [
                    'id' => $period->id,
                    'label' => $period->label(),
                    'startsOn' => $period->starts_on->toDateString(),
                    'endsOn' => $period->ends_on->toDateString(),
                    'lockedAt' => $period->locked_at?->toIso8601String(),
                    'lockedBy' => $period->locker?->name,
                ]),
            ]),
        ]);
    }

    public function storeYear(Request $request, Periods $periods, Audit $audit): RedirectResponse
    {
        $data = $request->validate([
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'months' => ['required', 'integer', 'min:1', 'max:24'],
        ]);

        $year = DB::transaction(function () use ($periods, $data, $audit) {
            $year = $periods->addYear(isset($data['starts_on']) ? CarbonImmutable::parse($data['starts_on']) : null, (int) $data['months']);
            $audit->record('period.year_added', $year, null, ['starts_on' => $year->starts_on->toDateString(), 'ends_on' => $year->ends_on->toDateString()]);

            return $year;
        });

        return back()->with('success', "{$year->name} added: {$year->starts_on->format('j M Y')} to {$year->ends_on->format('j M Y')}.");
    }

    public function lock(Request $request, FiscalPeriod $period, Periods $periods, Audit $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $period, $periods, $audit) {
            $wasLocked = $period->locked();
            $locked = $periods->lock($period, $this->user($request));
            if (! $wasLocked) {
                $audit->record('period.locked', $locked, ['locked' => false], ['locked' => true, 'period' => $locked->label()]);
            }
        });

        return back()->with('success', "{$period->label()} locked.");
    }

    public function unlock(FiscalPeriod $period, Periods $periods, Audit $audit): RedirectResponse
    {
        DB::transaction(function () use ($period, $periods, $audit) {
            $before = ['locked' => $period->locked(), 'locked_by' => $period->locked_by, 'locked_at' => $period->locked_at?->toIso8601String()];
            $unlocked = $periods->unlock($period);
            if ($before['locked']) {
                $audit->record('period.unlocked', $unlocked, $before, ['locked' => false, 'period' => $unlocked->label()]);
            }
        });

        return back()->with('success', "{$period->label()} unlocked.");
    }

    public function close(Request $request, FiscalYear $year, YearEnd $yearEnd, Audit $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $year, $yearEnd, $audit) {
            $closed = $yearEnd->close($year, $this->user($request));
            $audit->record('period.year_closed', $closed, ['closed' => false], ['closed' => true, 'closing_journal_id' => $closed->closing_journal_id]);
        });

        return back()->with('success', "{$year->name} closed into retained earnings.");
    }

    public function reopen(Request $request, FiscalYear $year, YearEnd $yearEnd, Audit $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $year, $yearEnd, $audit) {
            $before = ['closed' => true, 'closing_journal_id' => $year->closing_journal_id];
            $reopened = $yearEnd->reopen($year, $this->user($request));
            $audit->record('period.year_reopened', $reopened, $before, ['closed' => false]);
        });

        return back()->with('success', "{$year->name} reopened; its months stay locked until you unlock them.");
    }
}
