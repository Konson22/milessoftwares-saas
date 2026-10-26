<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A subject a class is taught, and who teaches it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $school_class_id
 * @property string $subject_id
 * @property string|null $teacher_id
 * @property-read Subject $subject
 * @property-read Staff|null $teacher
 */
class ClassSubject extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['school_class_id', 'subject_id', 'teacher_id'];

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'teacher_id');
    }
}
