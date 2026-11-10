<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a supplier offered, as a total in the project's currency, with its
 * document on the private disk.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $purchase_request_id
 * @property string $supplier_id
 * @property int $amount_minor
 * @property string|null $reference
 * @property CarbonImmutable|null $valid_until
 * @property string|null $original_name
 * @property string|null $path
 * @property string $created_by
 * @property-read Supplier $supplier
 */
class PurchaseQuote extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'valid_until' => 'date'];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
