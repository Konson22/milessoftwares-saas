<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A milestone of a linked project, as Projects last reported it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $linked_project_id
 * @property string $external_id
 * @property string $name
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $reached_on
 * @property-read LinkedProject $project
 */
class LinkedMilestone extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['due_on' => 'immutable_date', 'reached_on' => 'immutable_date'];
    }

    /**
     * @return BelongsTo<LinkedProject, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(LinkedProject::class, 'linked_project_id');
    }
}
