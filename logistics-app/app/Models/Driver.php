<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $licence_number
 * @property CarbonImmutable|null $licence_expires_on
 * @property bool $active
 */
class Driver extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['name', 'phone', 'licence_number', 'licence_expires_on', 'active'];

    protected function casts(): array
    {
        return ['licence_expires_on' => 'date', 'active' => 'boolean'];
    }

    /**
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function licenceExpired(): bool
    {
        return $this->licence_expires_on !== null && $this->licence_expires_on->lt(today());
    }
}
