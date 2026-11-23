<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A logbook chosen for someone invited by email who has not opened
 * Logbook yet. Turned into a membership on their first visit.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property string|null $invitation_id
 * @property string $logbook_id
 * @property string $granted_by
 * @property-read Logbook $logbook
 */
class PendingMember extends Model
{
    use TenantModel;

    protected $table = 'logbook_pending_members';

    protected $guarded = ['id', 'organization_id'];

    /** @return BelongsTo<Logbook, $this> */
    public function logbook(): BelongsTo
    {
        return $this->belongsTo(Logbook::class);
    }
}
