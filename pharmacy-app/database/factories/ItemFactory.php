<?php

namespace Database\Factories;

use App\Modules\Inventory\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An item no industry module keeps details for: for Inventory, Procurement
 * and Sales tests. Create inside a tenant, like MedicineFactory.
 *
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'test',
            'name' => 'Item '.fake()->unique()->numberBetween(1, 99999),
            'unit' => 'piece',
            'sell_price_minor' => 250,
            'reorder_level' => 20,
            'active' => true,
        ];
    }
}
