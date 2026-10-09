<?php

namespace App\Modules\Applications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string $application_id
 * @property string $catalog_version_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property int $sensitivity
 */
class ApplicationPermission extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['sensitivity' => 'integer'];
    }

    /**
     * @return list<string>
     */
    public function scopeTypes(): array
    {
        /** @var list<string> */
        return DB::table('application_permission_scope_types')
            ->where('application_permission_id', $this->getKey())
            ->pluck('scope_type')
            ->all();
    }
}
