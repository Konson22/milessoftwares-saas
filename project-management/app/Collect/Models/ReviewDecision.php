<?php

namespace App\Collect\Models;

use App\Mel\Models\Concerns\TenantModel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a collected submission's review. Append-only.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $submission_id
 * @property string $action
 * @property string|null $comment
 * @property array<string, string>|null $notes
 * @property string $user_id
 * @property CarbonImmutable $created_at
 * @property-read User $user
 */
class ReviewDecision extends Model
{
    use TenantModel;

    public const UPDATED_AT = null;

    protected $table = 'collect_review_decisions';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['notes' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
