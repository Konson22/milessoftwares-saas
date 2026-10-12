<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property bool $active
 */
class Supplier extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['name', 'phone', 'email', 'address', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
