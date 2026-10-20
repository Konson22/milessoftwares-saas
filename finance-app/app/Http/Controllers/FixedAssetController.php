<?php

namespace App\Http\Controllers;

use App\Finance\FixedAssets;
use App\Finance\Money;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\Bill;
use App\Models\FixedAsset;
use App\Models\Journal;
use App\Models\JournalLine;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The fixed asset register: adding assets, their schedules and disposal.
 */
class FixedAssetController extends Controller
{
    public function index(): Response
    {
        $assets = FixedAsset::query()->with('category')->withSum('depreciation', 'amount_minor')->orderBy('number')->get();
        $categories = AssetCategory::query()->with(['assetAccount', 'accumulatedAccount'])->orderBy('name')->get();
        $accumulated = fn (FixedAsset $asset) => $asset->opening_depreciation_minor + (int) $asset->getAttribute('depreciation_sum_amount_minor');

        return Inertia::render('fixed-assets/index', [
            'assets' => $assets->map(fn (FixedAsset $asset) => [
                'id' => $asset->id,
                'number' => $asset->number,
                'name' => $asset->name,
                'category' => $asset->category->name,
                'acquiredOn' => $asset->acquired_on->toDateString(),
                'costMinor' => $asset->cost_minor,
                'accumulatedMinor' => $accumulated($asset),
                'valueMinor' => $asset->cost_minor - $accumulated($asset),
                'disposedOn' => $asset->disposed_on?->toDateString(),
            ]),
            'reconciliation' => $this->reconciliation($categories, $assets->reject(fn (FixedAsset $asset) => $asset->disposed()), $accumulated),
            'categories' => $categories->where('active', true)->map(fn (AssetCategory $category) => [
                'id' => $category->id,
                'label' => $category->name,
                'method' => $category->method,
                'lifeMonths' => $category->life_months,
                'rate' => $category->rate_bp === null ? null : AssetCategoryController::percent($category->rate_bp),
                'assetAccountId' => $category->asset_account_id,
            ])->values(),
            'sources' => $this->sources(array_values(array_map('strval', $categories->pluck('asset_account_id')->unique()->all()))),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function store(Request $request, FixedAssets $assets): RedirectResponse
    {
        $data = $request->validate([
            'asset_category_id' => ['required', 'uuid', $this->ours('asset_categories')->where('active', true)],
            'name' => ['required', 'string', 'max:255'],
            'acquired_on' => ['required', 'date_format:Y-m-d'],
            'cost' => ['required', 'string', Money::rule()],
            'residual' => ['nullable', 'string', Money::rule()],
            'opening_depreciation' => ['nullable', 'string', Money::rule()],
            'life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'rate' => ['nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'source' => ['required', Rule::in(['bill', 'journal', 'existing'])],
            'bill_id' => ['nullable', 'required_if:source,bill', 'uuid', $this->ours('bills')],
            'journal_id' => ['nullable', 'required_if:source,journal', 'uuid', $this->ours('journals')],
        ]);

        $category = AssetCategory::query()->with('assetAccount')->findOrFail((string) $data['asset_category_id']);
        $asset = $assets->register(
            $category,
            trim((string) $data['name']),
            CarbonImmutable::parse($data['acquired_on']),
            Money::toMinor($data['cost']),
            [
                'residual' => ($data['residual'] ?? '') === '' ? 0 : Money::toMinor($data['residual']),
                // Only assets already in the books bring depreciation with them.
                'opening_depreciation' => $data['source'] !== 'existing' || ($data['opening_depreciation'] ?? '') === '' ? 0 : Money::toMinor($data['opening_depreciation']),
                'life_months' => isset($data['life_months']) ? (int) $data['life_months'] : null,
                'rate_bp' => ($data['rate'] ?? '') === '' ? null : AssetCategoryController::basisPoints((string) $data['rate']),
            ],
            $data['source'] === 'journal' ? Journal::query()->findOrFail((string) $data['journal_id']) : null,
            $data['source'] === 'bill' ? Bill::query()->findOrFail((string) $data['bill_id']) : null,
            $this->user($request),
        );

        return redirect()->route('fixed-assets.show', $asset)->with('success', "Asset {$asset->number} registered.");
    }

    public function show(FixedAsset $asset, FixedAssets $assets): Response
    {
        $asset->load(['category.assetAccount', 'category.accumulatedAccount', 'category.expenseAccount', 'acquisitionJournal', 'bill']);
        $accumulated = $asset->accumulated();

        return Inertia::render('fixed-assets/show', [
            'asset' => [
                'id' => $asset->id,
                'number' => $asset->number,
                'name' => $asset->name,
                'category' => $asset->category->name,
                'accounts' => [
                    'asset' => $asset->category->assetAccount->label(),
                    'accumulated' => $asset->category->accumulatedAccount->label(),
                    'expense' => $asset->category->expenseAccount->label(),
                ],
                'acquiredOn' => $asset->acquired_on->toDateString(),
                'costMinor' => $asset->cost_minor,
                'residualMinor' => $asset->residual_minor,
                'openingDepreciationMinor' => $asset->opening_depreciation_minor,
                'accumulatedMinor' => $accumulated,
                'valueMinor' => $asset->cost_minor - $accumulated,
                'method' => AssetCategory::methodLabel($asset->method),
                'lifeMonths' => $asset->life_months,
                'rate' => $asset->rate_bp === null ? null : AssetCategoryController::percent($asset->rate_bp),
                'acquisition' => $asset->acquisitionJournal === null ? null : ['id' => $asset->acquisitionJournal->id, 'number' => $asset->acquisitionJournal->number],
                'bill' => $asset->bill === null ? null : ['id' => $asset->bill->id, 'number' => $asset->bill->number],
                'disposedOn' => $asset->disposed_on?->toDateString(),
                'disposalProceedsMinor' => $asset->disposal_proceeds_minor,
                'disposalJournalId' => $asset->disposal_journal_id,
            ],
            'schedule' => $assets->schedule($asset),
            'banks' => $this->accountOptions(bank: true),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function dispose(Request $request, FixedAsset $asset, FixedAssets $assets): RedirectResponse
    {
        $data = $request->validate([
            'disposed_on' => ['required', 'date_format:Y-m-d'],
            'proceeds' => ['nullable', 'string', Money::rule()],
            'account_id' => ['nullable', 'uuid', $this->openAccount(bank: true)],
        ]);

        $assets->dispose(
            $asset,
            CarbonImmutable::parse($data['disposed_on']),
            ($data['proceeds'] ?? '') === '' ? 0 : Money::toMinor($data['proceeds']),
            isset($data['account_id']) ? Account::query()->findOrFail((string) $data['account_id']) : null,
            $this->user($request),
        );

        return back()->with('success', "Asset {$asset->number} disposed of.");
    }

    /**
     * The register against the ledger: per asset account, the cost of the
     * assets in it; per accumulated depreciation account, their depreciation.
     *
     * @param  Collection<int, AssetCategory>  $categories
     * @param  Collection<int, FixedAsset>  $held
     * @param  callable(FixedAsset): int  $accumulated
     * @return list<array{account: string, kind: string, ledgerMinor: int, registerMinor: int}>
     */
    private function reconciliation($categories, $held, callable $accumulated): array
    {
        $rows = [];
        foreach (['asset' => 'asset_account_id', 'accumulated' => 'accumulated_account_id'] as $kind => $column) {
            foreach ($categories->groupBy($column) as $accountId => $group) {
                $account = $kind === 'asset' ? $group->first()->assetAccount : $group->first()->accumulatedAccount;
                $ids = $group->pluck('id')->all();
                $assets = $held->whereIn('asset_category_id', $ids);
                $balance = (int) JournalLine::query()->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit_minor - credit_minor), 0) AS balance')->value('balance');
                $rows[] = [
                    'account' => $account->label(),
                    'kind' => $kind,
                    // Accumulated depreciation is a credit balance: shown positive.
                    'ledgerMinor' => $kind === 'asset' ? $balance : -$balance,
                    'registerMinor' => $kind === 'asset' ? (int) $assets->sum('cost_minor') : (int) $assets->sum(fn (FixedAsset $asset) => $accumulated($asset)),
                ];
            }
        }

        return $rows;
    }

    /**
     * Recent bills and journals that put money in an asset account, to
     * register assets against.
     *
     * @param  list<string>  $assetAccounts
     * @return array{bills: list<array{id: string, label: string}>, journals: list<array{id: string, label: string}>}
     */
    private function sources(array $assetAccounts): array
    {
        $journalIds = JournalLine::query()->whereIn('account_id', $assetAccounts)->where('debit_minor', '>', 0)->distinct()->pluck('journal_id');
        $journals = Journal::query()->whereIn('id', $journalIds)->orderByDesc('entry_date')->orderByDesc('number')->limit(200)->get();
        $bills = Bill::query()->with('supplier')->whereIn('journal_id', $journalIds)->orderByDesc('bill_date')->limit(200)->get();

        return [
            'bills' => array_values($bills->map(fn (Bill $bill) => ['id' => $bill->id, 'label' => "{$bill->number} · {$bill->bill_date->toDateString()} · {$bill->supplier->name}"])->all()),
            'journals' => array_values($journals->where('source_type', '!=', 'bill')->map(fn (Journal $journal) => ['id' => $journal->id, 'label' => "{$journal->number} · {$journal->entry_date->toDateString()} · {$journal->memo}"])->all()),
        ];
    }
}
