<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A revaluation of foreign-currency balances at a day's rates: its journal
 * on the day (none when nothing moved) and the reversal the next day. See
 * App\Finance\Revaluations.
 *
 * @property string $id
 * @property string $organization_id
 * @property CarbonImmutable $as_of
 * @property string|null $journal_id
 * @property string|null $reversal_journal_id
 * @property string $created_by
 * @property CarbonImmutable $created_at
 * @property-read Journal|null $journal
 * @property-read User $creator
 */
class Revaluation extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'as_of' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
