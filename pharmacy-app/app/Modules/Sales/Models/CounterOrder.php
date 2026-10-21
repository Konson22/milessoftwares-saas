<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Sales\Enums\CounterWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * An order taken at a branch counter that works in steps (CounterWorkflow):
 * it is paid (which makes its Sale) and handed over (dispensed or
 * collected) in the order its workflow says, then completed.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $application
 * @property string $branch_id
 * @property string $number
 * @property CounterWorkflow $workflow
 * @property string $status open|paid|handed_over|completed|cancelled
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string|null $customer_id
 * @property string $currency
 * @property int $total_minor
 * @property string|null $submission_id
 * @property string $ordered_by
 * @property CarbonImmutable $ordered_at
 * @property string|null $sale_id
 * @property CarbonImmutable|null $paid_at
 * @property string|null $handed_over_by
 * @property CarbonImmutable|null $handed_over_at
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property-read Collection<int, CounterOrderLine> $lines
 * @property-read User $orderer
 * @property-read User|null $handler
 * @property-read User|null $canceller
 * @property-read Sale|null $sale
 * @property-read Customer|null $customer
 */
class CounterOrder extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const HANDED_OVER = 'handed_over';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'workflow' => CounterWorkflow::class,
            'total_minor' => 'integer',
            'ordered_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'handed_over_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<CounterOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CounterOrderLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function orderer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_over_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function isHandedOver(): bool
    {
        return $this->handed_over_at !== null;
    }

    /**
     * Whether it can be paid now: open, not paid, and handed over first
     * when the workflow says so.
     */
    public function awaitsPayment(): bool
    {
        return in_array($this->status, [self::OPEN, self::HANDED_OVER], true)
            && ! $this->isPaid()
            && ($this->workflow->paysFirst() || $this->isHandedOver());
    }

    /**
     * Whether it can be dispensed or collected now.
     */
    public function awaitsHandOver(): bool
    {
        return in_array($this->status, [self::OPEN, self::PAID], true)
            && ! $this->isHandedOver()
            && (! $this->workflow->paysFirst() || $this->isPaid());
    }
}
