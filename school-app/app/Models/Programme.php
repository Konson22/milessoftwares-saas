<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * What an institute, college or university student studies: a certificate,
 * diploma or degree over a number of years. Each year of study is a class.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $department_id
 * @property string $code
 * @property string $name
 * @property string $award
 * @property int $duration_years
 * @property bool $active
 * @property-read Department|null $department
 */
class Programme extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['department_id', 'code', 'name', 'award', 'duration_years', 'active'];

    protected function casts(): array
    {
        return ['duration_years' => 'integer', 'active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<SchoolClass, $this>
     */
    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class)->orderBy('level')->orderBy('name');
    }
}
