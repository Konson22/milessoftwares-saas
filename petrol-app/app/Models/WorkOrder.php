<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * @property string $id
 * @property string $branch_id
 * @property string $status
 * @property int $labor_minor
 * @property int|null $invoiced_minor
 * @property string $number
 */
class WorkOrder extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const OPEN = 'open';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const INVOICED = 'invoiced';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'labor_minor' => 'integer',
            'invoiced_minor' => 'integer',
            'odometer' => 'integer',
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
            'invoiced_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WorkOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(WorkOrderLine::class);
    }

    /**
     * @return HasMany<WorkPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(WorkPayment::class);
    }
}
