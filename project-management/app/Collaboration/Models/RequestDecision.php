<?php

namespace App\Collaboration\Models;

use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a request's life, or a comment. A submission keeps what was
 * asked. Append-only.
 *
 * @property string $id
 * @property string $request_id
 * @property string $action
 * @property string|null $comment
 * @property array<string, mixed>|null $snapshot
 * @property string $user_id
 * @property string $acting_organization_id
 * @property CarbonImmutable $created_at
 * @property-read User $user
 * @property-read Organization $actingOrganization
 */
class RequestDecision extends Model
{
    use AppendOnly;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'project_request_decisions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function actingOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'acting_organization_id');
    }
}
