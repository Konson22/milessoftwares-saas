<?php

namespace Database\Factories;

use App\Modules\Retail\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A shop's product. Create inside a tenant, like MedicineFactory.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Product '.fake()->unique()->numberBetween(1, 99999),
            'unit' => 'piece',
            'barcode' => (string) fake()->unique()->ean13(),
            'sell_price_minor' => 500,
            'reorder_level' => 5,
            'active' => true,
        ];
    }
}
