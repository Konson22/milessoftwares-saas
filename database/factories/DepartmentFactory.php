<?php

namespace Database\Factories;

use App\Modules\Organizations\Models\Organization;
use App\Modules\Structure\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'branch_id' => null,
            'name' => fake()->randomElement(['Finance', 'Human Resources', 'Procurement', 'Sales', 'Operations']),
            'code' => strtoupper(fake()->unique()->bothify('DEP-###??')),
            'status' => 'active',
        ];
    }
}
