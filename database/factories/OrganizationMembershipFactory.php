<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Memberships\Enums\MembershipStatus;
use App\Modules\Memberships\Models\OrganizationMembership;
use App\Modules\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationMembership>
 */
class OrganizationMembershipFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => MembershipStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }
}
