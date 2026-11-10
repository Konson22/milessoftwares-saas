<?php

namespace App\Petrol;

use App\Models\BankDeposit;
use App\Models\CashMovement;
use App\Models\CashTransfer;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\FuelPrice;
use App\Models\FuelSale;
use App\Models\StationCurrency;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Company currency catalog. SSP and USD are created when the catalog is read.
 * A station that has not chosen yet accepts every active company currency.
 */
class Currencies
{
    /** @var list<array{code: string, name: string, symbol: string, decimals: int}> */
    public const DEFAULTS = [
        ['code' => 'SSP', 'name' => 'South Sudanese Pound', 'symbol' => 'SSP', 'decimals' => 2],
        ['code' => 'USD', 'name' => 'United States Dollar', 'symbol' => '$', 'decimals' => 2],
    ];

    public function __construct(private readonly Audit $audit) {}

    public function ensure(): void
    {
        foreach (self::DEFAULTS as $row) {
            Currency::query()->firstOrCreate(['code' => $row['code']], [
                'name' => $row['name'],
                'symbol' => $row['symbol'],
                'decimals' => $row['decimals'],
                'active' => true,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        $this->ensure();

        return array_values(Currency::query()->where('active', true)->orderBy('code')->pluck('code')->map(fn ($code) => (string) $code)->all());
    }

    /**
     * Active currencies, plus any code that already appears on cash so a
     * deactivated currency is still visible in history.
     *
     * @return list<string>
     */
    public function reportCodes(): array
    {
        $this->ensure();
        $codes = Currency::query()->where('active', true)->pluck('code')->all();
        $codes[] = Money::currency();
        foreach ([CashMovement::class, CashTransfer::class, BankDeposit::class, FuelSale::class] as $model) {
            foreach ($model::query()->whereNotNull('currency')->distinct()->pluck('currency') as $code) {
                $codes[] = strtoupper((string) $code);
            }
        }

        $codes = array_values(array_unique(array_filter($codes, fn ($code) => is_string($code) && $code !== '')));
        sort($codes);

        return $codes;
    }

    /**
     * @return list<array{id: string, code: string, name: string, symbol: string, decimals: int, active: bool, createdBy: ?string, createdAt: ?string}>
     */
    public function catalog(): array
    {
        $this->ensure();

        $rows = Currency::query()->orderBy('code')->get();
        $names = User::query()->whereIn('id', $rows->pluck('created_by')->filter()->all())->pluck('name', 'id');

        return array_values($rows->map(fn (Currency $currency) => [
            'id' => $currency->id,
            'code' => $currency->code,
            'name' => $currency->name,
            'symbol' => $currency->symbol,
            'decimals' => (int) $currency->decimals,
            'active' => (bool) $currency->active,
            'createdBy' => $currency->created_by === null ? null : (isset($names[$currency->created_by]) ? (string) $names[$currency->created_by] : null),
            'createdAt' => $currency->created_at?->toIso8601String(),
        ])->all());
    }

    /**
     * @return list<array{code: string, name: string, symbol: string, decimals: int}>
     */
    public function accepted(string $branchId): array
    {
        $this->ensure();
        $active = Currency::query()->where('active', true)->orderBy('code')->get();
        $chosen = StationCurrency::query()->where('branch_id', $branchId)->pluck('enabled', 'code');
        $books = Money::currency();

        return array_values($active->filter(function (Currency $currency) use ($chosen, $books) {
            if ($currency->code === $books) {
                return true;
            }
            if ($chosen->isEmpty()) {
                return true;
            }

            return (bool) $chosen->get($currency->code, false);
        })->map(fn (Currency $currency) => [
            'code' => $currency->code,
            'name' => $currency->name,
            'symbol' => $currency->symbol,
            'decimals' => (int) $currency->decimals,
        ])->all());
    }

    /**
     * @return list<string>
     */
    public function acceptedCodes(string $branchId): array
    {
        return array_column($this->accepted($branchId), 'code');
    }

    public function assertAccepted(string $branchId, ?string $currency): void
    {
        $currency = strtoupper((string) $currency);
        if ($currency === '' || $currency === Money::currency()) {
            return;
        }
        if (! in_array($currency, $this->acceptedCodes($branchId), true)) {
            throw ValidationException::withMessages(['currency' => $currency.' is not accepted at this station.']);
        }
    }

    public function create(string $code, string $name, string $symbol, int $decimals, User $by): Currency
    {
        $this->ensure();
        $code = strtoupper($code);
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw ValidationException::withMessages(['code' => 'Use a three-letter currency code.']);
        }
        if (Currency::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => $code.' is already in this company.']);
        }
        $this->guardDecimals($decimals);

        $currency = Currency::query()->create([
            'code' => $code,
            'name' => trim($name),
            'symbol' => trim($symbol),
            'decimals' => $decimals,
            'active' => true,
            'created_by' => $by->id,
        ]);
        $this->audit->record($by, 'currency.created', $currency, null, ['code' => $code], null);

        return $currency;
    }

