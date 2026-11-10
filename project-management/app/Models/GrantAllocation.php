<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Part of a grant given to a project: amount_minor in the grant's
 * currency, project_amount_minor in the project's, at exchange_rate
 * (project currency per unit of grant currency, recorded when allocated).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $grant_id
 * @property string $project_id
 * @property int $amount_minor
 * @property string $exchange_rate
 * @property int $project_amount_minor
 * @property string|null $note
 * @property string $created_by
 * @property-read Grant $grant
 * @property-read Project $project
 */
class GrantAllocation extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'exchange_rate' => 'decimal:8', 'project_amount_minor' => 'integer'];
    }

    /**
     * @return BelongsTo<Grant, $this>
     */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(Grant::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
