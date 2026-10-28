<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A manual journal waiting for a second person's approval
 * (App\Finance\JournalApprovals). Not in the ledger until approved.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property CarbonImmutable $entry_date
 * @property string $memo
 * @property list<array{account_id: string, debit_minor: int, credit_minor: int, description: string|null, cost_centre_id: string|null, project_id: string|null}> $lines
 * @property int $total_minor
 * @property string $status
 * @property string $submitted_by
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $review_note
 * @property string|null $journal_id the journal posted when approved
 * @property CarbonImmutable $created_at
 * @property-read User $submitter
 * @property-read User|null $reviewer
 * @property-read Journal|null $journal
 */
class JournalDraft extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Taken back by the person who submitted it. */
    public const WITHDRAWN = 'withdrawn';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'entry_date' => 'immutable_date',
            'reviewed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'lines' => 'array',
            'total_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<Journal, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
