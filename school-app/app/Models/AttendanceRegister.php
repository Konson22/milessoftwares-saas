<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One register: a class on a day, for one subject outside primary schools.
 * Taking it again the same day replaces its entries.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $school_class_id
 * @property string|null $subject_id
 * @property string $term_id
 * @property CarbonImmutable $taken_on
 * @property string $taken_by
 * @property-read SchoolClass $schoolClass
 * @property-read Subject|null $subject
 * @property-read User $taker
 */
class AttendanceRegister extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['taken_on' => 'date'];
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function taker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    /**
     * @return HasMany<AttendanceEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(AttendanceEntry::class);
    }
}
