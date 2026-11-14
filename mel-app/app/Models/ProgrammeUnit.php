<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What results are reported for: a project, a site, a partner's share of
 * the work. It may be linked to a project kept in Tatook Projects, which
 * stays the record of that project.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $programme_id
 * @property string $code
 * @property string $name
 * @property string $kind
 * @property string|null $partner_id
 * @property string|null $location_id
 * @property string|null $linked_project_id
 * @property CarbonImmutable|null $archived_at
 * @property-read Programme $programme
 * @property-read Partner|null $partner
 * @property-read Location|null $location
 * @property-read LinkedProject|null $linkedProject
 */
class ProgrammeUnit extends Model
{
    use TenantModel;

    public const KINDS = ['project', 'site', 'partner', 'other'];

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['archived_at' => 'immutable_datetime'];
    }

    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<LinkedProject, $this>
     */
    public function linkedProject(): BelongsTo
    {
        return $this->belongsTo(LinkedProject::class);
    }
}
