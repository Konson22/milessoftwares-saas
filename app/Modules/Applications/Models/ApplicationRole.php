<?php

namespace App\Modules\Applications\Models;

use App\Modules\Authorization\Enums\ScopeType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * A role declared by an application's catalog. Its permissions and allowed
 * scope types are fixed by the catalog version it belongs to.
 *
 * @property string $id
 * @property string $application_id
 * @property string $catalog_version_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_delegable
 * @property int $sensitivity
 * @property-read Application $application
 * @property-read ApplicationCatalogVersion $catalogVersion
 */
class ApplicationRole extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'is_delegable' => 'boolean',
            'sensitivity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * @return BelongsTo<ApplicationCatalogVersion, $this>
     */
    public function catalogVersion(): BelongsTo
    {
        return $this->belongsTo(ApplicationCatalogVersion::class, 'catalog_version_id');
    }

    /**
     * @return BelongsToMany<ApplicationPermission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(ApplicationPermission::class, 'role_permission_mappings', 'application_role_id', 'application_permission_id');
    }

    /**
     * @return list<ScopeType>
     */
    public function allowedScopeTypes(): array
    {
        return array_values(DB::table('application_role_scope_types')
            ->where('application_role_id', $this->getKey())
            ->pluck('scope_type')
            ->map(fn (string $type) => ScopeType::from($type))
            ->all());
    }

    public function allowsScope(ScopeType $type): bool
    {
        return in_array($type, $this->allowedScopeTypes(), true);
    }
}
