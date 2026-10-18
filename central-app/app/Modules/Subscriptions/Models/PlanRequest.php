<?php

namespace App\Modules\Subscriptions\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An owner asking for a plan (subscribe) or to end a subscription (cancel).
 * Nothing changes until staff approve it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $type subscribe|cancel
 * @property string|null $plan_id
 * @property string|null $subscription_id
 * @property string $status pending|approved|declined|withdrawn
 * @property string|null $note
 * @property string|null $requested_by_user_id
 * @property string|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $created_at
 * @property-read Plan|null $plan
 * @property-read Subscription|null $subscription
 * @property-read User|null $requester
 */
class PlanRequest extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
