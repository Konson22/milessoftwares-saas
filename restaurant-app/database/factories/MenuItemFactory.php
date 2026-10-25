<?php

namespace Database\Factories;

use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A dish on a restaurant's menu (drink(): a drink). Create inside a
 * tenant (asTenant).
 *
 * @extends Factory<MenuItem>
 */
class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => MenuItem::DISH,
            'name' => 'Dish '.fake()->unique()->numberBetween(1, 99999),
            'unit' => 'plate',
            'section' => 'Mains',
            'price_minor' => 1500,
            'reorder_level' => 0,
            'active' => true,
        ];
    }

    public function drink(): static
    {
        return $this->state([
            'kind' => MenuItem::DRINK,
            'name' => 'Drink '.fake()->unique()->numberBetween(1, 99999),
            'unit' => 'bottle',
            'section' => 'Drinks',
            'price_minor' => 300,
        ]);
    }
}
