<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A donor organization and a beneficiary or implementer it works with: the one
 * record that crosses two organizations, so it is not organization-scoped. Every
 * read starts from the donor or the beneficiary in hand. Written by
 * App\Actions\Beneficiaries.
 *
 * Two ways in, one record: an invitation by email (a token; the organization
 * is set up or connected on accepting), or a direct one to an organization
 * already on the platform (invited_organization_id; its administrators accept
 * in the app). Either can be declined by the invited side or withdrawn by the
 * donor; accepting connects, and gives the donor nothing it is not shared.
 *
 * @property string $id
 * @property string $donor_organization_id
 * @property string|null $beneficiary_organization_id
 * @property string|null $invited_organization_id
 * @property CarbonImmutable|null $declined_at
 * @property string|null $declined_by
 * @property string|null $funder_id
 * @property string $label
 * @property string|null $role the role key the invited person holds in the new organization, besides owning it
 * @property string $name
 * @property string|null $email null for a direct invitation
 * @property string $currency
 * @property string $timezone
 * @property string|null $country_code
 * @property string|null $token_hash
 * @property string $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property string|null $accepted_by
 * @property-read Organization $donor
 * @property-read Organization|null $beneficiary
 * @property-read User $inviter
 */
class Partnership extends Model
{
    use HasUuids;

    /** What the donor calls them; both get the same workspace. */
    public const LABELS = ['beneficiary' => 'Beneficiary', 'implementer' => 'Project implementer'];

    protected $guarded = ['donor_organization_id', 'beneficiary_organization_id', 'invited_organization_id', 'funder_id', 'token_hash', 'accepted_at', 'accepted_by', 'declined_at', 'declined_by'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'declined_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function invitedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'invited_organization_id');
    }

    /**
     * Direct invitations an organization may still accept or decline.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePendingFor(Builder $query, Organization $organization): Builder
    {
        return $query->where('invited_organization_id', $organization->id)
            ->whereNull('accepted_at')->whereNull('declined_at')->where('expires_at', '>', now());
    }

    /**
     * Still waiting for an answer, either way: not accepted, declined or expired.
     */
    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->declined_at === null && $this->expires_at->isFuture();
    }

    public function isDeclined(): bool
    {
        return $this->declined_at !== null;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'donor_organization_id');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'beneficiary_organization_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Invitations still waiting and in time.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('declined_at')->whereNotNull('token_hash')->where('expires_at', '>', now());
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findOpen(string $token): ?self
    {
        return self::query()->open()->where('token_hash', self::hashToken($token))->first();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function labelText(): string
    {
        return self::LABELS[$this->label] ?? $this->label;
    }
}
