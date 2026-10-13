<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\BankKind;
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
 * @property string|null $system_key
 * @property string|null $description
 * @property bool $active
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

    public const SALES = 'sales';

    public const PURCHASES = 'purchases';

    protected $guarded = ['organization_id', 'system_key'];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'bank_kind' => BankKind::class,
            'active' => 'boolean',
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
