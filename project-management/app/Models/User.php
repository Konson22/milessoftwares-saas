<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A platform user who has opened Projects. The id is the platform subject
 * (immutable); name and email come from the platform at every sign-in
 * (miles/platform-client), which has verified the address. Nothing here
 * grants access: the platform decides that on every request.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property bool $email_alerts
 * @property CarbonImmutable|null $email_verified_at
 * @property string $status
 * @property CarbonImmutable|null $last_login_at
 */
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;

    public const ACTIVE = 'active';

    /** Set from the platform when it suspends the account. */
    public const SUSPENDED = 'suspended';

    protected $fillable = ['name', 'email'];

    protected static function booted(): void
    {
        // The platform signs in verified addresses only.
        static::creating(function (User $user): void {
            $user->email_verified_at ??= now();
        });
    }

    /**
     * Whether alerts may be emailed: not turned off.
     */
    public function wantsEmailAlerts(): bool
    {
        return $this->email_alerts && $this->email_verified_at !== null && $this->isActive();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'email_alerts' => 'boolean',
        ];
    }

    /**
     * Emails are compared and stored lowercased.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * @return HasMany<OrganizationMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public static function findByEmail(string $email): ?self
    {
        return static::query()->where('email', mb_strtolower(trim($email)))->first();
    }
}
