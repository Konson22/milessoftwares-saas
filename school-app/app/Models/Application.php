<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * An application for admission (APP-00001) to a class level or a
 * programme: PENDING, then ADMITTED (it becomes a student) or DECLINED.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $number
 * @property string $first_name
 * @property string $last_name
 * @property string $gender
 * @property CarbonImmutable|null $date_of_birth
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $guardian_name
 * @property string|null $guardian_phone
 * @property string|null $previous_school
 * @property int $level
 * @property string|null $programme_id
 * @property string $status
 * @property string $received_by
 * @property string|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property string|null $student_id
 * @property-read Programme|null $programme
 * @property-read Student|null $student
 * @property-read User|null $decider
 */
class Application extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PENDING = 'pending';

    public const ADMITTED = 'admitted';

    public const DECLINED = 'declined';

    public const STATUSES = [self::PENDING, self::ADMITTED, self::DECLINED];

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'level' => 'integer', 'decided_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Programme, $this>
     */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function name(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
