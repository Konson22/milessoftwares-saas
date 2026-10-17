<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A cashier's till at one branch, from opening to the count at closing.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $number
 * @property string $status
 * @property string $opened_by
 * @property CarbonImmutable $opened_at
 * @property int $opening_float_minor
 * @property string|null $closed_by
 * @property CarbonImmutable|null $closed_at
 * @property int|null $expected_cash_minor
 * @property int|null $counted_cash_minor
 * @property string|null $closing_note
 * @property-read User $opener
 * @property-read User|null $closer
 */
class TillSession extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'opening_float_minor' => 'integer',
            'expected_cash_minor' => 'integer',
            'counted_cash_minor' => 'integer',
        ];
    }

    /**
     * Counted minus expected: positive is over, negative is short.
     */
    public function difference(): ?int
    {
        return $this->counted_cash_minor === null || $this->expected_cash_minor === null
            ? null
            : $this->counted_cash_minor - $this->expected_cash_minor;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
