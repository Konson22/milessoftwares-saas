<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One handover of physical cash. Pending money stays with the sender.
 * Confirming it does not create a sale.
 *
 * @property string $id
 * @property string $branch_id
 * @property string $number
 * @property string $kind
 * @property string $source_type
 * @property string $source_id
 * @property string $destination_type
 * @property string|null $destination_id
 * @property string|null $shift_id
 * @property string|null $currency
 * @property int $declared_minor
 * @property int|null $counted_minor
 * @property int|null $fx_rate_minor
 * @property int|null $reporting_minor
 * @property string $status
 * @property string $initiated_by
 * @property string|null $confirmed_by
 */
class CashTransfer extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const REMITTANCE = 'remittance';

    public const TO_SAFE = 'to_safe';

    public const BETWEEN_SAFES = 'between_safes';

    public const CASHIER = 'cashier_transfer';

    public const PAYOUT = 'payout';

    public const ADJUSTMENT = 'adjustment';

    public const ATTENDANT = 'attendant';

    public const CASHIER_HOLDER = 'cashier';

    public const SAFE = 'safe';

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const DISPUTED = 'disputed';

    public const REJECTED = 'rejected';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'declared_minor' => 'integer',
            'counted_minor' => 'integer',
            'fx_rate_minor' => 'integer',
            'reporting_minor' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }
}
