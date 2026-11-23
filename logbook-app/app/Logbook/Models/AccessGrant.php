<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member of a logbook (or of one of its sheets) as contributor, reviewer
 * or viewer: without one, only managers reach the logbook. Grants nothing
 * beyond the member's catalog role and its department: it says which
 * logbooks, the role says what.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $logbook_id
 * @property string|null $log_sheet_id
 * @property string $user_id
 * @property string $role
 * @property string $granted_by
 * @property-read User $user
 * @property-read Logbook $logbook
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

    /** @return BelongsTo<Logbook, $this> */
    public function logbook(): BelongsTo
    {
        return $this->belongsTo(Logbook::class);
    }

    /** @return BelongsTo<LogSheet, $this> */
    public function sheet(): BelongsTo
    {
        return $this->belongsTo(LogSheet::class, 'log_sheet_id');
    }
}
