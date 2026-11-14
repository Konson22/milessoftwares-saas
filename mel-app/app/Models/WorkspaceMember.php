<?php

namespace App\Models;

use App\Models\Concerns\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Someone who has opened M&E in this organization (App\Http\Middleware\
 * RememberMember). Being here grants nothing; it only makes the person
 * selectable as a manager, a responsible person or for programme access.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property CarbonImmutable $last_seen_at
 * @property-read User $user
 */
class WorkspaceMember extends Model
{
    use TenantModel;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Validation: the id is a member of the current organization.
     */
    public static function rule(): Exists
    {
        return Rule::exists('workspace_members', 'user_id')
            ->where('organization_id', app(CurrentTenant::class)->require()->id);
    }

    /**
     * People of the organization, for pickers: [{value, label}].
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_values(User::query()
            ->whereIn('id', self::query()->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => ['value' => $user->id, 'label' => "{$user->name} ({$user->email})"])
            ->values()
            ->all());
    }

    /**
     * @param  Builder<WorkspaceMember>  $query
     * @return Builder<WorkspaceMember>
     */
    public function scopeOfUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
