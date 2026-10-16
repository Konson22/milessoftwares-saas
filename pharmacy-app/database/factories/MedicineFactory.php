<?php

namespace Database\Factories;

use App\Modules\Pharmacy\Enums\MedicineForm;
use App\Modules\Pharmacy\Models\Medicine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create inside a tenant: $this->asTenant('acme', fn () => Medicine::factory()->create()).
 *
 * @extends Factory<Medicine>
 */
class MedicineFactory extends Factory
{
    protected $model = Medicine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['Amoxicillin', 'Paracetamol', 'Ibuprofen', 'Metformin', 'Omeprazole', 'Ciprofloxacin', 'Artemether', 'Salbutamol', 'Cetirizine', 'Diclofenac']).' '.fake()->unique()->numberBetween(1, 9999);

        return [
            'name' => $name,
            'generic_name' => strtolower($name),
            'form' => MedicineForm::Tablet,
            'strength' => '500 mg',
            'unit' => 'tablet',
            'prescription_only' => false,
            'controlled' => false,
            'sell_price_minor' => 250,
            'reorder_level' => 20,
            'active' => true,
        ];
    }

    public function controlled(): static
    {
        return $this->state(['controlled' => true, 'prescription_only' => true]);
    }
}
