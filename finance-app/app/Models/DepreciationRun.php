<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A month's depreciation, posted as one journal at the month end.
 *
 * @property string $id
 * @property string $organization_id
 * @property CarbonImmutable $month
 * @property string|null $journal_id
 * @property string|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read Collection<int, DepreciationLine> $lines
 * @property-read Journal|null $journal
 * @property-read User|null $creator
 */
class DepreciationRun extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<DepreciationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DepreciationLine::class);
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
