<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Signed cash in the station drawer or the petty box. Drawer kinds are
 * summed for the shift; petty kinds are not.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string|null $shift_id
 * @property string $kind
 * @property int $amount_minor
 * @property string|null $fuel_sale_id
 * @property string|null $expense_id
 * @property string|null $supplier_payment_id
 * @property string|null $credit_entry_id
 * @property string|null $idempotency_key
 * @property string|null $note
 * @property string $recorded_by
 * @property CarbonImmutable $occurred_at
 */
class CashMovement extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const FLOAT = 'float';

    public const SALE = 'sale';

    public const SALE_VOID = 'sale_void';

    public const EXPENSE = 'expense';

    public const CUSTOMER_PAYMENT = 'customer_payment';

    public const SUPPLIER_PAYMENT = 'supplier_payment';

    public const PETTY_TRANSFER = 'petty_transfer';

    public const PETTY_IN = 'petty_in';

    public const PETTY_EXPENSE = 'petty_expense';

    public const REMITTANCE = 'remittance';

    public const TO_SAFE = 'to_safe';

    public const PAYOUT = 'payout';

    public const ADJUSTMENT = 'adjustment';

    /** Kinds that sit in the cashier's drawer. */
    public const DRAWER = [
        self::FLOAT,
        self::SALE,
        self::SALE_VOID,
        self::EXPENSE,
        self::CUSTOMER_PAYMENT,
        self::SUPPLIER_PAYMENT,
        self::PETTY_TRANSFER,
        self::REMITTANCE,
        self::TO_SAFE,
        self::PAYOUT,
        self::ADJUSTMENT,
    ];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
