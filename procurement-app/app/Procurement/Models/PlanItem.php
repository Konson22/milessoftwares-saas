<?php

namespace App\Procurement\Models;

use App\Models\User;
use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $plan_id
 * @property string $description
 * @property string $method
 * @property string $quantity
 * @property int $estimated_base_minor
 * @property-read Plan $plan
 */
class PlanItem extends Model
{
    use TenantModel;

    protected $table = 'procurement_plan_items';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['estimated_base_minor' => 'integer', 'expected_on' => 'immutable_date'];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /** @return HasMany<Requisition, $this> */
    public function requisitions(): HasMany
    {
        return $this->hasMany(Requisition::class);
    }
}
