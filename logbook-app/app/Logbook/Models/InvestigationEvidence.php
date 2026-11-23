<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pointer from an investigation to an original record (and, for an
 * entry, the exact revision). Holds the record's reference, never its
 * values; removal is a mark with a reason.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $investigation_id
 * @property string $kind
 * @property string $subject_id
 * @property int|null $entry_revision
 * @property string|null $logbook_id
 * @property string $label
 * @property string $role
 * @property string|null $note
 * @property string $added_by
 * @property CarbonImmutable $added_at
 * @property CarbonImmutable|null $removed_at
 * @property string|null $removed_by
 * @property string|null $removal_reason
 * @property-read User $adder
 * @property-read User|null $remover
 */
class InvestigationEvidence extends Model
{
    use TenantModel;

    public const KINDS = ['entry', 'exception', 'task', 'track'];

    public const ROLES = ['evidence', 'corrective'];

    public $timestamps = false;

    protected $table = 'log_investigation_evidence';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'entry_revision' => 'integer',
            'added_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function adder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /** @return BelongsTo<User, $this> */
    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }
}
