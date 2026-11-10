<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Miles\PlatformClient\Tenancy\BelongsToTenant;

class StoreMovement extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const RECEIVE = 'receive';

    public const ISSUE = 'issue';

    public const ADJUST = 'adjust';

    public const COUNT = 'count';

    public const TRANSFER_OUT = 'transfer_out';

    public const TRANSFER_IN = 'transfer_in';

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer',
            'unit_cost_minor' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
