<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment Finance reports for a request, keyed by Finance's own id: one
 * row per payment, however often Finance's event arrives.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $finance_request_id
 * @property string $finance_payment_id
 * @property string $currency
 * @property int $amount_minor
 * @property int $reversed_minor
 * @property CarbonImmutable $paid_on
 * @property string $status completed or reversed
 * @property string|null $journal_reference
 */
class FinancePayment extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const COMPLETED = 'completed';

    public const REVERSED = 'reversed';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'reversed_minor' => 'integer', 'paid_on' => 'immutable_date'];
    }
}
