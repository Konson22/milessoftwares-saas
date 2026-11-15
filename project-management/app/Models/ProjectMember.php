<?php

namespace App\Models;

use App\Access\ProjectBound;
use App\Access\VisibleThroughProject;
use App\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone on a project's team, with their role and how much of their time
 * it takes.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $project_id
 * @property string $person_id
 * @property string|null $role
 * @property int $allocation_percent
 * @property list<string>|null $responsibilities App\Collaboration\Responsibility keys
 * @property string|null $org_unit_id the department they stand for on the project
 * @property-read Person $person
 * @property-read OrgUnit|null $orgUnit
 * @property-read Project $project
 */
class ProjectMember extends Model implements ProjectBound
{
    use BelongsToOrganization;
    use HasUuids;
    use VisibleThroughProject;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['allocation_percent' => 'integer', 'responsibilities' => 'array'];
    }

    /**
     * @return BelongsTo<OrgUnit, $this>
     */
    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
