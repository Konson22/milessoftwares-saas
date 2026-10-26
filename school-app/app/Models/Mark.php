<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A student's score out of 100 in one assessment of one subject.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $assessment_id
 * @property string $subject_id
 * @property string $student_id
 * @property string $score
 * @property string $recorded_by
 */
class Mark extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['score' => 'decimal:2'];
    }
}
