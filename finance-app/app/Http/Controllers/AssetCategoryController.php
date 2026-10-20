<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AssetCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asset categories: the accounts and depreciation of a kind of asset. A
 * change applies to assets registered afterwards.
 */
class AssetCategoryController extends Controller
{
    public function index(): Response
    {
        $accounts = Account::query()->where('active', true)->whereNull('bank_kind')->orderBy('code')->get();
        $options = fn (AccountType $type) => $accounts->where('type', $type)->map(fn (Account $account) => ['id' => $account->id, 'label' => $account->label()])->values();

        return Inertia::render('asset-categories/index', [
            'categories' => AssetCategory::query()->with(['assetAccount', 'accumulatedAccount', 'expenseAccount'])->orderBy('name')->get()
                ->map(fn (AssetCategory $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'assetAccountId' => $category->asset_account_id,
                    'accumulatedAccountId' => $category->accumulated_account_id,
                    'expenseAccountId' => $category->expense_account_id,
                    'assetAccount' => $category->assetAccount->label(),
                    'accumulatedAccount' => $category->accumulatedAccount->label(),
                    'expenseAccount' => $category->expenseAccount->label(),
                    'method' => $category->method,
                    'methodLabel' => AssetCategory::methodLabel($category->method),
                    'lifeMonths' => $category->life_months,
                    'rate' => $category->rate_bp === null ? null : self::percent($category->rate_bp),
                    'active' => $category->active,
                ]),
            'assetAccounts' => $options(AccountType::Asset),
            'expenseAccounts' => $options(AccountType::Expense),
            'methods' => array_map(fn (string $method) => ['value' => $method, 'label' => AssetCategory::methodLabel($method)], AssetCategory::METHODS),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $category = new AssetCategory($this->validated($request));
        $category->save();

        return back()->with('success', "Category {$category->name} added.");
    }

    public function update(Request $request, AssetCategory $category): RedirectResponse
    {
        $category->fill($this->validated($request, $category))->save();

        return back()->with('success', "Category {$category->name} saved.");
    }

    /** 2500 → "25". */
    public static function percent(int $basisPoints): string
    {
        return rtrim(rtrim(number_format($basisPoints / 100, 2, '.', ''), '0'), '.');
    }

    /** "25" → 2500. */
    public static function basisPoints(string $percent): int
    {
        return (int) round(((float) $percent) * 100);
    }

    /**
     * @return array{name: string, asset_account_id: string, accumulated_account_id: string, expense_account_id: string, method: string, life_months: int, rate_bp: int|null, active: bool}
     */
    private function validated(Request $request, ?AssetCategory $category = null): array
    {
        $balanceSheet = $this->ours('accounts')->where('type', AccountType::Asset->value)->whereNull('bank_kind');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('asset_categories', 'name')->where('organization_id', $this->organizationId())->ignore($category?->id)],
            'asset_account_id' => ['required', 'uuid', $balanceSheet],
            'accumulated_account_id' => ['required', 'uuid', 'different:asset_account_id', $this->ours('accounts')->where('type', AccountType::Asset->value)->whereNull('bank_kind')],
            'expense_account_id' => ['required', 'uuid', $this->ours('accounts')->where('type', AccountType::Expense->value)],
            'method' => ['required', Rule::in(AssetCategory::METHODS)],
            'life_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'rate' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $rate = ($data['rate'] ?? '') === '' ? null : self::basisPoints((string) $data['rate']);
        if ($data['method'] === AssetCategory::DECLINING_BALANCE && ($rate === null || $rate <= 0 || $rate > 10000)) {
            throw ValidationException::withMessages(['rate' => 'Declining balance needs a yearly rate between 0 and 100%.']);
        }

        return [
            'name' => trim((string) $data['name']),
            'asset_account_id' => (string) $data['asset_account_id'],
            'accumulated_account_id' => (string) $data['accumulated_account_id'],
            'expense_account_id' => (string) $data['expense_account_id'],
            'method' => (string) $data['method'],
            'life_months' => (int) $data['life_months'],
            'rate_bp' => $data['method'] === AssetCategory::DECLINING_BALANCE ? $rate : null,
            'active' => (bool) ($data['active'] ?? true),
        ];
    }
}
