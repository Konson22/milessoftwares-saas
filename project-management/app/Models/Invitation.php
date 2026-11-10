<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation to join an organization with some roles. The link it sends
 * is also the activation link: someone without an account sets their
 * password through it. Only the token's sha256 is stored; a link works
 * once, until it expires or is revoked.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property list<string> $roles
 * @property string $token_hash
 * @property string|null $invited_by
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property string|null $accepted_by
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property-read Organization $organization
 * @property-read User|null $inviter
 */
class Invitation extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'organization_id', 'token_hash'];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The open invitation a link's token belongs to, if any.
     */
    public static function findOpen(string $token): ?self
    {
        return static::query()->open()->where('token_hash', self::hashToken($token))->first();
    }

    /**
     * Not accepted, revoked or expired.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
