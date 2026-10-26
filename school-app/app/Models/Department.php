<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * An academic department, in a faculty at a university. It offers
 * programmes and is where staff work.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $faculty_id
 * @property string $name
 * @property string|null $code
 * @property-read Faculty|null $faculty
 */
class Department extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['faculty_id', 'name', 'code'];

    /**
     * @return BelongsTo<Faculty, $this>
     */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    /**
     * @return HasMany<Programme, $this>
     */
    public function programmes(): HasMany
    {
        return $this->hasMany(Programme::class)->orderBy('name');
    }
}
