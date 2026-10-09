<?php

namespace Database\Factories;

use App\Modules\Organizations\Enums\OrganizationStatus;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationSlug;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates an active organization with a unique primary slug.
 *
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'legal_name' => $name.' Ltd',
            'display_name' => $name,
            'business_type' => 'retail',
            'contact_email' => fake()->companyEmail(),
            'country_code' => 'SS',
            'default_currency' => 'SSP',
            'timezone' => 'Africa/Juba',
            'status' => OrganizationStatus::Active,
            'activated_at' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization): void {
            $slug = new OrganizationSlug([
                'slug' => 'org'.Str::lower(Str::random(10)),
                'is_primary' => true,
            ]);
            $slug->organization()->associate($organization)->save();
        });
    }

    /**
     * Runs after configure()'s callback, renaming the generated primary slug.
     */
    public function withSlug(string $slug): static
    {
        return $this->afterCreating(function (Organization $organization) use ($slug): void {
            $organization->slugs()->where('is_primary', true)->update(['slug' => $slug]);
        });
    }

    public function status(OrganizationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
