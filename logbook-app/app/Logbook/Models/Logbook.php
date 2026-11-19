<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A collection of related log sheets (IT operations, vehicles, security).
 * Archived, never deleted, once it holds records.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $description
 * @property string|null $icon
 * @property string $status
 * @property bool $restricted
 * @property string $created_by
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $creator
 */
class Logbook extends Model
{
    use TenantModel;

    public const ICONS = ['book', 'server', 'building', 'wrench', 'truck', 'zap', 'shield', 'clipboard', 'map-pin', 'users'];

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'active', 'restricted' => false];

    protected function casts(): array
    {
        return ['restricted' => 'boolean', 'archived_at' => 'immutable_datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return HasMany<LogSheet, $this> */
    public function sheets(): HasMany
    {
        return $this->hasMany(LogSheet::class);
    }

    /** @return HasMany<AccessGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
