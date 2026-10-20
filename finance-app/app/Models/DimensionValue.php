<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A cost centre or a project, set on journal lines to report income and
 * spending by what it was for. Never deleted once used; made inactive.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $kind
 * @property string $code
 * @property string $name
 * @property bool $active
 */
class DimensionValue extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const COST_CENTRE = 'cost_centre';

    public const PROJECT = 'project';

    public const KINDS = [self::COST_CENTRE, self::PROJECT];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * "ADM · Administration".
     */
    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }

    /**
     * The journal line column that holds this kind.
     */
    public static function column(string $kind): string
    {
        return $kind === self::PROJECT ? 'project_id' : 'cost_centre_id';
    }

    public static function kindLabel(string $kind): string
    {
        return $kind === self::PROJECT ? 'Project' : 'Cost centre';
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOfKind(Builder $query, string $kind): void
    {
        $query->where('kind', $kind);
    }
}
