<?php

namespace App\Modules\Applications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One immutable version of an application's role and permission catalog.
 * Written by CatalogImporter only.
 *
 * @property string $id
 * @property string $application_id
 * @property string $version
 * @property string $status
 * @property Carbon|null $published_at
 * @property-read Application $application
 */
class ApplicationCatalogVersion extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return HasMany<ApplicationRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(ApplicationRole::class, 'catalog_version_id');
    }

    /**
     * @return HasMany<ApplicationPermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(ApplicationPermission::class, 'catalog_version_id');
    }

    /**
     * @return HasMany<SodConstraint, $this>
     */
    public function sodConstraints(): HasMany
    {
        return $this->hasMany(SodConstraint::class, 'catalog_version_id');
    }
}
