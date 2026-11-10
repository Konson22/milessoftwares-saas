<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A count of the cash in a box, by someone other than its custodian. Any
 * difference from the books goes to cash over and short.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $fund_id
 * @property CarbonImmutable $counted_on
 * @property int $counted_minor
 * @property int $book_minor
 * @property int $difference_minor
 * @property string|null $note
 * @property string $counted_by
 * @property-read User $counter
 */
class PettyCashCount extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'counted_on' => 'immutable_date',
            'counted_minor' => 'integer',
            'book_minor' => 'integer',
            'difference_minor' => 'integer',
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
