<?php

namespace App\Modules\Administration\Models;

use App\Modules\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Time-boxed, justified access for one staff member to one organization's
 * data. Platform-owned (not tenant-scoped): it is how staff reach a tenant.
 * Written by SupportAccess only.
 *
 * @property string $id
 * @property string $platform_staff_id
 * @property string $organization_id
 * @property string $reason
 * @property string|null $ticket_reference
 * @property string $scope read_only|configuration
 * @property string|null $approved_by_staff_id
 * @property Carbon $starts_at
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property-read PlatformStaff $staff
 * @property-read PlatformStaff|null $approver
 * @property-read Organization $organization
 */
class SupportAccessGrant extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlatformStaff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'platform_staff_id');
    }

    /**
     * @return BelongsTo<PlatformStaff, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'approved_by_staff_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Approved, not revoked, and inside its time window right now.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInEffect(Builder $query): void
    {
        $query->whereNotNull('approved_by_staff_id')
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now());
    }

    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            $this->approved_by_staff_id === null => 'pending',
            $this->starts_at->isFuture() => 'scheduled',
            default => 'active',
        };
    }
}
