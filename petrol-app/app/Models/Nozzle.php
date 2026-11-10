<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $pump_id
 * @property string $fuel_product_id
 * @property string $name
 * @property string $code
 * @property string $status
 * @property-read Pump $pump
 * @property-read FuelProduct $product
 * @property-read NozzleConnection|null $currentConnection
 */
class Nozzle extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const ACTIVE = 'active';

    public const OUT = 'out_of_service';

    protected $guarded = ['organization_id'];

    /**
     * @return BelongsTo<Pump, $this>
     */
    public function pump(): BelongsTo
    {
        return $this->belongsTo(Pump::class);
    }

    /**
     * @return BelongsTo<FuelProduct, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class, 'fuel_product_id');
    }

    /**
     * @return HasOne<NozzleConnection, $this>
     */
    public function currentConnection(): HasOne
    {
        return $this->hasOne(NozzleConnection::class)->whereNull('disconnected_at');
    }
}
