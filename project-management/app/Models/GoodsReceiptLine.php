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
 * @property string $goods_receipt_id
 * @property string $purchase_request_line_id
 * @property string $quantity
 */
class GoodsReceiptLine extends Model implements ProjectBound
{
    use BelongsToOrganization;
    use HasUuids;
    use VisibleThroughProject;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2', 'rejected_quantity' => 'decimal:2'];
    }

    /**
     * Seen only with the project its GoodsReceipt belongs to (App\Access\VisibleThroughProject).
     *
     * @return array{0: string, 1: class-string<Model>}
     */
    public function visibleThrough(): array
    {
        return ['goods_receipt_id', GoodsReceipt::class];
    }
}
