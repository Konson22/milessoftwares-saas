<?php

namespace App\Models;

use App\Access\ProjectBound;
use App\Access\VisibleThroughProject;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $purchase_request_id
 * @property string $description
 * @property string $quantity with two decimals, compared with bcmath
 * @property string|null $unit
 * @property int $unit_price_minor an estimate
 * @property int $amount_minor
 */
class PurchaseRequestLine extends Model implements ProjectBound
{
    use BelongsToOrganization;
    use HasUuids;
    use VisibleThroughProject;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'unit_price_minor' => 'integer', 'amount_minor' => 'integer'];
    }

    /**
     * Seen only with the project its PurchaseRequest belongs to (App\Access\VisibleThroughProject).
     *
     * @return array{0: string, 1: class-string<Model>}
     */
    public function visibleThrough(): array
    {
        return ['purchase_request_id', PurchaseRequest::class];
    }
}
