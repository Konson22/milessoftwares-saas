<?php

namespace App\Logbook\Models;

use App\Logbook\Models\Concerns\TenantModel;
use App\Logbook\ResourceCategories;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Something the logs are about, registered once (ADR-0026 §2.10,
 * ADR-0031): a generator, a vehicle, a cooling unit, or a monitored place
 * such as a cold room. Its id never changes; it is retired, never deleted.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string $type the finer kind within its category (Car, Pickup, Diesel generator...)
 * @property string|null $category_id the broad group (Vehicles, Generators...)
 * @property string $kind asset or monitored_location
 * @property string $status active, inactive or retired
 * @property string|null $description
 * @property string|null $location_id
 * @property string|null $manufacturer
 * @property string|null $model
 * @property string|null $serial_number
 * @property string|null $responsible_id
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed>|null $template the template as it was at registration
 * @property bool $active true only while status is active
 * @property-read ResourceCategory|null $category
 * @property-read Location|null $location
 * @property-read User|null $responsible
 */
class LogResource extends Model
{
    use TenantModel;

    public const KINDS = ['asset', 'monitored_location'];

    public const STATUSES = ['active', 'inactive', 'retired'];

    protected $table = 'log_resources';

    protected $guarded = ['id', 'organization_id'];

    protected $attributes = ['active' => true, 'status' => 'active', 'kind' => 'asset'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'metadata' => 'array', 'template' => 'array'];
    }

    protected static function booted(): void
    {
        // `active` is what entry forms and older code read: it follows the status.
        // Every asset sits in a category: the one its type falls in, unless one is chosen.
        static::creating(function (LogResource $resource) {
            $resource->category_id ??= ResourceCategories::guess($resource->type)?->id;
        });
        static::saving(function (LogResource $resource) {
            if ($resource->isDirty('status')) {
                $resource->active = $resource->status === 'active';
            } elseif ($resource->isDirty('active')) {
                $resource->status = $resource->active ? 'active' : ($resource->status === 'retired' ? 'retired' : 'inactive');
            }
        });
    }

    /** @return BelongsTo<ResourceCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ResourceCategory::class, 'category_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /** The log sheets that record it. @return BelongsToMany<LogSheet, $this> */
    public function sheets(): BelongsToMany
    {
        return $this->belongsToMany(LogSheet::class, 'log_sheet_resources', 'log_resource_id', 'log_sheet_id')
            ->withPivot(['id', 'organization_id', 'created_by'])->withTimestamps();
    }

    public function label(): string
    {
        return "{$this->code} · {$this->name}";
    }
}
