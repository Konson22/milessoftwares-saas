<?php

namespace App\Modules\Applications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Two permissions one member must not hold at overlapping scopes.
 *
 * @property string $id
 * @property string $catalog_version_id
 * @property string $permission_a_id
 * @property string $permission_b_id
 * @property string $mode block|warn
 * @property string|null $description
 * @property-read ApplicationPermission $permissionA
 * @property-read ApplicationPermission $permissionB
 */
class SodConstraint extends Model
{
    use HasUuids;

    /**
     * @return BelongsTo<ApplicationPermission, $this>
     */
    public function permissionA(): BelongsTo
    {
        return $this->belongsTo(ApplicationPermission::class, 'permission_a_id');
    }

    /**
     * @return BelongsTo<ApplicationPermission, $this>
     */
    public function permissionB(): BelongsTo
    {
        return $this->belongsTo(ApplicationPermission::class, 'permission_b_id');
    }

    public function blocks(): bool
    {
        return $this->mode === 'block';
    }
}
