<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a submission's review. Append-only.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $submission_id
 * @property string $action
 * @property string|null $comment
 * @property string $user_id
 * @property CarbonImmutable $created_at
 * @property-read User $user
 */
class ReviewDecision extends Model
{
    use TenantModel;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
