<?php

namespace App\Mel;

use App\Models\OrganizationMembership;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The organization's active members, for M&E's people pickers (managers,
 * responsible people, programme access, field assignments). Being listed
 * grants nothing: access is the member's permissions and grants.
 */
final class Members
{
    /**
     * Validation: the id is an active member of the current organization.
     */
    public static function rule(): Exists
    {
        return Rule::exists('organization_memberships', 'user_id')
            ->where('organization_id', app(CurrentOrganization::class)->require()->id)
            ->where('status', OrganizationMembership::ACTIVE);
    }

    /**
     * People of the organization, for pickers: [{value, label}].
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $members = OrganizationMembership::query()
            ->where('organization_id', app(CurrentOrganization::class)->require()->id)
            ->where('status', OrganizationMembership::ACTIVE)
            ->select('user_id');

        return array_values(User::query()
            ->whereIn('id', $members)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => ['value' => $user->id, 'label' => "{$user->name} ({$user->email})"])
            ->values()
            ->all());
    }
}
