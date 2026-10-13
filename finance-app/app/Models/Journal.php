<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A posted journal: balanced lines, never edited. Created by App\Finance\Ledger.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property CarbonImmutable $entry_date
 * @property string $memo
 * @property string $source_app
 * @property string $source_type
 * @property string|null $source_reference
 * @property string|null $branch_id
 * @property string|null $reversal_of_id
 * @property string|null $reversed_by_id
 * @property string|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read Collection<int, JournalLine> $lines
 * @property-read User|null $creator
 * @property-read Journal|null $reversalOf
 * @property-read Journal|null $reversedBy
 */
class Journal extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const MANUAL = 'manual';

    public const REVERSAL = 'reversal';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'entry_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function total(): int
    {
        return (int) $this->lines->sum('debit_minor');
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_of_id');
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversed_by_id');
    }
}
