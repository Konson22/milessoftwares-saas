<?php

namespace App\Http\Controllers;

use App\Finance\Currencies;
use App\Finance\Money;
use App\Models\Account;
use App\Models\DimensionValue;
use App\Models\ExchangeRate;
use App\Models\TaxCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Miles\PlatformClient\Tenancy\CurrentTenant;

abstract class Controller
{
    protected function organizationId(): string
    {
        return (string) app(CurrentTenant::class)->id();
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * An existing row of this organization (validation rule).
     */
    protected function ours(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('organization_id', $this->organizationId());
    }

    /**
     * An open account of this organization, optionally only banks or only non-banks.
     */
    protected function openAccount(?bool $bank = null): Exists
    {
        $rule = $this->ours('accounts')->where('active', true);

        return match ($bank) {
            true => $rule->whereNotNull('bank_kind'),
            false => $rule->whereNull('bank_kind'),
            null => $rule,
        };
    }

    /**
     * A typed amount in a currency's minor unit (null: the books').
     *
     * @throws ValidationException when it has more decimals than the currency
     */
    protected function amountIn(string $amount, ?string $currency, string $field = 'amount'): int
    {
        try {
            return Money::toMinor($amount, $currency);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => ($currency ?? Money::currency()).' amounts have '.Money::decimals($currency).' decimals.']);
        }
    }

    /**
     * Validation rules for a document's currency and rate.
     *
     * @return array<string, list<mixed>>
     */
    protected function currencyRules(): array
    {
        return [
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'rate' => ['nullable', 'string', Currencies::RATE_RULE],
        ];
    }

    /**
     * A document's currency and rate (null: the books' currency). Without a
     * typed rate, the day's rate from the table.
     *
     * @param  array<string, mixed>  $data
     * @return array{currency: string, rate: string}|null
     *
     * @throws ValidationException
     */
    protected function foreignTerms(array $data, CarbonImmutable $on): ?array
    {
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        if ($currency === '' || $currency === Money::currency()) {
            return null;
        }
        if (! Currencies::enabled()) {
            throw ValidationException::withMessages(['currency' => 'Your plan keeps the books in '.Money::currency().' only.']);
        }
        $currency = Currencies::check($currency);
        $rate = (string) ($data['rate'] ?? '');

        return ['currency' => $currency, 'rate' => $rate === '' ? app(Currencies::class)->rate($currency, $on) : Currencies::normalize($rate)];
    }

    /**
     * Currencies with a rate, latest rate first, for document forms (empty
     * without the plan feature).
     *
     * @return list<array{code: string, rate: string, date: string}>
     */
    protected function currencyOptions(): array
    {
        if (! Currencies::enabled()) {
            return [];
        }

        $options = [];
        foreach (ExchangeRate::query()->orderBy('currency')->orderByDesc('rate_on')->get() as $rate) {
            $options[$rate->currency] ??= ['code' => $rate->currency, 'rate' => Currencies::normalize((string) $rate->rate), 'date' => $rate->rate_on->toDateString()];
        }

        return array_values($options);
    }

    /**
     * An active tax code of this organization for sales or purchases.
     */
    protected function taxCodeRule(string $side): Exists
    {
        return $this->ours('tax_codes')->where('active', true)->where($side === 'sales' ? 'sales' : 'purchases', true);
    }

    /**
     * Active tax codes for a select: [{id, label, rate_bp}].
     *
     * @return list<array{id: string, label: string, rate_bp: int}>
     */
    protected function taxCodeOptions(string $side): array
    {
        return array_values(TaxCode::query()->for($side)->orderBy('code')->get()
            ->map(fn (TaxCode $code) => ['id' => $code->id, 'label' => $code->label(), 'rate_bp' => $code->rate_bp])
            ->all());
    }

    /**
     * The tax codes chosen on validated lines, by id.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return Collection<string, TaxCode>
     */
    protected function chosenTaxCodes(array $lines): Collection
    {
        return TaxCode::query()->whereIn('id', array_filter(array_column($lines, 'tax_code_id')))->get()->keyBy('id');
    }

    /**
     * An active cost centre or project of this organization.
     */
    protected function dimensionRule(string $kind): Exists
    {
        return $this->ours('dimension_values')->where('kind', $kind)->where('active', true);
    }

    /**
     * Validation for lines[*][cost_centre_id] and lines[*][project_id].
     *
     * @return array<string, list<mixed>>
     */
    protected function lineDimensionRules(): array
    {
        return [
            'lines.*.cost_centre_id' => ['nullable', 'uuid', $this->dimensionRule(DimensionValue::COST_CENTRE)],
            'lines.*.project_id' => ['nullable', 'uuid', $this->dimensionRule(DimensionValue::PROJECT)],
        ];
    }

    /**
     * The cost centre and project chosen on a validated line, for the ledger.
     *
     * @param  array<string, mixed>  $line
     * @param  Collection<string, DimensionValue>  $values
     * @return array{cost_centre: DimensionValue|null, project: DimensionValue|null}
     */
    protected function lineDimensions(array $line, Collection $values): array
    {
        return [
            'cost_centre' => isset($line['cost_centre_id']) ? $values->get($line['cost_centre_id']) : null,
            'project' => isset($line['project_id']) ? $values->get($line['project_id']) : null,
        ];
    }

    /**
     * Validated journal lines (debit or credit typed in the books' currency)
     * as kept for later posting (App\Finance\StoredLines).
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return list<array{account_id: string, debit_minor: int, credit_minor: int, description: string|null, cost_centre_id: string|null, project_id: string|null}>
     */
    protected function storedLines(array $lines): array
    {
        return array_values(array_map(fn (array $line) => [
            'account_id' => (string) $line['account_id'],
            'debit_minor' => ($line['debit'] ?? '') === '' ? 0 : Money::toMinor((string) $line['debit']),
            'credit_minor' => ($line['credit'] ?? '') === '' ? 0 : Money::toMinor((string) $line['credit']),
            'description' => isset($line['description']) ? (string) $line['description'] : null,
            'cost_centre_id' => isset($line['cost_centre_id']) ? (string) $line['cost_centre_id'] : null,
            'project_id' => isset($line['project_id']) ? (string) $line['project_id'] : null,
        ], $lines));
    }

    /**
     * The cost centres and projects chosen on validated lines, by id.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return Collection<string, DimensionValue>
     */
    protected function chosenDimensions(array $lines): Collection
    {
        $ids = array_filter([...array_column($lines, 'cost_centre_id'), ...array_column($lines, 'project_id')]);

        return DimensionValue::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * Active cost centres and projects for selects.
     *
     * @return array{costCentres: list<array{id: string, label: string}>, projects: list<array{id: string, label: string}>}
     */
    protected function dimensionOptions(): array
    {
        $values = DimensionValue::query()->where('active', true)->orderBy('code')->get();
        $options = fn (string $kind) => array_values($values->where('kind', $kind)
            ->map(fn (DimensionValue $value) => ['id' => $value->id, 'label' => $value->label()])
            ->all());

        return ['costCentres' => $options(DimensionValue::COST_CENTRE), 'projects' => $options(DimensionValue::PROJECT)];
    }

    /**
     * Open accounts for a select: [{id, label, type, requires}], requires naming
     * the dimensions lines on the account must have.
     *
     * @return list<array{id: string, label: string, type: string, requires: list<string>}>
     */
    protected function accountOptions(?bool $bank = null): array
    {
        return array_values(Account::query()
            ->where('active', true)
            ->when($bank === true, fn ($query) => $query->whereNotNull('bank_kind'))
            ->when($bank === false, fn ($query) => $query->whereNull('bank_kind'))
            ->orderBy('code')
            ->get()
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'label' => $account->label(),
                'type' => $account->type->value,
                'currency' => $account->currency,
                'requires' => array_keys(array_filter([DimensionValue::COST_CENTRE => $account->requires_cost_centre, DimensionValue::PROJECT => $account->requires_project])),
            ])
            ->all());
    }

    /**
     * A from/to period from the query string (validated), with defaults.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function period(Request $request, CarbonImmutable $defaultFrom): array
    {
        $period = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [
            isset($period['from']) ? CarbonImmutable::parse($period['from']) : $defaultFrom,
            isset($period['to']) ? CarbonImmutable::parse($period['to']) : CarbonImmutable::today(),
        ];
    }
}