    public function update(Currency $currency, string $name, string $symbol, int $decimals, bool $active, User $by): Currency
    {
        $this->guardDecimals($decimals);
        if ($decimals !== (int) $currency->decimals && $this->used($currency->code)) {
            throw ValidationException::withMessages(['decimals' => 'This currency is already on transactions, so its precision stays '.$currency->decimals.'.']);
        }
        if ($currency->code === Money::currency() && ! $active) {
            throw ValidationException::withMessages(['active' => 'The workspace currency stays available.']);
        }
        $before = ['name' => $currency->name, 'active' => $currency->active, 'decimals' => $currency->decimals];
        $currency->forceFill([
            'name' => trim($name),
            'symbol' => trim($symbol),
            'decimals' => $decimals,
            'active' => $active,
        ])->save();
        $this->audit->record($by, 'currency.updated', $currency, $before, [
            'name' => $currency->name,
            'active' => $currency->active,
            'decimals' => $currency->decimals,
        ], null);

        return $currency;
    }

    /**
     * @param  list<string>  $enabled
     */
    public function setStation(string $branchId, array $enabled, User $by): void
    {
        $this->ensure();
        $books = Money::currency();
        $enabled = array_values(array_unique(array_map(fn ($code) => strtoupper((string) $code), $enabled)));
        $enabled[] = $books;
        $active = Currency::query()->where('active', true)->pluck('code')->all();
        foreach ($enabled as $code) {
            if (! in_array($code, $active, true)) {
                throw ValidationException::withMessages(['currencies' => $code.' is not an active company currency.']);
            }
        }

        DB::transaction(function () use ($branchId, $enabled, $active, $by) {
            foreach ($active as $code) {
                StationCurrency::query()->updateOrCreate(
                    ['branch_id' => $branchId, 'code' => $code],
                    ['enabled' => in_array($code, $enabled, true)],
                );
            }
            $sample = StationCurrency::query()->where('branch_id', $branchId)->where('code', Money::currency())->first();
            if ($sample !== null) {
                $this->audit->record($by, 'currency.station', $sample, null, ['enabled' => $enabled], $branchId);
            }
        });
    }

    /**
     * @return list<string>
     */
    public function enabledAt(string $branchId): array
    {
        $chosen = array_values(StationCurrency::query()->where('branch_id', $branchId)->where('enabled', true)->pluck('code')->map(fn ($code) => (string) $code)->all());
        if ($chosen === []) {
            return $this->acceptedCodes($branchId);
        }

        return $chosen;
    }

    private function guardDecimals(int $decimals): void
    {
        if ($decimals < 0 || $decimals > 4) {
            throw ValidationException::withMessages(['decimals' => 'Use 0 to 4 decimal places.']);
        }
    }

    private function used(string $code): bool
    {
        return FuelSale::query()->where('currency', $code)->exists()
            || FuelPrice::query()->where('currency', $code)->exists()
            || CashMovement::query()->where('currency', $code)->exists()
            || CashTransfer::query()->where('currency', $code)->exists()
            || BankDeposit::query()->where('currency', $code)->exists()
            || ExchangeRate::query()->where(function ($query) use ($code) {
                $query->where('base_currency', $code)->orWhere('quote_currency', $code);
            })->exists();
    }
}
