<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPack;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\PriceListPrice;
use App\Support\Industry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a customer pays for an item on a quotation. A price list prices an
 * item's unit, its packs, or both:
 * - a pack the list prices sells at that price;
 * - a pack it does not, at the list's unit price times the pack's units;
 * - an item the list does not price (or no list, or a list switched off),
 *   at the branch's shelf price (ShelfPrices) when a branch is named, the
 *   shop's own price (the pack's, or the item's) otherwise.
 */
class PriceLists
{
    public function __construct(
        private readonly Industry $industry,
        private readonly ShelfPrices $shelf,
    ) {}

    public function price(?PriceList $list, Item $item, ?ItemPack $pack, ?string $branchId = null): int
    {
        return $this->shelf->listed($list, $item, $pack)
            ?? ($branchId === null
                ? ($pack === null ? $item->sell_price_minor : $pack->sellPrice($item))
                : $this->shelf->price($branchId, $item, $pack));
    }

    /**
     * Sets these prices on the list; a line without a price takes that price
     * off it (the item then sells at the shop's own price).
     *
     * @param  list<array{item_id: string, pack_id?: string|null, price_minor: int|null}>  $prices
     *
     * @throws ValidationException
     */
    public function set(PriceList $list, array $prices): void
    {
        DB::transaction(function () use ($list, $prices) {
            foreach ($prices as $index => $line) {
                $item = Item::query()->find($line['item_id']);
                if ($item === null || ! $this->industry->handles($item->kind)) {
                    throw ValidationException::withMessages(["prices.{$index}.item_id" => 'This item is not sold here.']);
                }

                $packId = ($line['pack_id'] ?? '') === '' ? null : $line['pack_id'];
                if ($packId !== null && ! ItemPack::query()->where('item_id', $item->id)->whereKey($packId)->exists()) {
                    throw ValidationException::withMessages(["prices.{$index}.pack_id" => 'Not a pack of this item.']);
                }

                $existing = PriceListPrice::query()
                    ->where('price_list_id', $list->id)
                    ->where('item_id', $item->id)
                    ->where('item_pack_id', $packId)
                    ->first();

                if ($line['price_minor'] === null) {
                    $existing?->delete();

                    continue;
                }

                if ($existing === null) {
                    $existing = new PriceListPrice(['item_id' => $item->id, 'item_pack_id' => $packId]);
                    $existing->price_list_id = $list->id;
                }
                $existing->price_minor = $line['price_minor'];
                $existing->save();
            }
        });
    }
}
