<?php

namespace App\Modules\Organizations\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Not tenant-scoped: host resolution must look slugs up across all
 * organizations before any tenant context exists.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $slug
 * @property bool $is_primary
 * @property Carbon|null $retired_at
 * @property Carbon|null $redirect_until
 * @property-read Organization $organization
 */
#[Fillable(['slug', 'is_primary', 'retired_at', 'redirect_until'])]
class OrganizationSlug extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'retired_at' => 'datetime',
            'redirect_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function redirectsToPrimary(): bool
    {
        return ! $this->is_primary
            && $this->redirect_until !== null
            && $this->redirect_until->isFuture();
    }
}
