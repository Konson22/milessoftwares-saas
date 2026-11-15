<?php

namespace App\Collaboration\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member of a participating organization who works on the shared project
 * for it. Only they (and those who manage its collaboration) see the project:
 * being in the organization is not enough. Removing one keeps the row.
 *
 * @property string $id
 * @property string $participant_id
 * @property string $user_id
 * @property list<string>|null $responsibilities
 * @property string $added_by
 * @property CarbonImmutable|null $removed_at
 * @property string|null $removed_by
 * @property-read Participant $participant
 * @property-read User $user
 */
class Representative extends Model
{
    use HasUuids;

    protected $table = 'project_participant_members';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['responsibilities' => 'array', 'removed_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Participant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
