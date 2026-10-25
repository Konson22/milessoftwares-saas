<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A cashier's shift at a branch: the cash drawer opened with a float, the
 * bills paid into it, and the count it was closed with (App\Actions\Tills).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $number
 * @property string $status
 * @property int $opening_float_minor
 * @property string $opened_by
 * @property CarbonImmutable $opened_at
 * @property string|null $closed_by
 * @property CarbonImmutable|null $closed_at
 * @property int|null $expected_cash_minor
 * @property int|null $counted_cash_minor
 * @property string|null $closing_note
 * @property-read User $opener
 * @property-read User|null $closer
 */
class TillShift extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'opening_float_minor' => 'integer',
            'expected_cash_minor' => 'integer',
            'counted_cash_minor' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
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
}
