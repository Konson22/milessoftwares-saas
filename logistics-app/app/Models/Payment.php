<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Money received against an invoice.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $invoice_id
 * @property string $number
 * @property int $amount_minor
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property CarbonImmutable $paid_on
 * @property string $received_by
 * @property-read User $receiver
 */
class Payment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'paid_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
