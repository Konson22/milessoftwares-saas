<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A percentage off one item, or every item of a category, from one day
 * until another (or until ended), in every branch or one. The till gives
 * it by itself (App\Modules\Sales\Actions\Promotions).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $application
 * @property string $name
 * @property int $percent_off
 * @property string|null $item_id
 * @property string|null $category
 * @property string|null $branch_id null: every branch
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable|null $ends_on null: until ended
 * @property bool $active
 * @property string $created_by
 * @property CarbonImmutable $created_at
 * @property-read Item|null $item
 * @property-read User $creator
 */
class Promotion extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return [
            'percent_off' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Whether it is given on this day.
     */
    public function runsOn(CarbonImmutable $day): bool
    {
        return $this->active
            && $this->starts_on->lessThanOrEqualTo($day)
            && ($this->ends_on === null || $this->ends_on->greaterThanOrEqualTo($day));
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
