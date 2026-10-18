<?php

namespace App\Modules\Billing\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money paid against an invoice outside the platform. A customer's report
 * counts towards the invoice only once staff confirm it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $invoice_id
 * @property string $status reported|confirmed|rejected
 * @property string $method bank_transfer|mobile_money|cash|card|other
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $reference
 * @property Carbon $paid_on
 * @property string|null $note
 * @property string|null $reported_by_user_id
 * @property string|null $decided_by_user_id
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property-read Invoice $invoice
 * @property-read User|null $reporter
 */
class Payment extends Model
{
    use BelongsToOrganization, HasUuids;

    public const METHODS = ['bank_transfer', 'mobile_money', 'cash', 'card', 'other'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'paid_on' => 'date',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }
}
