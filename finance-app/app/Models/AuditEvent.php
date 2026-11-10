<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One control event in the books (App\Finance\Audit). Never changed or
 * deleted once written: there is no route that does either, and the model
 * refuses.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property CarbonImmutable $created_at
 * @property-read User|null $actor
 */
class AuditEvent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['organization_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit events are never changed.'));
        static::deleting(fn () => throw new LogicException('Audit events are never deleted.'));
    }

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
