<?php

namespace App\Modules\Subscriptions\Models;

use App\Models\User;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a visitor chose on the public site, carried through registering,
 * verifying, signing in and choosing the organization (see
 * RegistrationIntents). Not tenant-scoped: it exists before any
 * organization does. The token itself is never stored, only its hash.
 *
 * @property string $id
 * @property string $token_hash
 * @property string $target_type application|product|plan
 * @property string $target_code
 * @property string|null $plan_code
 * @property string $action trial|subscribe
 * @property string|null $source
 * @property array<string, string>|null $campaign
 * @property string|null $user_id
 * @property Carbon|null $claimed_at
 * @property string|null $organization_id
 * @property string $status pending|completed|cancelled
 * @property string|null $outcome
 * @property array<string, mixed>|null $outcome_details
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property-read User|null $user
 * @property-read Organization|null $organization
 */
class RegistrationIntent extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'campaign' => 'array',
            'outcome_details' => 'array',
            'claimed_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
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
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  Builder<RegistrationIntent>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'pending')->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isPast();
    }
}
