<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Spending Projects handed to Finance, one revision of it
 * (App\Finance\ProjectSpending). Finance takes it in by preparing a payment
 * request, or refuses it; the payment request then goes through Finance's
 * own review, approval and disbursement.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $request_id
 * @property string $correlation_id
 * @property string $idempotency_key
 * @property int $revision
 * @property string $project_id
 * @property string $project_code
 * @property string $project_name
 * @property string $expense_id
 * @property string $category
 * @property string $description
 * @property string|null $supplier
 * @property string|null $grant_reference
 * @property string $currency
 * @property int $amount_minor
 * @property CarbonImmutable $requested_at
 * @property string $status
 * @property string|null $payment_request_id
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property CarbonImmutable|null $cancellation_requested_at
 * @property string|null $cancellation_reason
 * @property int $sequence
 * @property CarbonImmutable $created_at
 * @property-read PaymentRequest|null $paymentRequest
 */
class ProjectSpendingRequest extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'amount_minor' => 'integer',
            'sequence' => 'integer',
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'cancellation_requested_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PaymentRequest, $this>
     */
    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class);
    }
}
