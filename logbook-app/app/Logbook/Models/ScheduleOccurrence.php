<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One expected entry of a schedule. Open until an entry meets it
 * (completed, or late within the late window) or the window closes
 * (missed); a manager may excuse it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $schedule_id
 * @property string $logbook_id
 * @property string $log_sheet_id
 * @property CarbonImmutable $occurs_on
 * @property CarbonImmutable $opens_at
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable $closes_at
 * @property string|null $responsible_id
 * @property string $status
 * @property string|null $log_entry_id
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $settled_at
 * @property string|null $timeliness
 * @property string|null $timeliness
 * @property string|null $excused_by
 * @property string|null $excuse_reason
 * @property-read Schedule $schedule
 * @property-read LogSheet $sheet
 * @property-read Logbook $logbook
 * @property-read Entry|null $entry
 * @property-read User|null $responsible
 */
class ScheduleOccurrence extends Model
{
    use TenantModel;

    public const STATUSES = ['open', 'completed', 'late', 'missed', 'excused'];

    protected $table = 'log_schedule_occurrences';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'occurs_on' => 'immutable_date',
            'opens_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'closes_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }

    /**
     * What a person sees: upcoming, due, overdue (past due, still in its
     * late window), awaiting review (an entry is in, the schedule wants it
     * reviewed), or the settled status.
     */
    public function state(?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();
        if ($this->status !== 'open') {
            return $this->status;
        }
        if ($this->log_entry_id !== null) {
            return 'awaiting_review';
        }

        return match (true) {
            $now->lt($this->opens_at) => 'upcoming',
            $now->lte($this->due_at) => 'due',
            default => 'overdue',
        };
    }

    /** @return BelongsTo<Schedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    /** @return BelongsTo<LogSheet, $this> */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(LogSheet::class, 'log_sheet_id');
    }

    /** @return BelongsTo<Logbook, $this> */
    public function logbook(): BelongsTo
    {
        return $this->belongsTo(Logbook::class);
    }

    /** @return BelongsTo<Entry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class, 'log_entry_id');
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }
}
