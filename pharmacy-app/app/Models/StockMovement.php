<?php

namespace App\Models;

use App\Enums\MovementType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One change to stock (append-only ledger). quantity is signed.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $medicine_id
 * @property string $batch_id
 * @property int $quantity
 * @property MovementType $type
 * @property string|null $reason
 * @property string|null $note
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string $user_id
 * @property CarbonImmutable $created_at
 * @property-read Batch $batch
 * @property-read User $user
 */
class StockMovement extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['organization_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements are append-only.'));
        static::deleting(fn () => throw new LogicException('Stock movements are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
