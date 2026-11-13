<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Net pay that went out for some of a run's payslips at once: one bank
 * batch, one cash day. Finance gets one finance.payroll.paid per batch.
 *
 * @property string $id
 * @property string $payroll_run_id
 * @property string $number
 * @property string $method
 * @property CarbonImmutable $paid_on
 * @property string|null $reference
 * @property int $amount_minor
 * @property int $headcount
 * @property string $recorded_by
 * @property-read PayrollRun $run
 * @property-read User $recorder
 */
class PayrollPayment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'paid_on' => 'immutable_date',
            'amount_minor' => 'integer',
            'headcount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return HasMany<PayslipPayment, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayslipPayment::class);
    }
}
