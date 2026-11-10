<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file on a task, kept on the private disk and served only through an
 * authorized route.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $task_id
 * @property string $original_name
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property string $uploaded_by
 * @property CarbonImmutable $created_at
 * @property-read User $uploader
 */
class TaskAttachment extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const UPDATED_AT = null;

    /** What can be attached: the same as supporting documents on spending. */
    public const RULES = ExpenseDocument::RULES;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
