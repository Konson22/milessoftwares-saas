<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A pause in one attendant's dispensing. The assignment before the break
 * is closed at the meter, and a return opens a new assignment.
 *
 * @property string $id
 * @property string $assignment_id
 * @property string|null $replacement_assignment_id
 * @property string|null $return_assignment_id
 * @property string $reason
 * @property bool $nozzle_available
 * @property int $reading_milli
 */
class ShiftBreak extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'nozzle_available' => 'boolean',
            'reading_milli' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
