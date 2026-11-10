<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Cash counted in a petty cash box by someone other than its custodian,
 * against what the books said it held. A difference is posted to Cash over
 * and short, with the counter's explanation.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $fund_id
 * @property CarbonImmutable $counted_on
 * @property int $counted_minor
 * @property int $book_minor
 * @property int $difference_minor counted less book: negative is a shortage
 * @property string|null $note
 * @property string|null $journal_id
 * @property string $counted_by
 * @property CarbonImmutable $created_at
 * @property-read User $counter
 */
class PettyCashCount extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'counted_on' => 'immutable_date',
            'counted_minor' => 'integer',
            'book_minor' => 'integer',
            'difference_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
