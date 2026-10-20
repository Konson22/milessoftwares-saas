<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * One asset's depreciation in a run.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $depreciation_run_id
 * @property string $fixed_asset_id
 * @property int $amount_minor
 * @property-read DepreciationRun $run
 */
class DepreciationLine extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    /**
     * @return BelongsTo<DepreciationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DepreciationRun::class, 'depreciation_run_id');
    }
}
