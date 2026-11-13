<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One payslip's net pay in a payment batch. A payslip is paid once.
 *
 * @property string $id
 * @property string $payroll_payment_id
 * @property string $payslip_id
 * @property int $amount_minor
 * @property-read PayrollPayment $payment
 */
class PayslipPayment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PayrollPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PayrollPayment::class, 'payroll_payment_id');
    }
}
