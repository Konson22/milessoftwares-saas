<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A subject (primary), module (institute) or course, with its credit hours
 * at colleges and universities.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property int|null $credit_hours
 * @property bool $active
 */
class Subject extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['code', 'name', 'credit_hours', 'active'];

    protected function casts(): array
    {
        return ['credit_hours' => 'integer', 'active' => 'boolean'];
    }
}
