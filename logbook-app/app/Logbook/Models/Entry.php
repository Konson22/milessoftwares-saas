<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A log entry. With review: draft, submitted, reviewed; or submitted,
 * returned, corrected and submitted again. Without: draft, finalized.
 * Each submission is kept as a revision.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $logbook_id
 * @property string $log_sheet_id
 * @property string $log_sheet_version_id
 * @property string|null $reference
 * @property string $status
 * @property bool $review_required
 * @property CarbonImmutable $occurred_at
 * @property string|null $schedule_occurrence_id
 * @property string|null $shift_id ADR-0030: the shift it was recorded in
 * @property CarbonImmutable|null $activity_started_at ADR-0030 §4.4
 * @property CarbonImmutable|null $activity_ended_at
 * @property string|null $activity_entry_id the activity this entry belongs to
 * @property array<string, mixed>|null $calculation ADR-0030 §4.7: the snapshot made at submission (before: only the phone's figure)
 * @property string|null $meter_resource_id
 * @property string|null $resource_id ADR-0031: the asset it is for
 * @property string|null $meter_measure
 * @property string|null $meter_start
 * @property string|null $meter_end
 * @property-read Shift|null $shift
 * @property 'named'|'extra'|null $schedule_link ADR-0030: what the author said it is for; null from older clients
 * @property string|null $log_track_id
 * @property array{lat: float, lng: float, accuracy_m: float|null, captured_at: string, received_at: string, source: string}|null $location
 * @property array<string, mixed> $values
 * @property string|null $search_text
 * @property int $revision
 * @property string $created_by
 * @property string|null $idempotency_key
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Logbook $logbook
 * @property-read LogSheet $sheet
 * @property-read LogSheetVersion $version
 * @property-read User $creator
 * @property-read User|null $reviewer
 */
class Entry extends Model
{
    use TenantModel;

    public const STATUSES = ['draft', 'submitted', 'returned', 'reviewed', 'finalized'];

    protected $table = 'log_entries';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'draft', 'revision' => 0, 'review_required' => false];

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'location' => 'array',
            'review_required' => 'boolean',
            'revision' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'activity_started_at' => 'immutable_datetime',
            'activity_ended_at' => 'immutable_datetime',
            'calculation' => 'array',
            'meter_start' => 'decimal:1',
            'meter_end' => 'decimal:1',
        ];
    }

    /** Its author may still change it. */
    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'returned'], true);
    }

    /** @return BelongsTo<Logbook, $this> */
    public function logbook(): BelongsTo
    {
        return $this->belongsTo(Logbook::class);
    }

    /** @return BelongsTo<LogSheet, $this> */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(LogSheet::class, 'log_sheet_id');
    }

    /** @return BelongsTo<LogSheetVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(LogSheetVersion::class, 'log_sheet_version_id');
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return HasMany<EntryRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(EntryRevision::class, 'log_entry_id')->orderByDesc('revision');
    }

    /** @return HasMany<EntryReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(EntryReview::class, 'log_entry_id')->orderByDesc('decided_at');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'log_entry_id')->orderBy('created_at');
    }

    /** ADR-0031: the asset it is for, when one is named. @return BelongsTo<LogResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(LogResource::class, 'resource_id');
    }
}
