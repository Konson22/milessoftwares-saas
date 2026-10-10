<?php

namespace App\Modules\Administration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Synced from PlatformRoleCatalog; permissions live in code.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 */
class PlatformRole extends Model
{
    use HasUuids;
}
