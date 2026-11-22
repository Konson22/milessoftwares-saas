<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the logs are about, named once (ADR-0026 §2.10): a generator,
 * a vehicle, a computer, a room. Not an asset register.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property string|null $description
 * @property string|null $location_id
 * @property bool $active
 * @property-read Location|null $location
 */
class LogResource extends Model
{
    use TenantModel;

    protected $table = 'log_resources';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }
}
