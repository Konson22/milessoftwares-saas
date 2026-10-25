<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One change to a drink's stock at a branch, signed: positive stock gained.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $menu_item_id
 * @property string $type opening, sale, bill_void, count, write_off
 * @property int $quantity
 * @property int $unit_cost_minor
 * @property string|null $reason
 * @property string|null $note
 * @property string|null $bill_id
 * @property string $created_by
 * @property CarbonImmutable $created_at
 * @property-read User $creator
 */
class StockMovement extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    public const OPENING = 'opening';

    public const SALE = 'sale';

    public const BILL_VOID = 'bill_void';

    public const COUNT = 'count';

    public const WRITE_OFF = 'write_off';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_cost_minor' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
