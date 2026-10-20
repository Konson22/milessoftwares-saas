<?php

namespace App\Http\Controllers;

use App\Finance\FixedAssets;
use App\Models\DepreciationRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Monthly depreciation runs: the next month's charges, running it, and
 * undoing the latest run.
 */
class DepreciationController extends Controller
{
    public function index(FixedAssets $assets): Response
    {
        $next = $assets->nextMonth();
        $runs = DepreciationRun::query()->with(['journal', 'creator'])->withSum('lines', 'amount_minor')->withCount('lines')
            ->orderByDesc('month')->limit(60)->get();

        return Inertia::render('depreciation/index', [
            'next' => $next?->format('Y-m'),
            'nextLabel' => $next?->format('F Y'),
            // A month is run once it has started.
            'runnable' => $next !== null && $next->lessThanOrEqualTo(CarbonImmutable::today()->startOfMonth()),
            'preview' => $next === null ? [] : array_map(fn (array $charge) => [
                'id' => $charge['asset']->id,
                'number' => $charge['asset']->number,
                'name' => $charge['asset']->name,
                'category' => $charge['asset']->category->name,
                'amountMinor' => $charge['amount'],
            ], $assets->preview($next)),
            'runs' => $runs->map(fn (DepreciationRun $run) => [
                'id' => $run->id,
                'month' => $run->month->format('F Y'),
                'journalId' => $run->journal_id,
                'journalNumber' => $run->journal?->number,
                'totalMinor' => (int) $run->getAttribute('lines_sum_amount_minor'),
                'assets' => (int) $run->getAttribute('lines_count'),
                'by' => $run->creator?->name,
            ]),
        ]);
    }

    public function store(Request $request, FixedAssets $assets): RedirectResponse
    {
        $data = $request->validate(['month' => ['required', 'date_format:Y-m']]);

        $run = $assets->run(CarbonImmutable::createFromFormat('!Y-m', (string) $data['month']) ?: abort(422), $this->user($request));

        return back()->with('success', "Depreciation for {$run->month->format('F Y')} run.");
    }

    public function destroy(Request $request, DepreciationRun $run, FixedAssets $assets): RedirectResponse
    {
        $assets->undo($run, $this->user($request));

        return back()->with('success', "Depreciation for {$run->month->format('F Y')} undone.");
    }
}
