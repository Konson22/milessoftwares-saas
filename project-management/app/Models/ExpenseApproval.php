<?php

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's approval of a spending request (App\Actions\Spending).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $expense_id
 * @property string $user_id
 * @property string|null $note
 * @property CarbonImmutable $created_at
 * @property-read User $user
 */
class ExpenseApproval extends Model
{
    use BelongsToOrganization;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['organization_id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
