<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An incident review (ADR-0029): links to the original records, with
 * notes and findings, and a small lifecycle. Never decides cause or blame.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $reference
 * @property string $title
 * @property string|null $description
 * @property string $logbook_id
 * @property string|null $resource_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $period_from
 * @property CarbonImmutable|null $period_to
 * @property string $investigator_id
 * @property string $status
 * @property string|null $closure_decision
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by
 * @property CarbonImmutable|null $reopened_at
 * @property string $created_by
 * @property int $revision
 * @property CarbonImmutable $created_at
 * @property-read Logbook $logbook
 * @property-read LogResource|null $resource
 * @property-read User $investigator
 * @property-read User $creator
 * @property-read User|null $closer
 */
class Investigation extends Model
{
    use TenantModel;

    public const STATUSES = ['open', 'investigating', 'findings_recorded', 'closed'];

    /** Statuses in which evidence and notes can still change. */
    public const OPEN = ['open', 'investigating', 'findings_recorded'];

    protected $table = 'log_investigations';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'revision' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** @return BelongsTo<Logbook, $this> */
    public function logbook(): BelongsTo
    {
        return $this->belongsTo(Logbook::class);
    }

    /** @return BelongsTo<LogResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(LogResource::class, 'resource_id');
    }

    /** @return BelongsTo<User, $this> */
    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigator_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return HasMany<InvestigationEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(InvestigationEvidence::class, 'investigation_id')->orderBy('added_at');
    }

    /** @return HasMany<InvestigationNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(InvestigationNote::class, 'investigation_id')->orderBy('created_at');
    }
}
