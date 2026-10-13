<?php

namespace App\Http\Controllers;

use App\Finance\Statements;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Financial statements.
 */
class ReportController extends Controller
{
    public function trialBalance(Request $request, Statements $statements): Response
    {
        $asOf = $this->asOf($request);

        return Inertia::render('reports/trial-balance', [
            'asOf' => $asOf->toDateString(),
        ] + $statements->trialBalance($asOf));
    }

    public function profitAndLoss(Request $request, Statements $statements): Response
    {
        [$from, $to] = $this->period($request, CarbonImmutable::today()->startOfYear());

        return Inertia::render('reports/profit-and-loss', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ] + $statements->profitAndLoss($from, $to));
    }

    public function balanceSheet(Request $request, Statements $statements): Response
    {
        $asOf = $this->asOf($request);

        return Inertia::render('reports/balance-sheet', [
            'asOf' => $asOf->toDateString(),
        ] + $statements->balanceSheet($asOf));
    }

    private function asOf(Request $request): CarbonImmutable
    {
        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return isset($data['as_of']) ? CarbonImmutable::parse($data['as_of']) : CarbonImmutable::today();
    }
}
