<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member listed on a restricted logbook (or one of its sheets) as
 * contributor, reviewer or viewer. Grants nothing beyond the member's
 * catalog permissions: it says where, the catalog says what.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $logbook_id
 * @property string|null $log_sheet_id
 * @property string $user_id
 * @property string $role
 * @property string $granted_by
 * @property-read User $user
 * @property-read LogSheet|null $sheet
 */
class AccessGrant extends Model
{
    use TenantModel;

    public const ROLES = ['contributor', 'reviewer', 'viewer'];

    protected $table = 'logbook_access';

    protected $guarded = ['id', 'organization_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<LogSheet, $this> */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(LogSheet::class, 'log_sheet_id');
    }
}
