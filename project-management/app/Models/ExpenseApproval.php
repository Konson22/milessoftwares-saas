<?php

namespace App\Models;

use App\Access\ProjectBound;
use App\Access\VisibleThroughProject;
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
class ExpenseApproval extends Model implements ProjectBound
{
    use BelongsToOrganization;
    use HasUuids;
    use VisibleThroughProject;

    public const UPDATED_AT = null;

    protected $guarded = ['organization_id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Seen only with the project its Expense belongs to (App\Access\VisibleThroughProject).
     *
     * @return array{0: string, 1: class-string<Model>}
     */
    public function visibleThrough(): array
    {
        return ['expense_id', Expense::class];
    }
}
