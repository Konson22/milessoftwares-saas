<?php

namespace App\Http\Controllers;

use App\Models\BankRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bank rules: how statement lines nothing in the ledger matches are entered.
 */
class BankRuleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('bank-rules/index', [
            'rules' => BankRule::query()->with(['bankAccount', 'account'])->orderBy('position')->get()->map(fn (BankRule $rule) => [
                'id' => $rule->id,
                'bankAccount' => $rule->bankAccount?->label(),
                'contains' => $rule->contains,
                'direction' => $rule->direction,
                'account' => $rule->account->label(),
                'memo' => $rule->memo,
            ]),
            'banks' => $this->accountOptions(bank: true),
            'accounts' => $this->accountOptions(bank: false),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'bank_account_id' => ['nullable', 'uuid', $this->openAccount(bank: true)],
            'contains' => ['required', 'string', 'max:100'],
            'direction' => ['required', Rule::in([BankRule::IN, BankRule::OUT, BankRule::ANY])],
            'account_id' => ['required', 'uuid', $this->openAccount(bank: false)],
            'memo' => ['nullable', 'string', 'max:255'],
        ]);

        BankRule::query()->create($data + ['position' => (int) BankRule::query()->max('position') + 1]);

        return back()->with('success', "Rule for “{$data['contains']}” added.");
    }

    public function destroy(BankRule $rule): RedirectResponse
    {
        $rule->delete();

        return back()->with('success', 'Rule removed.');
    }
}
