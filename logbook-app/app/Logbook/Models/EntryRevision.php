<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What was submitted, each time. Append-only.
 *
 * @property string $id
 * @property string $log_entry_id
 * @property int $revision
 * @property string $log_sheet_version_id
 * @property CarbonImmutable $occurred_at
 * @property array<string, mixed> $values
 * @property string $submitted_by
 * @property CarbonImmutable $submitted_at
 * @property CarbonImmutable|null $activity_started_at
 * @property CarbonImmutable|null $activity_ended_at
 * @property string|null $activity_entry_id
 * @property array<string, mixed>|null $calculation
 * @property-read LogSheetVersion $version
 * @property-read User $submitter
 */
class EntryRevision extends Model
{
    use TenantModel;

    public $timestamps = false;

    protected $table = 'log_entry_revisions';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['values' => 'array', 'location' => 'array', 'revision' => 'integer', 'occurred_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'activity_started_at' => 'immutable_datetime', 'activity_ended_at' => 'immutable_datetime', 'calculation' => 'array', 'stops' => 'array'];
    }

    /** @return BelongsTo<LogSheetVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(LogSheetVersion::class, 'log_sheet_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'log_entry_id');
    }
}
