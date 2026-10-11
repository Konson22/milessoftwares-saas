<?php

namespace App\Modules\Entitlements\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An organization's right to use an application (feature_key NULL) or one of
 * its features. Access decisions read entitlements, never subscriptions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $application_id
 * @property string|null $feature_key
 * @property int|null $limit_value
 * @property string $source subscription|trial|manual
 * @property Carbon $valid_from
 * @property Carbon|null $valid_until
 * @property Carbon|null $revoked_at
 */
class Entitlement extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return [
            'limit_value' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where('valid_from', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now()));
    }
}
