<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPack;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\PriceListPrice;
use App\Support\Industry;

/**
 * What an item costs on the shelf of a branch. A branch may sell at a price
 * list of its own (its price level, in its counter settings); what that
 * list does not price, and every item in a branch without one, sells at
 * the shop's own price. A list prices a pack, or the unit (a pack then
 * costs its units at that price), as on quotations (PriceLists).
 *
 * Lists and their prices are read once per instance: price a whole catalog
 * with one.
 */
class ShelfPrices
{
    /** @var array<string, PriceList|null> by branch */
    private array $lists = [];

    /** @var array<string, array<string, int>> by list: "item:pack" or "item:unit" => price */
    private array $prices = [];

    public function __construct(private readonly Industry $industry) {}

    /**
     * The branch's price level, when it has one that is switched on.
     */
    public function list(string $branchId): ?PriceList
    {
        if (! array_key_exists($branchId, $this->lists)) {
            $id = BranchCounterSettings::forBranch($branchId, $this->industry->code())->price_list_id;
            $this->lists[$branchId] = $id === null ? null : PriceList::query()
                ->where('application', $this->industry->code())
                ->where('active', true)
                ->find($id);
        }

        return $this->lists[$branchId];
    }

    /**
     * The price of one unit, or of one pack when it is named.
     */
    public function price(string $branchId, Item $item, ?ItemPack $pack = null): int
    {
        return $this->listed($this->list($branchId), $item, $pack)
            ?? ($pack === null ? $item->sell_price_minor : $pack->sellPrice($item));
    }

    /**
     * What a list says the unit (or the pack) costs; null when it does not
     * price the item, or the list is switched off.
     */
    public function listed(?PriceList $list, Item $item, ?ItemPack $pack): ?int
    {
        if ($list === null || ! $list->active) {
            return null;
        }

        $prices = $this->prices[$list->id] ??= PriceListPrice::query()
            ->where('price_list_id', $list->id)
            ->get()
            ->mapWithKeys(fn (PriceListPrice $price) => [$price->item_id.':'.($price->item_pack_id ?? 'unit') => $price->price_minor])
            ->all();

        if ($pack !== null && isset($prices["{$item->id}:{$pack->id}"])) {
            return $prices["{$item->id}:{$pack->id}"];
        }
        if (isset($prices["{$item->id}:unit"])) {
            return $prices["{$item->id}:unit"] * ($pack->units ?? 1);
        }

        return null;
    }
}
