<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Where students sit: a primary class ("Primary 4 East", level 4) or a
 * programme's year of study ("DIP-NUR Year 2", level 2). Registers, marks
 * and fees are taken per class.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property int $level
 * @property string|null $programme_id
 * @property string|null $class_teacher_id
 * @property int|null $capacity
 * @property bool $active
 * @property-read Programme|null $programme
 * @property-read Staff|null $classTeacher
 */
class SchoolClass extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'school_classes';

    protected $fillable = ['name', 'level', 'programme_id', 'class_teacher_id', 'capacity', 'active'];

    protected function casts(): array
    {
        return ['level' => 'integer', 'capacity' => 'integer', 'active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function classTeacher(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'class_teacher_id');
    }

    /**
     * @return HasMany<Student, $this>
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class)->orderBy('last_name')->orderBy('first_name');
    }

    /**
     * Students currently in the class (not graduated, withdrawn...).
     *
     * @return HasMany<Student, $this>
     */
    public function enrolled(): HasMany
    {
        return $this->students()->where('status', Student::ACTIVE);
    }

    /**
     * @return HasMany<ClassSubject, $this>
     */
    public function classSubjects(): HasMany
    {
        return $this->hasMany(ClassSubject::class);
    }
}
