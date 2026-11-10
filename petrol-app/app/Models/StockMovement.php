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
 * @property string $tank_id
 * @property string $kind
 * @property int $quantity_milli
 * @property int $unit_cost_minor
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string|null $idempotency_key
 * @property string|null $notes
 * @property string $created_by
 * @property CarbonImmutable $occurred_at
 */
class StockMovement extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const OPENING = 'opening';

    public const RECEIPT = 'receipt';

    public const SALE = 'sale';

    public const VOID = 'void';

    public const COUNT = 'count';

    public const ADJUST = 'adjust';

    public const TRANSFER_OUT = 'transfer_out';

    public const TRANSFER_IN = 'transfer_in';

    public const TEST = 'test';

    public const METER = 'meter';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'unit_cost_minor' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
