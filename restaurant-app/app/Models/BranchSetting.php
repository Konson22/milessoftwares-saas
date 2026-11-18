<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * How one branch works in the restaurant: lines printed on its receipts,
 * the cash its tills usually start with, and how long a kitchen ticket may
 * wait before it is flagged. The branch itself is the workspace's.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string|null $receipt_header
 * @property string|null $receipt_footer
 * @property string|null $phone
 * @property int|null $default_float_minor
 * @property int|null $slow_ticket_minutes
 * @property string|null $updated_by
 */
class BranchSetting extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** A ticket waiting longer than this is flagged, unless the branch says otherwise. */
    public const SLOW_TICKET_MINUTES = 20;

    /** The least a branch may set. */
    public const SLOW_TICKET_MINUTES_MIN = 5;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['default_float_minor' => 'integer', 'slow_ticket_minutes' => 'integer'];
    }

    /** The branch's settings, or the defaults when it has none saved. */
    public static function for(string $branchId): self
    {
        return self::query()->where('branch_id', $branchId)->first() ?? new self(['branch_id' => $branchId]);
    }

    public function slowTicketMinutes(): int
    {
        return $this->slow_ticket_minutes ?? self::SLOW_TICKET_MINUTES;
    }
}
