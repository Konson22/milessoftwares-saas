<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\BankKind;
use App\Enums\CashFlowActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * An account in the organization's chart of accounts.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property AccountType $type
 * @property BankKind|null $bank_kind
 * @property string|null $bank_name
 * @property string|null $bank_account_number
 * @property array<string, mixed>|null $bank_import how the bank's CSV export is laid out
 * @property string|null $system_key
 * @property CashFlowActivity|null $cash_flow
 * @property string|null $description
 * @property bool $active
 * @property bool $requires_cost_centre lines entered by hand must name a cost centre
 * @property bool $requires_project lines entered by hand must name a project
 * @property string|null $currency a bank or cash account held in another currency (null: the books')
 */
class Account extends Model
{
    use BelongsToTenant;
    use HasUuids;

    // Accounts the posting rules rely on, by system key.
    public const CASH = 'cash';

    public const BANK = 'bank';

    public const MOBILE_MONEY = 'mobile_money';

    public const CARD = 'card';

    public const RECEIVABLE = 'receivable';

    public const INVENTORY = 'inventory';

    public const PAYABLE = 'payable';

    public const TAX = 'tax';

    public const EQUITY = 'equity';

    public const RETAINED_EARNINGS = 'retained_earnings';

    /** Takes the difference when opening balances are entered. */
    public const OPENING_BALANCE = 'opening_balance';

    /** Takes realised and unrealised exchange gains and losses (a gain is a credit). */
    public const FX = 'fx';

    /** Takes the gain or loss when a fixed asset is disposed of (a gain is a credit). */
    public const DISPOSAL = 'asset_disposal';

    public const SALES = 'sales';

    public const PURCHASES = 'purchases';

    public const STOCK_LOSSES = 'stock_losses';

    public const CASH_OVER_SHORT = 'cash_over_short';

    /** Takes cash paid out of a till for an expense when no account is named. */
    public const OTHER_EXPENSES = 'other_expenses';

    protected $guarded = ['organization_id', 'system_key'];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'bank_kind' => BankKind::class,
            'cash_flow' => CashFlowActivity::class,
            'bank_import' => 'array',
            'active' => 'boolean',
            'requires_cost_centre' => 'boolean',
            'requires_project' => 'boolean',
        ];
    }

    /**
     * The account a posting rule needs (created with the chart).
     */
    public static function system(string $key): self
    {
        return static::query()->where('system_key', $key)->firstOrFail();
    }

    public function label(): string
    {
        return "{$this->code} {$this->name}";
    }

    public function isBank(): bool
    {
        return $this->bank_kind !== null;
    }

    /**
     * Where the account's movements show in the cash flow statement: null
     * for banks and cash (the cash itself) and for revenue and expenses (in
     * the profit). Unless the accountant chose otherwise: receivable,
     * inventory, payable and tax are operating, other assets investing,
     * other liabilities and equity financing.
     */
    public function cashFlowActivity(): ?CashFlowActivity
    {
        if ($this->isBank() || in_array($this->type, [AccountType::Revenue, AccountType::Expense], true)) {
            return null;
        }
        if ($this->cash_flow !== null) {
            return $this->cash_flow;
        }

        return match (true) {
            in_array($this->system_key, [self::RECEIVABLE, self::INVENTORY, self::PAYABLE, self::TAX], true) => CashFlowActivity::Operating,
            $this->type === AccountType::Asset => CashFlowActivity::Investing,
            default => CashFlowActivity::Financing,
        };
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeBanks(Builder $query): void
    {
        $query->whereNotNull('bank_kind');
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
