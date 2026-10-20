<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A budgeted amount for an account in one month (period number) of the
 * budget's year, in the account's normal direction.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $budget_id
 * @property string $account_id
 * @property int $period
 * @property int $amount_minor
 * @property-read Account $account
 */
class BudgetLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'period' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
