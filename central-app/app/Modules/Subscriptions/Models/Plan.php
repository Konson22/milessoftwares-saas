<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Applications\Models\Application;
use App\Modules\Products\Models\ErpProduct;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A sellable plan covering one application, or several for a bundle.
 * Platform-owned; edited on admin.<root> only.
 *
 * @property string $id
 * @property string|null $erp_product_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $billing_interval monthly|yearly|custom
 * @property int $price_minor
 * @property string $currency
 * @property int $trial_days
 * @property bool $is_bundle
 * @property bool $is_public
 * @property string $status draft|active|retired
 */
class Plan extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'trial_days' => 'integer',
            'is_bundle' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Application, $this>
     */
    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(Application::class, 'plan_applications');
    }

    /**
     * The ERP product the plan sells, if any.
     *
     * @return BelongsTo<ErpProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ErpProduct::class, 'erp_product_id');
    }
}
