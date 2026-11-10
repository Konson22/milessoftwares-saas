<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Cash leaving a safe for a bank. Prepared and submitted money is not
 * treated as received by the bank until it is confirmed.
 *
 * @property string $id
 * @property string $branch_id
 * @property string $safe_id
 * @property string $number
 * @property string $bank_reference
 * @property string|null $currency
 * @property string|null $bank_name
 * @property int $amount_minor
 * @property int|null $confirmed_amount_minor
 * @property int|null $reporting_minor
 * @property int|null $fx_rate_minor
 * @property string $status
 * @property string $prepared_by
 */
class BankDeposit extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const DRAFT = 'draft';

    public const PREPARED = 'prepared';

    public const SUBMITTED = 'submitted';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'confirmed_amount_minor' => 'integer',
            'reporting_minor' => 'integer',
            'fx_rate_minor' => 'integer',
            'deposit_on' => 'date',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }
}
