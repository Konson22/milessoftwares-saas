<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A part of how an organization is arranged: a department, team, branch or
 * country office, perhaps inside another. Changes go through App\Actions\OrgUnits.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $parent_id
 * @property string $kind
 * @property string $name
 * @property string|null $code
 * @property CarbonImmutable|null $archived_at
 */
class OrgUnit extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const KINDS = [
        'department' => 'Department',
        'team' => 'Team',
        'branch' => 'Branch',
        'country_office' => 'Country office',
    ];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<OrgUnit, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
