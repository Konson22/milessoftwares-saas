<?php

namespace App\Modules\Administration\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Internal Miles Global Softwares staff record. Not mass assignable: staff are
 * created by an existing Super Admin or a console command only.
 *
 * @property string $id
 * @property string $user_id
 * @property string $status
 * @property-read User $user
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

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
