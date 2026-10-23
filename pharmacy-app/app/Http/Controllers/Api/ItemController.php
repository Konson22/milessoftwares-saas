<?php

namespace App\Http\Controllers\Api;

use App\Modules\Inventory\Enums\StorageCondition;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPack;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Sales\Actions\Promotions;
use App\Modules\Sales\Actions\ShelfPrices;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What the counter can sell, as the web counter lists it: active medicines
 * with today's shelf price in this branch (ShelfPrices), packs, and what
 * is sellable in this branch (unexpired, not held back), soonest expiry
 * first.
 */
class ItemController extends ApiController
{
    private const LIMIT = 50;

    /**
     * GET api/v1/items?search=: by name, generic name or barcode. Without a
     * search, those in stock first.
     */
    public function index(Request $request): JsonResponse
    {
        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';

        $items = Item::query()
            ->where('active', true)
            ->ofKind($this->industry->itemKinds())
            ->search($search)
            ->when($search === '', fn ($query) => $query->whereIn('id', Batch::query()
                ->select('item_id')
                ->where('branch_id', $this->branch->id())
                ->where('quantity_on_hand', '>', 0)))
            ->with('packs')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        return response()->json(['data' => $this->present($items)]);
    }

    /**
     * GET api/v1/items/lookup?code=: the item with this barcode, as a
     * scanner reads it.
     */
    public function lookup(Request $request): JsonResponse
    {
        $code = is_string($request->query('code')) ? trim($request->query('code')) : '';
        abort_if($code === '', 404);

        $items = Item::query()
            ->where('active', true)
            ->ofKind($this->industry->itemKinds())
            ->where('barcode', $code)
            ->with('packs')
            ->limit(1)
            ->get();
        abort_if($items->isEmpty(), 404, 'Nothing sold here has this barcode.');

        return response()->json(['data' => $this->present($items)[0]]);
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return list<array<string, mixed>>
     */
    private function present(Collection $items): array
    {
        $ids = $items->modelKeys();
        $today = CarbonImmutable::today();

        $stock = Batch::query()
            ->where('branch_id', $this->branch->id())
            ->whereIn('item_id', $ids)
            ->where('quantity_on_hand', '>', 0)
            ->where('status', Batch::AVAILABLE)
            ->unexpired($today)
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(quantity_on_hand) AS quantity, MIN(expires_on) AS expires_on')
            ->get()
            ->keyBy('item_id');
        $medicines = Medicine::query()->whereIn('id', $ids)->get()->keyBy('id');
        $shelf = app(ShelfPrices::class);
        $promotions = app(Promotions::class);

        return array_values($items->map(function (Item $item) use ($stock, $medicines, $shelf, $promotions) {
            $medicine = $medicines->get($item->id);
            $promotion = $promotions->best($item, $this->branch->id());
            $sellable = $stock->get($item->id);
            $expires = $sellable?->getAttribute('expires_on');

            return [
                'id' => $item->id,
                'name' => $item->name,
                'generic_name' => $item->alias,
                'strength' => $medicine?->strength,
                'form' => $medicine?->form->value,
                'unit' => $item->unit,
                'barcode' => $item->barcode,
                'category' => $item->category,
                'price_minor' => $shelf->price($this->branch->id(), $item),
                // Taken off by itself at the till today.
                'promotion' => $promotion === null ? null : ['name' => $promotion->name, 'percent_off' => $promotion->percent_off],
                'available' => (int) $sellable?->getAttribute('quantity'),
                'nearest_expiry' => $expires === null ? null : CarbonImmutable::parse((string) $expires)->toDateString(),
                'restricted' => $item->restricted,
                'controlled' => $item->controlled,
                // Each unit sold names its serial number (items[].serials).
                'tracks_serials' => $item->tracks_serials,
                // How it must be kept, when that matters at the counter.
                'storage' => $item->storage === StorageCondition::Room ? null : $item->storage->short(),
                'packs' => array_values($item->packs->map(fn (ItemPack $pack) => [
                    'id' => $pack->id,
                    'name' => $pack->name,
                    'units' => $pack->units,
                    'price_minor' => $shelf->price($this->branch->id(), $item, $pack),
                ])->all()),
            ];
        })->all());
    }
}
