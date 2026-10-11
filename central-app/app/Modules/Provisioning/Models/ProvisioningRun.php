<?php

namespace App\Modules\Provisioning\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $idempotency_key
 * @property string $status pending|running|succeeded|failed|cancelled
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 */
class ProvisioningRun extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /**
     * @return HasMany<ProvisioningStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ProvisioningStep::class);
    }
}
