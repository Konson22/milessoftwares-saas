<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Someone the organization buys from. Created here, or from a product's
 * supplier bill (then keyed by the product's own supplier id).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $source_app
 * @property string|null $source_supplier_id
 * @property string|null $email
 * @property string|null $phone
 */
class Supplier extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    /**
     * @return HasMany<Bill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    /**
     * @return HasMany<SupplierPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }
}
