<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A kind of fixed asset: its accounts and how it is depreciated.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $asset_account_id
 * @property string $accumulated_account_id
 * @property string $expense_account_id
 * @property string $method
 * @property int $life_months
 * @property int|null $rate_bp
 * @property bool $active
 * @property-read Account $assetAccount
 * @property-read Account $accumulatedAccount
 * @property-read Account $expenseAccount
 */
class AssetCategory extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const STRAIGHT_LINE = 'straight_line';

    public const DECLINING_BALANCE = 'declining_balance';

    public const METHODS = [self::STRAIGHT_LINE, self::DECLINING_BALANCE];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'life_months' => 'integer',
            'rate_bp' => 'integer',
            'active' => 'boolean',
        ];
    }

    public static function methodLabel(string $method): string
    {
        return $method === self::DECLINING_BALANCE ? 'Declining balance' : 'Straight line';
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function accumulatedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }
}
