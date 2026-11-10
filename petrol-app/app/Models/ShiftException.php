<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Something a supervisor has to look at. Correcting a reading does not
 * delete the exception.
 *
 * @property string $id
 * @property string $branch_id
 * @property string $shift_id
 * @property string|null $assignment_id
 * @property string $type
 * @property string $description
 * @property string $severity
 * @property string $status
 * @property string|null $resolution
 */
class ShiftException extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const OPEN = 'open';

    public const UNDER_REVIEW = 'under_review';

    public const RESOLVED = 'resolved';

    public const CLOSED = 'closed';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
