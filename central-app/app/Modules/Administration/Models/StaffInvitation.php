<?php

namespace App\Modules\Administration\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation to join the platform staff. Not mass assignable: created by
 * StaffInvitations only.
 *
 * @property string $id
 * @property string $email
 * @property string|null $name
 * @property string|null $role
 * @property string $token_hash
 * @property string|null $invited_by_staff_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property-read PlatformStaff|null $invitedBy
 */
class StaffInvitation extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<PlatformStaff, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'invited_by_staff_id');
    }

    /** pending, accepted, revoked or expired. */
    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }
}
