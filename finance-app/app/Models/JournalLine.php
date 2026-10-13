<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One side of a journal: a debit or a credit to one account.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $journal_id
 * @property string $account_id
 * @property int $position
 * @property int $debit_minor
 * @property int $credit_minor
 * @property string|null $description
 * @property-read Journal $journal
 * @property-read Account $account
 */
class JournalLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
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
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
