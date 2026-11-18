<?php

namespace App\Collect\Models;

use App\Mel\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One submission counted by a calculation run, with the revision of the
 * answers it was counted from and what it added.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $run_id
 * @property string $indicator_id
 * @property string $submission_id
 * @property string|null $revision_id
 * @property string $amount
 * @property bool|null $in_numerator
 * @property bool $standing
 * @property-read CalculationRun $run
 * @property-read Submission $submission
 * @property-read SubmissionRevision|null $revision
 */
class CalculationRow extends Model
{
    use TenantModel;

    protected $table = 'collect_calculation_rows';

    public $timestamps = false;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['in_numerator' => 'boolean', 'standing' => 'boolean'];
    }

    /**
     * @return BelongsTo<CalculationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(CalculationRun::class, 'run_id');
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * @return BelongsTo<SubmissionRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(SubmissionRevision::class, 'revision_id');
    }
}
