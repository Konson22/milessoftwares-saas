<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platform organization (workspace) using Projects: an NGO, a donor, an
 * implementing partner... Its id is the platform's (App\Actions\Organizations::mirror). Everything it owns carries its id as organization_id. What it
 * can act as is a set of capabilities, never one exclusive type: the same
 * organization funds one project and implements another.
 *
 * @property string $id
 * @property string $slug
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $country_code
 * @property string $currency
 * @property string $timezone
 * @property string $status
 * @property list<string>|null $capabilities
 * @property list<string>|null $disabled_modules
 * @property int $health_schedule_slack
 * @property int $health_budget_slack
 * @property int|null $petty_cash_max_payment_minor the largest single petty cash payment (none set: nothing is paid)
 * @property int|null $advance_max_minor the largest staff advance
 * @property int|null $advance_retire_days how long staff have to account for an advance
 * @property string|null $created_by
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable $created_at
 */
class Organization extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    /** Funds other organizations: the funding sections show in its menu. */
    public const DONOR = 'donor';

    public const DEFAULT_HEALTH_SLACK = 20;

    protected $fillable = ['slug', 'name', 'legal_name', 'country_code', 'currency', 'timezone', 'capabilities', 'disabled_modules', 'health_schedule_slack', 'health_budget_slack'];

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'disabled_modules' => 'array', 'suspended_at' => 'datetime', 'health_schedule_slack' => 'integer', 'health_budget_slack' => 'integer', 'petty_cash_max_payment_minor' => 'integer', 'advance_max_minor' => 'integer', 'advance_retire_days' => 'integer'];
    }

    /**
     * @return HasMany<OrganizationMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * Whether this part of the product is on (App\Support\Modules).
     */
    public function hasModule(string $module): bool
    {
        return ! in_array($module, $this->disabled_modules ?? [], true);
    }

    public function isDonor(): bool
    {
        return in_array(self::DONOR, $this->capabilities ?? [], true);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
