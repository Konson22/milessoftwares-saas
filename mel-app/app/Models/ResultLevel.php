<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * One level of a programme's results framework (Goal, Outcome, ...).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $programme_id
 * @property int $depth
 * @property string $name
 */
class ResultLevel extends Model
{
    use TenantModel;

    /** What a new programme starts with; renamed, added to or removed per programme. */
    public const DEFAULTS = ['Goal', 'Outcome', 'Output', 'Activity'];

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['depth' => 'integer'];
    }
}
