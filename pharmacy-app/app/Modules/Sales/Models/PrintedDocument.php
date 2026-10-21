<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One printing of a counter document. Copy 1 is the original; later copies
 * are reprints and say so on the paper. Printing never changes the sale or
 * order it prints.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $document receipt|order_slip|collection_slip
 * @property string $reference_type sale|counter_order
 * @property string $reference_id
 * @property int $copy
 * @property bool $automatic
 * @property string $printed_by
 * @property CarbonImmutable $printed_at
 * @property-read User $printer
 */
class PrintedDocument extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const RECEIPT = 'receipt';

    public const ORDER_SLIP = 'order_slip';

    public const COLLECTION_SLIP = 'collection_slip';

    public const DOCUMENTS = [self::RECEIPT, self::ORDER_SLIP, self::COLLECTION_SLIP];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'copy' => 'integer',
            'automatic' => 'boolean',
            'printed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function printer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
