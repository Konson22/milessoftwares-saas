<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string|null $shift_id
 * @property string $category
 * @property string $description
 * @property int $amount_minor
 * @property string $paid_from
 * @property CarbonImmutable $incurred_on
 * @property string|null $idempotency_key
 * @property string $recorded_by
 */
class Expense extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const CASH = 'cash';

    public const BANK = 'bank';

    public const PETTY = 'petty';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'incurred_on' => 'date',
        ];
    }
}
