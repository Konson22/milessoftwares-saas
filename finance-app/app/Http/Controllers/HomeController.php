<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Finance\Balances;
use App\Finance\Statements;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\Journal;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * The books at a glance; each figure only for members who may see it.
 */
class HomeController extends Controller
{
    public function __invoke(CurrentAccess $currentAccess, Balances $balances, Statements $statements): Response
    {
        $access = $currentAccess->get();
        $can = fn (string $permission) => $access?->allowsIn($permission) ?? false;
        $today = CarbonImmutable::today();

        $cash = null;
        if ($can('finance.banking.view')) {
            $totals = $balances->totals();
            $cash = Account::query()->banks()->orderBy('code')->get()->map(fn (Account $bank) => [
                'id' => $bank->id,
                'name' => $bank->name,
                'balanceMinor' => AccountType::Asset->balance($totals[$bank->id]['debits'] ?? 0, $totals[$bank->id]['credits'] ?? 0),
            ])->values()->all();
        }

        $receivable = $can('finance.receivables.view') ? [
            'outstandingMinor' => (int) Invoice::query()->whereNull('voided_at')->selectRaw('COALESCE(SUM(total_minor - credited_minor - paid_minor), 0) AS owed')->value('owed'),
            'overdue' => Invoice::query()->whereNull('voided_at')->whereRaw('paid_minor + credited_minor < total_minor')->whereDate('due_on', '<', $today)->count(),
        ] : null;

        $payable = $can('finance.payables.view') ? [
            'outstandingMinor' => (int) Bill::query()->selectRaw('COALESCE(SUM(total_minor - paid_minor), 0) AS owed')->value('owed'),
            'overdue' => Bill::query()->whereColumn('paid_minor', '<', 'total_minor')->whereDate('due_on', '<', $today)->count(),
            'dueSoon' => Bill::query()->whereColumn('paid_minor', '<', 'total_minor')->whereDate('due_on', '>=', $today)->whereDate('due_on', '<=', $today->addDays(7))->count(),
        ] : null;

        $month = null;
        if ($can('finance.reports.view')) {
            $pnl = $statements->profitAndLoss($today->startOfMonth(), $today);
            $month = ['revenueMinor' => $pnl['revenueMinor'], 'expensesMinor' => $pnl['expensesMinor'], 'profitMinor' => $pnl['profitMinor']];
        }

        $recent = $can('finance.journals.view')
            ? Journal::query()->withSum('lines', 'debit_minor')->orderByDesc('created_at')->limit(8)->get()->map(fn (Journal $journal) => [
                'id' => $journal->id,
                'number' => $journal->number,
                'date' => $journal->entry_date->toDateString(),
                'memo' => $journal->memo,
                'source' => $journal->source_app,
                'totalMinor' => (int) $journal->getAttribute('lines_sum_debit_minor'),
            ])->values()->all()
            : null;

        return Inertia::render('home', [
            'cash' => $cash,
            'receivable' => $receivable,
            'payable' => $payable,
            'month' => $month,
            'recent' => $recent,
        ]);
    }
}
