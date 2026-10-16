<?php

namespace App\Modules\Products\Models;

use App\Modules\Applications\Models\Application;
use App\Modules\Products\Enums\ProductStatus;
use App\Modules\Subscriptions\Models\Plan;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A commercial ERP product: a named set of registry applications. Platform
 * owned, written only by ProductCatalog from database/erp-products.json.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $tagline
 * @property string|null $description
 * @property ProductStatus $status
 * @property int $sort_order
 */
class ErpProduct extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
        ];
    }

    /**
     * @return BelongsToMany<Application, $this>
     */
    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(Application::class, 'erp_product_applications')->withPivot('requirement');
    }

    /**
     * @return BelongsToMany<Application, $this>
     */
    public function requiredApplications(): BelongsToMany
    {
        return $this->applications()->wherePivot('requirement', 'required');
    }

    /**
     * @return HasMany<Plan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    /**
     * Application id => required|optional.
     *
     * @return array<string, string>
     */
    public function requirements(): array
    {
        $requirements = [];

        foreach ($this->applications as $application) {
            /** @var object{requirement: string} $pivot */
            $pivot = $application->getRelation('pivot');
            $requirements[(string) $application->id] = $pivot->requirement;
        }

        return $requirements;
    }
}
