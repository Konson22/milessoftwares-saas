<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Enums\BankKind;
use App\Finance\Balances;
use App\Finance\Banking;
use App\Finance\Money;
use App\Models\Account;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bank and cash accounts: balances, recent movements, money in and out,
 * and transfers.
 */
class BankingController extends Controller
{
    public function index(Balances $balances): Response
    {
        $totals = $balances->totals();
        $banks = Account::query()->banks()->orderBy('code')->get();

        $movements = JournalLine::query()
            ->whereIn('journal_lines.account_id', $banks->pluck('id'))
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->orderByDesc('journals.entry_date')
            ->orderByDesc('journals.number')
            ->limit(30)
            ->select('journal_lines.*', 'journals.number', 'journals.entry_date', 'journals.memo', 'journals.source_app')
            ->get();

        return Inertia::render('banking/index', [
            'banks' => $banks->map(fn (Account $bank) => [
                'id' => $bank->id,
                'code' => $bank->code,
                'name' => $bank->name,
                'kind' => $bank->bank_kind?->value,
                'kindLabel' => $bank->bank_kind?->label(),
                'bankName' => $bank->bank_name,
                'accountNumber' => $bank->bank_account_number,
                'active' => $bank->active,
                'balanceMinor' => AccountType::Asset->balance($totals[$bank->id]['debits'] ?? 0, $totals[$bank->id]['credits'] ?? 0),
            ]),
            'movements' => $movements->map(fn (JournalLine $line) => [
                'id' => $line->id,
                'journalId' => $line->journal_id,
                'number' => (string) $line->getAttribute('number'),
                'date' => substr((string) $line->getAttribute('entry_date'), 0, 10),
                'memo' => (string) $line->getAttribute('memo'),
                'source' => (string) $line->getAttribute('source_app'),
                'account' => $banks->firstWhere('id', $line->account_id)?->name,
                'inMinor' => $line->debit_minor,
                'outMinor' => $line->credit_minor,
            ]),
            'accounts' => $this->accountOptions(bank: false),
            'bankOptions' => $this->accountOptions(bank: true),
            'bankKinds' => array_map(fn (BankKind $kind) => ['value' => $kind->value, 'label' => $kind->label()], BankKind::cases()),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function moneyOut(Request $request, Banking $banking): RedirectResponse
    {
        $data = $this->validated($request);
        $journal = $banking->moneyOut($data['bank'], $data['other'], $data['amount'], $data['date'], $data['description'], $this->user($request));

        return back()->with('success', "Money out recorded ({$journal->number}).");
    }

    public function moneyIn(Request $request, Banking $banking): RedirectResponse
    {
        $data = $this->validated($request);
        $journal = $banking->moneyIn($data['bank'], $data['other'], $data['amount'], $data['date'], $data['description'], $this->user($request));

        return back()->with('success', "Money in recorded ({$journal->number}).");
    }

    public function transfer(Request $request, Banking $banking): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid', $this->openAccount(bank: true)],
            'to_account_id' => ['required', 'uuid', 'different:account_id', $this->openAccount(bank: true)],
            'amount' => ['required', 'string', Money::rule()],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
        ]);

        $journal = $banking->transfer(
            Account::query()->findOrFail((string) $data['account_id']),
            Account::query()->findOrFail((string) $data['to_account_id']),
            Money::toMinor($data['amount']),
            CarbonImmutable::parse($data['entry_date']),
            $data['description'],
            $this->user($request),
        );

        return back()->with('success', "Transfer recorded ({$journal->number}).");
    }

    /**
     * @return array{bank: Account, other: Account, amount: int, date: CarbonImmutable, description: string}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid', $this->openAccount(bank: true)],
            'other_account_id' => ['required', 'uuid', $this->openAccount(bank: false)],
            'amount' => ['required', 'string', Money::rule()],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
        ]);

        return [
            'bank' => Account::query()->findOrFail((string) $data['account_id']),
            'other' => Account::query()->findOrFail((string) $data['other_account_id']),
            'amount' => Money::toMinor($data['amount']),
            'date' => CarbonImmutable::parse($data['entry_date']),
            'description' => $data['description'],
        ];
    }
}
