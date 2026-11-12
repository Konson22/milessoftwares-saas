<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * An allowance (earning) or deduction the organization uses: a fixed
 * amount or a percentage of basic pay, set per person.
 *
 * @property string $id
 * @property string $name
 * @property string $kind
 * @property string $calculation
 * @property bool $taxable
 * @property bool $active
 */
class PayComponent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const EARNING = 'earning';

    public const DEDUCTION = 'deduction';

    public const FIXED = 'fixed';

    public const PERCENT = 'percent';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'taxable' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
