<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A plan to record an expense. The plan itself is not an expenditure.
 *
 * @property string $id
 * @property string $branch_id
 * @property string $category_id
 * @property string $frequency
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable $next_due_on
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $payee
 * @property string $description
 * @property bool $active
 */
class ExpenseSchedule extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const QUARTERLY = 'quarterly';

    public const YEARLY = 'yearly';

    public const FREQUENCIES = [self::DAILY, self::WEEKLY, self::MONTHLY, self::QUARTERLY, self::YEARLY];

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const ENDED = 'ended';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'next_due_on' => 'date',
            'amount_minor' => 'integer',
            'active' => 'boolean',
        ];
    }
}
