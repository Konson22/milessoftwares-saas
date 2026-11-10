<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Where an attendant may be rostered. Pump assignment is per shift, not here.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $attendant_id
 * @property string $branch_id
 * @property bool $active
 * @property-read Attendant $attendant
 */
class AttendantStation extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Attendant, $this>
     */
    public function attendant(): BelongsTo
    {
        return $this->belongsTo(Attendant::class);
    }
}
