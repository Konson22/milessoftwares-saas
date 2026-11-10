<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

/**
 * Local settings for one central branch used as a petrol station.
 * The branch itself stays in the platform.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $code
 * @property string $collection_mode
 * @property string $inventory_mode
 * @property int $low_stock_percent
 * @property string|null $hours
 * @property string|null $notes
 * @property bool $active
 * @property bool $is_main
 * @property bool $shared_pumps
 * @property array<string, bool>|null $operations
 */
class Station extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const ATTENDANT = 'attendant';

    public const CASHIER = 'cashier';

    public const BOTH = 'both';

    /** Each posted sale takes litres from the tank. Meters are checked, not posted again. */
    public const SALES = 'sales';

    /** Closing meters are the stock deduction. Sales record money and do not take litres again. */
    public const METERS = 'meters';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'low_stock_percent' => 'integer',
            'active' => 'boolean',
            'is_main' => 'boolean',
            'shared_pumps' => 'boolean',
            'dispense_during_receiving' => 'boolean',
            'water_alert_mm' => 'integer',
            'calibration_interval_days' => 'integer',
            'operations' => 'array',
        ];
    }

    public function runs(string $area): bool
    {
        $operations = $this->operations;

        return ! is_array($operations) || ! array_key_exists($area, $operations) || (bool) $operations[$area];
    }
}
