<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One line of a bank statement: money in positive, money out negative,
 * matched to the ledger line it clears.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $bank_statement_id
 * @property string $account_id
 * @property int $position
 * @property CarbonImmutable $line_date
 * @property string $description
 * @property string|null $reference
 * @property int $amount_minor
 * @property string|null $journal_line_id
 * @property CarbonImmutable|null $matched_at
 * @property string|null $matched_by
 * @property-read BankStatement $statement
 * @property-read JournalLine|null $journalLine
 */
class BankStatementLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'line_date' => 'immutable_date',
            'amount_minor' => 'integer',
            'matched_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<BankStatement, $this>
     */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    /**
     * @return BelongsTo<JournalLine, $this>
     */
    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class);
    }
}
