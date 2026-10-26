<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A university faculty or school (Faculty of Medicine), holding departments.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $code
 */
class Faculty extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['name', 'code'];

    /**
     * @return HasMany<Department, $this>
     */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class)->orderBy('name');
    }
}
