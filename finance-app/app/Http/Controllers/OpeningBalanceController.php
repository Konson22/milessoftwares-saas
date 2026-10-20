<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Finance\ChartOfAccounts;
use App\Finance\Money;
use App\Finance\OpeningBalances;
use App\Finance\Periods;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Opening balances of the balance sheet accounts.
 */
class OpeningBalanceController extends Controller
{
    public function edit(OpeningBalances $opening, Periods $periods, ChartOfAccounts $chart): Response
    {
        $chart->ensure();
        $current = $opening->current();
        $journal = $opening->journal();

        return Inertia::render('opening-balances/edit', [
            'date' => $periods->booksStart()?->toDateString(),
            'lockedUntil' => $periods->lockedUntil()?->toDateString(),
            'journal' => $journal === null ? null : ['id' => $journal->id, 'number' => $journal->number],
            'accounts' => Account::query()
                ->whereIn('type', [AccountType::Asset, AccountType::Liability, AccountType::Equity])
                ->where(fn ($query) => $query->whereNull('system_key')->orWhere('system_key', '!=', Account::OPENING_BALANCE))
                ->where(fn ($query) => $query->where('active', true)->orWhereIn('id', array_keys($current)))
                ->orderBy('code')
                ->get()
                ->map(fn (Account $account) => [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type->value,
                    'balanceMinor' => $current[$account->id] ?? 0,
                ])
                ->values(),
        ]);
    }

    public function update(Request $request, OpeningBalances $opening): RedirectResponse
    {
        $data = $request->validate([
            'balances' => ['present', 'array', 'max:500'],
            'balances.*.account_id' => ['required', 'uuid', $this->ours('accounts')],
            'balances.*.debit' => ['nullable', 'string', Money::rule()],
            'balances.*.credit' => ['nullable', 'string', Money::rule()],
        ]);

        $balances = [];
        foreach ($data['balances'] as $row) {
            $debit = ($row['debit'] ?? '') === '' ? 0 : Money::toMinor($row['debit']);
            $credit = ($row['credit'] ?? '') === '' ? 0 : Money::toMinor($row['credit']);
            $balances[$row['account_id']] = ($balances[$row['account_id']] ?? 0) + $debit - $credit;
        }

        $journal = $opening->save($balances, $this->user($request));

        return redirect()->route('opening-balances.edit')->with('success', $journal === null
            ? 'Opening balances cleared.'
            : "Opening balances posted as {$journal->number}.");
    }
}
