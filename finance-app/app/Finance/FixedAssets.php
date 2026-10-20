<?php

namespace App\Finance;

use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\Bill;
use App\Models\DepreciationLine;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\Journal;
use App\Models\JournalLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * The fixed asset register and its depreciation.
 *
 * An asset's cost is already in its asset account, put there by a bill, a
 * journal or opening balances: registering it posts nothing. Depreciation
 * runs month by month, in order and once per month, each run posting one
 * journal at the month end (expense against accumulated depreciation). Each
 * run charges what brings an asset to its schedule for that month, so an
 * asset registered late catches up. Nothing is depreciated below the
 * residual value. Disposal takes the asset and its depreciation out of the
 * books, with the gain or loss against the proceeds.
 */
class FixedAssets
{
    public const FEATURE = 'finance.fixed_assets';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly DocumentNumbers $numbers,
        private readonly ChartOfAccounts $chart,
    ) {}

    /**
     * Whether the organization's plan includes fixed assets.
     */
    public static function enabled(): bool
    {
        return app(CurrentAccess::class)->get()?->feature(self::FEATURE) !== null;
    }

    /**
     * Adds an asset to the register. With a bill or journal, that entry must
     * have put at least the cost (with what other assets already took from
     * it) in the category's asset account.
     *
     * @param  array{residual?: int, opening_depreciation?: int, life_months?: int|null, rate_bp?: int|null}  $terms
     *
     * @throws ValidationException
     */
    public function register(AssetCategory $category, string $name, CarbonImmutable $acquiredOn, int $costMinor, array $terms, ?Journal $journal, ?Bill $bill, User $by): FixedAsset
    {
        $residual = $terms['residual'] ?? 0;
        $opening = $terms['opening_depreciation'] ?? 0;
        if ($costMinor <= 0) {
            throw ValidationException::withMessages(['cost' => 'The cost must be more than zero.']);
        }
        if ($residual < 0 || $residual >= $costMinor) {
            throw ValidationException::withMessages(['residual' => 'The residual value is less than the cost.']);
        }
        if ($opening < 0 || $opening > $costMinor - $residual) {
            throw ValidationException::withMessages(['opening_depreciation' => 'Depreciation so far is at most the cost less the residual value.']);
        }
        if ($bill !== null) {
            $journal = $bill->journal_id === null ? null : Journal::query()->find($bill->journal_id);
            if ($journal === null) {
                throw ValidationException::withMessages(['bill_id' => "Bill {$bill->number} has no journal."]);
            }
        }

        return DB::transaction(function () use ($category, $name, $acquiredOn, $costMinor, $residual, $opening, $terms, $journal, $bill, $by) {
            if ($journal !== null) {
                // Locking the journal serializes assets registered against it.
                Journal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
                $debited = (int) JournalLine::query()->where('journal_id', $journal->id)->where('account_id', $category->asset_account_id)->sum('debit_minor');
                $taken = (int) FixedAsset::query()->where('acquisition_journal_id', $journal->id)->sum('cost_minor');
                if ($costMinor > $debited - $taken) {
                    $field = $bill === null ? 'journal_id' : 'bill_id';
                    throw ValidationException::withMessages([$field => "Journal {$journal->number} put ".($debited - $taken).' (minor units) not yet registered in '.$category->assetAccount->label().'; the cost is more.']);
                }
            }

            return FixedAsset::query()->create([
                'number' => $this->numbers->next('fixed_asset', 'FA', 4),
                'name' => $name,
                'asset_category_id' => $category->id,
                'acquired_on' => $acquiredOn,
                'cost_minor' => $costMinor,
                'residual_minor' => $residual,
                'opening_depreciation_minor' => $opening,
                'method' => $category->method,
                'life_months' => $terms['life_months'] ?? $category->life_months,
                'rate_bp' => $terms['rate_bp'] ?? $category->rate_bp,
                'acquisition_journal_id' => $journal?->id,
                'bill_id' => $bill?->id,
                'created_by' => $by->id,
            ]);
        });
    }

    /**
     * Depreciation to date the schedule reaches by the end of the month
     * (opening depreciation included).
     */
    public static function target(FixedAsset $asset, CarbonImmutable $month): int
    {
        $months = min($asset->life_months, self::monthsBetween($asset->firstMonth(), $month->startOfMonth()) + 1);
        if ($months <= 0) {
            return $asset->opening_depreciation_minor;
        }

        $start = $asset->cost_minor - $asset->opening_depreciation_minor;
        $depreciable = $start - $asset->residual_minor;

        if ($asset->method === AssetCategory::DECLINING_BALANCE) {
            $value = $start;
            for ($month = 1; $month <= $months; $month++) {
                // The last month of its life takes it to the residual value.
                $value = $month === $asset->life_months
                    ? $asset->residual_minor
                    : max($asset->residual_minor, $value - intdiv($value * (int) $asset->rate_bp + 60000, 120000));
            }

            return $asset->cost_minor - $value;
        }

        // Straight line, spread so the months add up exactly.
        return $asset->opening_depreciation_minor + intdiv($depreciable * $months * 2 + $asset->life_months, $asset->life_months * 2);
    }

    /**
     * The asset's months: what each charges, and its value at the end.
     *
     * @return list<array{month: string, amountMinor: int, valueMinor: int, posted: bool}>
     */
    public function schedule(FixedAsset $asset): array
    {
        $posted = DepreciationLine::query()
            ->join('depreciation_runs', 'depreciation_runs.id', '=', 'depreciation_lines.depreciation_run_id')
            ->where('depreciation_lines.fixed_asset_id', $asset->id)
            ->get(['depreciation_runs.month', 'depreciation_lines.amount_minor'])
            ->mapWithKeys(fn ($line) => [substr((string) $line->getAttribute('month'), 0, 7) => (int) $line->getAttribute('amount_minor')])
            ->all();

        $rows = [];
        $before = $asset->opening_depreciation_minor;
        for ($index = 0; $index < $asset->life_months; $index++) {
            $month = $asset->firstMonth()->addMonthsNoOverflow($index);
            $key = $month->format('Y-m');
            $target = self::target($asset, $month);
            $amount = $posted[$key] ?? $target - $before;
            $before = $target;
            $rows[] = ['month' => $key, 'amountMinor' => $amount, 'valueMinor' => $asset->cost_minor - $target, 'posted' => isset($posted[$key])];
        }
        // Months run after the end of its life (catching up).
        foreach ($posted as $key => $amount) {
            if ($key > ($rows === [] ? '' : $rows[array_key_last($rows)]['month'])) {
                $rows[] = ['month' => $key, 'amountMinor' => $amount, 'valueMinor' => $asset->cost_minor - $asset->accumulated(), 'posted' => true];
            }
        }

        return $rows;
    }

    /**
     * What a run for the month would charge, per asset.
     *
     * @return list<array{asset: FixedAsset, amount: int}>
     */
    public function preview(CarbonImmutable $month): array
    {
        $month = $month->startOfMonth();
        $assets = FixedAsset::query()->with('category')
            ->whereDate('acquired_on', '<=', $month->endOfMonth())
            ->where(fn ($query) => $query->whereNull('disposed_on')->orWhereDate('disposed_on', '>', $month->endOfMonth()))
            ->orderBy('number')
            ->get();

        $charges = [];
        foreach ($assets as $asset) {
            $amount = self::target($asset, $month) - $asset->accumulated();
            if ($amount > 0) {
                $charges[] = ['asset' => $asset, 'amount' => $amount];
            }
        }

        return $charges;
    }

    /**
     * The month the next run is for: after the latest run, or the earliest
     * acquisition when nothing has run.
     */
    public function nextMonth(): ?CarbonImmutable
    {
        $latest = DepreciationRun::query()->orderByDesc('month')->first();
        if ($latest !== null) {
            return $latest->month->addMonthNoOverflow()->startOfMonth();
        }
        $first = FixedAsset::query()->orderBy('acquired_on')->value('acquired_on');

        return $first === null ? null : CarbonImmutable::parse((string) $first)->startOfMonth();
    }

    /**
     * Depreciates every asset for the month, posted at the month end.
     *
     * @throws ValidationException when the month is run, out of order, or not started
     */
    public function run(CarbonImmutable $month, User $by): DepreciationRun
    {
        $month = $month->startOfMonth();
        if ($month->greaterThan(CarbonImmutable::today()->startOfMonth())) {
            throw ValidationException::withMessages(['month' => 'Run depreciation once the month has started.']);
        }

        return DB::transaction(function () use ($month, $by) {
            $latest = DepreciationRun::query()->orderByDesc('month')->lockForUpdate()->first();
            if ($latest !== null && $month->lessThanOrEqualTo($latest->month)) {
                throw ValidationException::withMessages(['month' => "Depreciation is already run for {$month->format('F Y')}."]);
            }
            if ($latest !== null && ! $month->equalTo($latest->month->addMonthNoOverflow()->startOfMonth())) {
                throw ValidationException::withMessages(['month' => 'Run '.$latest->month->addMonthNoOverflow()->format('F Y').' first: months are run in order.']);
            }

            $charges = $this->preview($month);
            $journal = null;
            if ($charges !== []) {
                $lines = [];
                foreach (collect($charges)->groupBy(fn (array $charge) => $charge['asset']->asset_category_id) as $group) {
                    $category = $group->first()['asset']->category;
                    $total = (int) $group->sum('amount');
                    $lines[] = ['account' => $category->expenseAccount, 'debit' => $total, 'description' => "Depreciation: {$category->name}"];
                    $lines[] = ['account' => $category->accumulatedAccount, 'credit' => $total, 'description' => "Depreciation: {$category->name}"];
                }
                $journal = $this->ledger->post($month->endOfMonth()->startOfDay(), "Depreciation for {$month->format('F Y')}", $lines, [
                    'source_type' => Journal::DEPRECIATION,
                    'source_reference' => $month->format('Y-m'),
                ], $by);
            }

            $run = DepreciationRun::query()->create(['month' => $month, 'journal_id' => $journal?->id, 'created_by' => $by->id]);
            foreach ($charges as $charge) {
                $run->lines()->create(['fixed_asset_id' => $charge['asset']->id, 'amount_minor' => $charge['amount']]);
            }

            return $run;
        });
    }

    /**
     * Takes back the latest run: its journal is reversed at the same date.
     *
     * @throws ValidationException when an asset it charged has since been disposed of
     */
    public function undo(DepreciationRun $run, User $by): void
    {
        DB::transaction(function () use ($run, $by) {
            $latest = DepreciationRun::query()->orderByDesc('month')->lockForUpdate()->first();
            if ($latest?->id !== $run->id) {
                throw ValidationException::withMessages(['run' => 'Only the latest run can be undone.']);
            }
            $disposed = FixedAsset::query()->whereNotNull('disposed_on')->whereIn('id', $run->lines()->pluck('fixed_asset_id'))->value('number');
            if ($disposed !== null) {
                throw ValidationException::withMessages(['run' => "Asset {$disposed} has been disposed of since: undo its disposal first."]);
            }

            if ($run->journal !== null) {
                $this->ledger->reverse($run->journal, $run->month->endOfMonth()->startOfDay(), "Undo depreciation for {$run->month->format('F Y')}", $by);
            }
            $run->delete();
        });
    }

    /**
     * Sells or writes off an asset: its cost and depreciation leave the
     * books; the proceeds go to the bank or cash account; the difference
     * from its value is a gain or loss.
     *
     * @throws ValidationException
     */
    public function dispose(FixedAsset $asset, CarbonImmutable $date, int $proceedsMinor, ?Account $into, User $by): FixedAsset
    {
        if ($proceedsMinor < 0) {
            throw ValidationException::withMessages(['proceeds' => 'The proceeds cannot be negative.']);
        }
        if ($proceedsMinor > 0 && ($into === null || ! $into->isBank())) {
            throw ValidationException::withMessages(['account_id' => 'Say which bank or cash account received the money.']);
        }
        if ($date->lessThan($asset->acquired_on) || $date->greaterThan(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['disposed_on' => 'Dispose of it between its acquisition and today.']);
        }

        return DB::transaction(function () use ($asset, $date, $proceedsMinor, $into, $by) {
            $locked = FixedAsset::query()->with('category')->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if ($locked->disposed()) {
                throw ValidationException::withMessages(['disposed_on' => "Asset {$locked->number} is already disposed of."]);
            }
            $later = DepreciationLine::query()
                ->join('depreciation_runs', 'depreciation_runs.id', '=', 'depreciation_lines.depreciation_run_id')
                ->where('depreciation_lines.fixed_asset_id', $locked->id)
                ->whereDate('depreciation_runs.month', '>', $date->startOfMonth())
                ->exists();
            if ($later) {
                throw ValidationException::withMessages(['disposed_on' => 'It is depreciated for months after this date: undo those runs first.']);
            }

            $category = $locked->category;
            $accumulated = $locked->accumulated();
            $gain = $proceedsMinor - ($locked->cost_minor - $accumulated);
            $lines = [['account' => $category->assetAccount, 'credit' => $locked->cost_minor, 'description' => "{$locked->number} {$locked->name}"]];
            if ($accumulated > 0) {
                $lines[] = ['account' => $category->accumulatedAccount, 'debit' => $accumulated, 'description' => "{$locked->number} depreciation"];
            }
            if ($proceedsMinor > 0) {
                $lines[] = ['account' => $into, 'debit' => $proceedsMinor, 'description' => "{$locked->number} proceeds"];
            }
            if ($gain !== 0) {
                $this->chart->ensure();
                $lines[] = ['account' => Account::system(Account::DISPOSAL), $gain > 0 ? 'credit' : 'debit' => abs($gain), 'description' => ($gain > 0 ? 'Gain' : 'Loss')." on {$locked->number}"];
            }

            $journal = $this->ledger->post($date, "Disposal of {$locked->number} {$locked->name}", $lines, [
                'source_type' => Journal::ASSET_DISPOSAL,
                'source_reference' => $locked->number,
            ], $by);

            $locked->forceFill([
                'disposed_on' => $date,
                'disposal_proceeds_minor' => $proceedsMinor,
                'disposal_journal_id' => $journal->id,
            ])->save();

            return $locked;
        });
    }

    private static function monthsBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return ($to->year - $from->year) * 12 + $to->month - $from->month;
    }
}
