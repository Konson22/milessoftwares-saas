<?php

namespace App\Modules\Organizations\Models;

use App\Modules\Applications\Models\Application;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A workspace one organization started for another (a donor for its grantee,
 * from Projects), waiting for the person it was made for. The workspace stays
 * pending, with no owner, until they claim it with the emailed link; then it
 * is theirs, and the sponsor has no place in it.
 *
 * Not tenant-scoped: it is about two organizations, and read across both.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property string $name
 * @property string|null $token_hash
 * @property string $sponsor_organization_id
 * @property string|null $sponsor_user_id
 * @property string $application_id
 * @property string $status pending|claimed|cancelled
 * @property Carbon $expires_at
 * @property Carbon|null $claimed_at
 * @property string|null $claimed_by_user_id
 * @property-read Organization $organization
 * @property-read Organization $sponsor
 * @property-read Application $application
 */
class OrganizationClaim extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const CLAIMED = 'claimed';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'claimed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'sponsor_organization_id');
    }

    /**
     * @return BelongsTo<Application, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * pending, expired (pending past its date), claimed or cancelled.
     */
    public function effectiveStatus(): string
    {
        return $this->status === self::PENDING && $this->expires_at->isPast() ? 'expired' : $this->status;
    }
}
