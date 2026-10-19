<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPack;
use App\Modules\Sales\Actions\PriceLists;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\PriceListPrice;
use App\Support\Industry;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The organization's price lists for the industry being served, and their
 * prices. Customers buy at their list on quotations (PriceLists).
 */
class PriceListController extends Controller
{
    public function __construct(private readonly Industry $industry) {}

    public function index(): Response
    {
        $lists = PriceList::query()
            ->where('application', $this->industry->code())
            ->withCount('prices')
            ->orderBy('name')
            ->get();
        $customers = Customer::query()
            ->whereIn('price_list_id', $lists->modelKeys())
            ->selectRaw('price_list_id, COUNT(*) AS customers')
            ->groupBy('price_list_id')
            ->pluck('customers', 'price_list_id');

        return Inertia::render($this->industry->page('price-lists/index'), [
            'priceLists' => $lists->map(fn (PriceList $list) => [
                'id' => $list->id,
                'name' => $list->name,
                'active' => $list->active,
                'prices' => (int) $list->getAttribute('prices_count'),
                'customers' => (int) ($customers[$list->id] ?? 0),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255', $this->uniqueName(null)]]);

        $list = new PriceList(['application' => $this->industry->code(), 'name' => trim($validated['name'])]);
        $list->save();

        return to_route($this->industry->route('price-lists.show'), $list)->with('success', "{$list->name} added: set its prices below.");
    }

    /**
     * The list with every item this industry sells, its packs, the shop's
     * own price, and the list's price where it sets one.
     */
    public function show(PriceList $priceList): Response
    {
        $this->ensureOurs($priceList);
        $prices = $priceList->prices()->get()->keyBy(fn (PriceListPrice $price) => $price->item_id.':'.($price->item_pack_id ?? 'unit'));

        $items = Item::query()
            ->where('active', true)
            ->ofKind($this->industry->itemKinds())
            ->with('packs')
            ->orderBy('name')
            ->get();

        return Inertia::render($this->industry->page('price-lists/show'), [
            'priceList' => ['id' => $priceList->id, 'name' => $priceList->name, 'active' => $priceList->active],
            'items' => $items->map(fn (Item $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'category' => $item->category,
                'rows' => [
                    [
                        'packId' => null,
                        'label' => $item->unit,
                        'retailMinor' => $item->sell_price_minor,
                        'priceMinor' => $prices[$item->id.':unit']->price_minor ?? null,
                    ],
                    ...$item->packs->map(fn (ItemPack $pack) => [
                        'packId' => $pack->id,
                        'label' => $pack->label(),
                        'retailMinor' => $pack->sellPrice($item),
                        'priceMinor' => $prices[$item->id.':'.$pack->id]->price_minor ?? null,
                    ]),
                ],
            ]),
        ]);
    }

    public function update(Request $request, PriceList $priceList): RedirectResponse
    {
        $this->ensureOurs($priceList);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', $this->uniqueName($priceList)],
            'active' => ['boolean'],
        ]);

        $priceList->forceFill(['name' => trim($validated['name']), 'active' => (bool) ($validated['active'] ?? true)])->save();

        return back()->with('success', "{$priceList->name} saved.");
    }

    /**
     * Sets prices on the list; an empty price takes the item off it.
     */
    public function updatePrices(Request $request, PriceList $priceList, PriceLists $prices): RedirectResponse
    {
        $this->ensureOurs($priceList);
        $validated = $request->validate([
            'prices' => ['required', 'array', 'max:2000'],
            'prices.*.item_id' => ['required', 'uuid'],
            'prices.*.pack_id' => ['nullable', 'uuid'],
            'prices.*.price' => ['nullable', 'string', Money::rule()],
        ]);

        $prices->set($priceList, array_values(array_map(fn (array $line) => [
            'item_id' => $line['item_id'],
            'pack_id' => $line['pack_id'] ?? null,
            'price_minor' => ($line['price'] ?? '') === '' ? null : Money::toMinor($line['price']),
        ], $validated['prices'])));

        return back()->with('success', "Prices of {$priceList->name} saved.");
    }

    private function uniqueName(?PriceList $list): mixed
    {
        return Rule::unique('price_lists', 'name')
            ->where('organization_id', app(CurrentTenant::class)->id())
            ->where('application', $this->industry->code())
            ->ignore($list?->id);
    }

    /**
     * Another industry's lists look unknown here.
     */
    private function ensureOurs(PriceList $list): void
    {
        abort_unless($list->application === $this->industry->code(), 404);
    }
}
