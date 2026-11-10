<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something a project promises to hand over. Planned, then delivered, then
 * accepted or sent back to planned with a reason. Changes go through
 * App\Actions\Deliverables.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string|null $milestone_id
 * @property string $name
 * @property string|null $description
 * @property string|null $owner_id
 * @property CarbonImmutable|null $due_on
 * @property string $status
 * @property string|null $delivery_note
 * @property string|null $delivered_by
 * @property CarbonImmutable|null $delivered_at
 * @property string|null $accepted_by
 * @property CarbonImmutable|null $accepted_at
 * @property string|null $returned_reason
 * @property string $created_by
 * @property-read Project $project
 * @property-read Milestone|null $milestone
 * @property-read Person|null $owner
 * @property-read User|null $deliverer
 * @property-read User|null $accepter
 */
class Deliverable extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const PLANNED = 'planned';

    public const DELIVERED = 'delivered';

    public const ACCEPTED = 'accepted';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'due_on' => 'immutable_date',
            'delivered_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Milestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function accepter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
