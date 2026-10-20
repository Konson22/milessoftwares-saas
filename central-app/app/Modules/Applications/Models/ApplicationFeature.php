<?php

namespace App\Modules\Applications\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan feature an application declares in its catalog (finance.multicurrency).
 * Plans and staff grants may only name features of an application's current
 * catalog version.
 *
 * @property string $id
 * @property string $application_id
 * @property string $catalog_version_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $status available|beta|deprecated
 * @property string|null $limit_unit
 */
class ApplicationFeature extends Model
{
    use HasUuids;

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Features of each application's current catalog version.
     *
     * @param  Builder<self>  $query
     */
    public function scopeRegistered(Builder $query): void
    {
        $query->whereIn('catalog_version_id', Application::query()->whereNotNull('current_catalog_version_id')->select('current_catalog_version_id'));
    }
}
