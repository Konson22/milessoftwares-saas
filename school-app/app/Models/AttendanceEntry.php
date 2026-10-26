<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A student's mark in a register: present, late, absent or excused.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $attendance_register_id
 * @property string $student_id
 * @property string $status
 * @property-read AttendanceRegister $register
 */
class AttendanceEntry extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PRESENT = 'present';

    public const LATE = 'late';

    public const ABSENT = 'absent';

    public const EXCUSED = 'excused';

    public const STATUSES = [self::PRESENT, self::LATE, self::ABSENT, self::EXCUSED];

    protected $guarded = ['organization_id'];

    /**
     * @return BelongsTo<AttendanceRegister, $this>
     */
    public function register(): BelongsTo
    {
        return $this->belongsTo(AttendanceRegister::class, 'attendance_register_id');
    }
}
