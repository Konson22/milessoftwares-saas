<?php

namespace App\Procurement\Models;

use App\Models\User;
use App\Procurement\Models\Concerns\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A procurement plan: what an organization (or a department) expects to buy
 * in a financial year. Draft → pending_approval → approved; revised back to
 * draft with a new revision; closed at year end.
 *
 * @property string $id
 * @property string $number
 * @property string $title
 * @property string $fiscal_year
 * @property string|null $department_id
 * @property string|null $department_name
 * @property string $status
 * @property int $revision
 * @property string $created_by
 */
class Plan extends Model
{
    use TenantModel;

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'closed'];

    protected $table = 'procurement_plans';

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'submitted_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime'];
    }

    /** @return HasMany<PlanItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PlanItem::class)->orderBy('created_at');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
