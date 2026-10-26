<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One step of a shipment's tracking history: the status it reached, or an
 * update on the road (status unchanged), with where and a note.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $shipment_id
 * @property string $status
 * @property string|null $location
 * @property string|null $note
 * @property string $recorded_by
 * @property CarbonImmutable $occurred_at
 * @property-read User $recorder
 */
class ShipmentEvent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
