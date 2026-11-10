<?php

namespace App\Models;

use App\Enums\CostCategory;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money paid out of a box, with its receipt. An expense from the day it is
 * paid. Voided rather than changed, and only until a top-up has covered it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $fund_id
 * @property CarbonImmutable $paid_on
 * @property int $amount_minor
 * @property string $description
 * @property string|null $payee
 * @property string|null $project_id
 * @property CostCategory|null $category
 * @property string|null $expense_id
 * @property string $receipt_name
 * @property string $receipt_path
 * @property string $receipt_mime
 * @property int $receipt_size
 * @property string|null $top_up_id
 * @property CarbonImmutable|null $voided_at
 * @property string|null $voided_by
 * @property string|null $void_reason
 * @property string $recorded_by
 * @property-read Project|null $project
 * @property-read User $recorder
 * @property-read PettyCashTopUp|null $topUp
 */
class PettyCashPayment extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    protected $guarded = ['organization_id'];

    protected function casts(): array
    {
        return [
            'paid_on' => 'immutable_date',
            'amount_minor' => 'integer',
            'category' => CostCategory::class,
            'receipt_size' => 'integer',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<PettyCashTopUp, $this>
     */
    public function topUp(): BelongsTo
    {
        return $this->belongsTo(PettyCashTopUp::class, 'top_up_id');
    }
}
