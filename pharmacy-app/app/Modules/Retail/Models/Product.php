<?php

namespace App\Modules\Retail\Models;

use App\Modules\Inventory\Models\Item;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Goods on a shop's shelves: an Inventory item of kind "product". A product
 * has nothing beyond what every item has (name, barcode, unit, category,
 * price, packs, reorder level, whether it tracks expiry), so it is the item
 * itself, limited to its kind. Queries and route bindings never see another
 * industry's items.
 */
#[UseFactory(ProductFactory::class)]
class Product extends Item
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    public const KIND = 'product';

    protected $table = 'items';

    protected $attributes = ['storage' => 'room', 'tracks_expiry' => false, 'kind' => self::KIND];

    protected static function booted(): void
    {
        static::addGlobalScope('kind', fn (Builder $query) => $query->where('kind', self::KIND));

        // Never anything but a product, whatever was filled in.
        static::saving(fn (self $product) => $product->kind = self::KIND);
    }

    public function getForeignKey(): string
    {
        return 'item_id';
    }
}
