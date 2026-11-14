<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;

/**
 * One disaggregated cell of a submission.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $submission_id
 * @property string $breakdown_id
 * @property string $category_id
 * @property string|null $second_category_id
 * @property string|null $value
 * @property string|null $numerator
 * @property string|null $denominator
 */
class SubmissionValue extends Model
{
    use TenantModel;

    public $timestamps = false;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['value' => 'decimal:6', 'numerator' => 'decimal:6', 'denominator' => 'decimal:6'];
    }
}
