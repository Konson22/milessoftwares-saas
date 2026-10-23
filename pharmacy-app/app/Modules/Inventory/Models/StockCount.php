<?php

namespace App\Modules\Inventory\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A stock-take: the branch's batches of one industry (or of one category)
 * counted on a sheet; completed, the differences become count corrections.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $application the industry module counting (pharmacy, retail)
 * @property string $number
 * @property string|null $category
 * @property string|null $note
 * @property string $status
 * @property string $started_by
 * @property CarbonImmutable $started_at
 * @property string|null $closed_by
 * @property CarbonImmutable|null $closed_at
 * @property-read Collection<int, StockCountLine> $lines
 * @property-read User $starter
 * @property-read User|null $closer
 */
class StockCount extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const OPEN = 'open';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /**
     * @return HasMany<StockCountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
