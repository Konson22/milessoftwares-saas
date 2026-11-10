<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * A proving-can test of one nozzle. It is not a customer sale.
 *
 * @property string $id
 * @property string $branch_id
 * @property string $pump_id
 * @property string $nozzle_id
 * @property CarbonImmutable $calibrated_on
 * @property string $technician
 * @property string|null $reference
 * @property string|null $certificate_note
 * @property CarbonImmutable|null $next_due
 * @property string $created_by
 * @property int $test_milli
 * @property int $indicated_milli
 * @property int $measured_milli
 * @property int $difference_milli
 * @property int $error_bps
 * @property string $outcome
 */
class PumpCalibration extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const PASS = 'pass';

    public const FAIL = 'fail';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'calibrated_on' => 'date',
            'next_due' => 'date',
            'test_milli' => 'integer',
            'indicated_milli' => 'integer',
            'measured_milli' => 'integer',
            'difference_milli' => 'integer',
            'error_bps' => 'integer',
        ];
    }
}
