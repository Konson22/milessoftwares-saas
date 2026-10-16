<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $status
 * @property Carbon|null $suspended_at
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property Carbon|null $password_change_required_at set: must choose a new password before anything else
 * @property Carbon|null $temporary_password_expires_at when the temporary password stops working
 * @property string|null $created_by_organization_id added by that organization's administrator (email not proven)
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'temporary_password_expires_at', 'created_by_organization_id'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Mirrors the column default, so new instances are active before a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password_change_required_at' => 'datetime',
            'temporary_password_expires_at' => 'datetime',
        ];
    }

    /**
     * Accounts an organization's administrator added are usable there without
     * an email check: the administrator vouched for the address. That is not
     * proof, so the column stays empty and everything that needs a proven
     * address (accepting another organization's invitation, the email_verified
     * claim products receive) still reads email_verified_at itself.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null || $this->created_by_organization_id !== null;
    }

    public function mustChangePassword(): bool
    {
        return $this->password_change_required_at !== null;
    }
}
