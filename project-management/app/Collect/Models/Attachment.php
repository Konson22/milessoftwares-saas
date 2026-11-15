<?php

namespace App\Collect\Models;

use App\Mel\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo of a submission, on the private disk. Read only through
 * App\Http\Controllers\Collect\SubmissionController, by people who may see
 * personal answers.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $submission_id
 * @property string $question
 * @property string $sha256
 * @property int $size
 * @property string $mime_type
 * @property string $path
 * @property CarbonImmutable $received_at
 * @property-read Submission $submission
 */
class Attachment extends Model
{
    use TenantModel;

    public $timestamps = false;

    protected $table = 'collect_attachments';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'received_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
