<?php

namespace Database\Factories;

use App\Modules\Sales\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An account customer of the shop. Create inside a tenant.
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application' => 'retail',
            'number' => 'C-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'name' => fake()->unique()->company(),
            'phone' => fake()->phoneNumber(),
            'credit_limit_minor' => 100_000,
            'payment_terms_days' => 30,
            'active' => true,
        ];
    }
}
