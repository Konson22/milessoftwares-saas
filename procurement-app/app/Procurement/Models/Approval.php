<?php

namespace App\Procurement\Models;

use App\Procurement\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable|null $decided_at
 */
class Approval extends Model
{
    use TenantModel;

    protected $table = 'approvals';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime', 'round' => 'integer', 'step' => 'integer'];
    }
}
