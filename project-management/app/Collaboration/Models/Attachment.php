<?php

namespace App\Collaboration\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file on a report version or a request, on the private disk. Files cross
 * organizations here, so they are not in either organization's documents.
 * Opened only by those who may see what it is attached to.
 *
 * @property string $id
 * @property string $organization_id the project's owner
 * @property string $attachable_type self::REPORT_VERSION or self::REQUEST
 * @property string $attachable_id
 * @property string $name
 * @property string $path
 * @property string|null $mime
 * @property int $size
 * @property string $uploaded_by
 * @property string $acting_organization_id
 * @property CarbonImmutable $created_at
 * @property-read User $uploader
 */
class Attachment extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const REPORT_VERSION = 'report_version';

    public const REQUEST = 'request';

    /** The most one file may be, in kilobytes. */
    public const MAX_KILOBYTES = 20480;

    protected $table = 'collaboration_attachments';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
