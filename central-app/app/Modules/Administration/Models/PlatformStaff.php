<?php

namespace App\Modules\Administration\Models;

use App\Models\User;
use App\Modules\Administration\Enums\PlatformPermission;
use App\Modules\Administration\Support\PlatformRoleCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Internal Miles Global Softwares staff record. Not mass assignable: staff are
 * created by an existing Super Admin or a console command only.
 *
 * @property string $id
 * @property string $user_id
 * @property string $status
 * @property-read User $user
 * @property-read Collection<int, PlatformRole> $roles
 */
class PlatformStaff extends Model
{
    use HasUuids;

    protected $table = 'platform_staff';

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<PlatformRole, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(PlatformRole::class, 'platform_staff_roles')
            ->withPivot(['granted_by_staff_id', 'granted_at']);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * @return list<PlatformPermission>
     */
    public function permissions(): array
    {
        $permissions = [];

        foreach ($this->roles as $role) {
            foreach (PlatformRoleCatalog::permissionsFor($role->key) as $permission) {
                $permissions[$permission->value] = $permission;
            }
        }

        return array_values($permissions);
    }

    public function hasPermission(PlatformPermission $permission): bool
    {
        return $this->isActive() && in_array($permission, $this->permissions(), true);
    }

    public function hasRole(string $key): bool
    {
        return $this->roles->contains('key', $key);
    }
}
