<?php

namespace App\Mel\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supporting document on the private disk, downloaded only through M&E
 * (App\Http\Controllers\EvidenceController).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $programme_id
 * @property string|null $submission_id
 * @property string|null $result_id
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property string $sha256
 * @property bool $share_with_donors
 * @property string $uploaded_by
 * @property CarbonImmutable $created_at
 * @property-read Submission|null $submission
 * @property-read User $uploader
 */
class EvidenceFile extends Model
{
    protected $table = 'mel_evidence_files';

    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'share_with_donors' => 'boolean', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
