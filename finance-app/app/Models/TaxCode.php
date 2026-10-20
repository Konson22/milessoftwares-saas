<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A tax rate charged on sales and/or paid on purchases.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property int $rate_bp basis points: 1800 is 18%
 * @property bool $sales
 * @property bool $purchases
 * @property bool $recoverable purchase tax claimed back; otherwise part of the cost
 * @property bool $active
 */
class TaxCode extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'rate_bp' => 'integer',
            'sales' => 'boolean',
            'purchases' => 'boolean',
            'recoverable' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * The tax on a net amount, rounded half up to the minor unit.
     */
    public function taxOn(int $netMinor): int
    {
        $tax = intdiv(abs($netMinor) * $this->rate_bp + 5000, 10000);

        return $netMinor < 0 ? -$tax : $tax;
    }

    /**
     * "VAT18 (18%)".
     */
    public function label(): string
    {
        return "{$this->code} (".rtrim(rtrim(number_format($this->rate_bp / 100, 2, '.', ''), '0'), '.').'%)';
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeFor(Builder $query, string $side): void
    {
        $query->where('active', true)->where($side === 'sales' ? 'sales' : 'purchases', true);
    }
}
