<?php

namespace App\Collect\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A correction of a submission's answers, with its reason. Append-only:
 * the original and every correction stay readable.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $submission_id
 * @property int $number
 * @property array<string, mixed> $answers
 * @property list<string> $changed
 * @property string $reason
 * @property string $author_id
 * @property CarbonImmutable $created_at
 * @property-read User $author
 */
class SubmissionRevision extends Model
{
    use TenantModel;

    public const UPDATED_AT = null;

    protected $table = 'collect_submission_revisions';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'answers' => 'array',
            'changed' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
