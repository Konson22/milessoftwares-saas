<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Enums\BankKind;
use App\Finance\Balances;
use App\Models\Account;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The chart of accounts, and each account's ledger.
 */
class AccountController extends Controller
{
    public function index(Balances $balances): Response
    {
        $totals = $balances->totals();

        return Inertia::render('accounts/index', [
            'accounts' => Account::query()->orderBy('code')->get()->map(fn (Account $account) => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'bankKind' => $account->bank_kind?->value,
                'bankName' => $account->bank_name,
                'bankAccountNumber' => $account->bank_account_number,
                'description' => $account->description,
                'system' => $account->system_key !== null,
                'active' => $account->active,
                'balanceMinor' => $account->type->balance($totals[$account->id]['debits'] ?? 0, $totals[$account->id]['credits'] ?? 0),
            ]),
            'types' => array_map(fn (AccountType $type) => ['value' => $type->value, 'label' => $type->label()], AccountType::cases()),
            'bankKinds' => array_map(fn (BankKind $kind) => ['value' => $kind->value, 'label' => $kind->label()], BankKind::cases()),
        ]);
    }

    /**
     * The account's ledger for a period: every line, with the running balance.
     */
    public function show(Request $request, Account $account): Response
    {
        [$from, $to] = $this->period($request, CarbonImmutable::today()->startOfYear());

        $before = JournalLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journal', fn ($query) => $query->whereDate('entry_date', '<', $from))
            ->selectRaw('COALESCE(SUM(debit_minor), 0) AS debits, COALESCE(SUM(credit_minor), 0) AS credits')
            ->toBase()
            ->first();
        $opening = $account->type->balance((int) ($before->debits ?? 0), (int) ($before->credits ?? 0));

        $lines = JournalLine::query()
            ->where('journal_lines.account_id', $account->id)
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->whereDate('journals.entry_date', '>=', $from)
            ->whereDate('journals.entry_date', '<=', $to)
            ->orderBy('journals.entry_date')
            ->orderBy('journals.number')
            ->orderBy('journal_lines.position')
            ->select('journal_lines.*', 'journals.number', 'journals.entry_date', 'journals.memo', 'journals.source_app', 'journals.source_type')
            ->get();

        $balance = $opening;
        $entries = [];
        foreach ($lines as $line) {
            $balance += $account->type->balance($line->debit_minor, $line->credit_minor);
            $entries[] = [
                'id' => $line->id,
                'journalId' => $line->journal_id,
                'number' => (string) $line->getAttribute('number'),
                'date' => substr((string) $line->getAttribute('entry_date'), 0, 10),
                'memo' => (string) $line->getAttribute('memo'),
                'description' => $line->description,
                'source' => (string) $line->getAttribute('source_app'),
                'debitMinor' => $line->debit_minor,
                'creditMinor' => $line->credit_minor,
                'balanceMinor' => $balance,
            ];
        }

        return Inertia::render('accounts/show', [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->value,
                'typeLabel' => $account->type->label(),
                'bankKind' => $account->bank_kind?->label(),
                'active' => $account->active,
            ],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'openingMinor' => $opening,
            'closingMinor' => $balance,
            'entries' => $entries,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = new Account($this->validated($request));
        $account->save();

        return back()->with('success', "Account {$account->label()} added.");
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $data = $this->validated($request, $account);

        $used = $account->lines()->exists();
        if ($used && $data['type'] !== $account->type->value) {
            throw ValidationException::withMessages(['type' => 'An account with postings keeps its type.']);
        }
        if ($account->system_key !== null && ($data['type'] !== $account->type->value || ! $data['active'] || ($data['bank_kind'] ?? null) !== $account->bank_kind?->value)) {
            throw ValidationException::withMessages(['active' => 'Finance posts to this account automatically: it stays open, with its type.']);
        }

        $account->fill($data)->save();

        return back()->with('success', "Account {$account->label()} saved.");
    }

    /**
     * @return array{code: string, name: string, type: string, bank_kind: string|null, bank_name: string|null, bank_account_number: string|null, description: string|null, active: bool}
     */
    private function validated(Request $request, ?Account $account = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9.-]+$/', Rule::unique('accounts', 'code')->where('organization_id', $this->organizationId())->ignore($account?->id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'bank_kind' => ['nullable', Rule::enum(BankKind::class)],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (($data['bank_kind'] ?? null) !== null && $data['type'] !== AccountType::Asset->value) {
            throw ValidationException::withMessages(['bank_kind' => 'Bank and cash accounts are assets.']);
        }

        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'bank_kind' => $data['bank_kind'] ?? null,
            'bank_name' => ($data['bank_kind'] ?? null) === null ? null : ($data['bank_name'] ?? null),
            'bank_account_number' => ($data['bank_kind'] ?? null) === null ? null : ($data['bank_account_number'] ?? null),
            'description' => $data['description'] ?? null,
            'active' => (bool) ($data['active'] ?? true),
        ];
    }
}
