<?php

namespace App\Modules\Retail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\ItemPacks;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPack;
use App\Modules\Retail\Models\Product;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The shop's product catalogue: shared by every branch.
 */
class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $category = $request->string('category')->toString();
        $showInactive = $request->boolean('inactive');

        $products = Product::query()
            ->search($search)
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->when(! $showInactive, fn ($query) => $query->where('active', true))
            ->orderBy('name')
            ->limit(200)
            ->get();
        $lastCosts = Batch::lastUnitCosts($products->modelKeys());

        return Inertia::render('retail/products/index', [
            'products' => $products->map(fn (Product $product) => [
                ...$this->summary($product),
                'lastUnitCostMinor' => $lastCosts[$product->id] ?? null,
            ]),
            'filters' => ['search' => $search, 'category' => $category, 'inactive' => $showInactive],
            'categories' => $this->categories(),
            'canManage' => $request->user()?->can('retail.products.manage') ?? false,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('retail/products/form', [
            'product' => null,
            'categories' => $this->categories(),
        ]);
    }

    public function store(Request $request, ItemPacks $packs): RedirectResponse
    {
        $product = new Product;
        $this->save($request, $product, $packs);

        return to_route('retail.products.index')->with('success', "{$product->name} added.");
    }

    public function edit(Product $product): Response
    {
        return Inertia::render('retail/products/form', [
            'product' => [
                ...$this->summary($product),
                'sellPrice' => Money::toMajor($product->sell_price_minor),
                'lastUnitCostMinor' => Batch::lastUnitCosts([$product->id])[$product->id] ?? null,
                // Expiry tracking is fixed once stock carries (or lacks) dates.
                'hasStock' => Batch::query()->where('item_id', $product->id)->exists(),
                'packs' => $product->packs->map(fn (ItemPack $pack) => [
                    'name' => $pack->name,
                    'units' => $pack->units,
                    'sellPrice' => $pack->sell_price_minor === null ? '' : Money::toMajor($pack->sell_price_minor),
                ])->values(),
            ],
            'categories' => $this->categories(),
        ]);
    }

    public function update(Request $request, Product $product, ItemPacks $packs): RedirectResponse
    {
        $this->save($request, $product, $packs);

        return to_route('retail.products.index')->with('success', "{$product->name} saved.");
    }

    /**
     * The product and the packs it is bought and sold in, together.
     */
    private function save(Request $request, Product $product, ItemPacks $packs): void
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:32'],
            'category' => ['nullable', 'string', 'max:64'],
            // One barcode, one item, across the whole catalogue (medicines too).
            'barcode' => [
                'nullable', 'string', 'max:64',
                Rule::unique('items', 'barcode')
                    ->where('organization_id', $product->organization_id ?? app(CurrentTenant::class)->id())
                    ->ignore($product->id),
            ],
            'tracks_expiry' => ['boolean'],
            'tracks_serials' => ['boolean'],
            'sell_price' => ['required', 'string', Money::rule()],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:1000000'],
            'active' => ['boolean'],
            'packs' => ['array', 'max:10'],
            'packs.*.name' => ['required', 'string', 'max:32'],
            'packs.*.units' => ['required', 'integer', 'min:2', 'max:100000'],
            'packs.*.sell_price' => ['nullable', 'string', Money::rule()],
        ]);

        $tracksExpiry = (bool) ($validated['tracks_expiry'] ?? false);
        if ($product->exists && $tracksExpiry !== $product->tracks_expiry && Batch::query()->where('item_id', $product->id)->exists()) {
            throw ValidationException::withMessages([
                'tracks_expiry' => 'This product already has stock '.($product->tracks_expiry ? 'with' : 'without').' expiry dates; it cannot change.',
            ]);
        }

        DB::transaction(function () use ($product, $validated, $tracksExpiry, $packs) {
            $product->fill([
                'name' => trim($validated['name']),
                'unit' => trim($validated['unit']),
                'barcode' => ($barcode = trim((string) ($validated['barcode'] ?? ''))) === '' ? null : $barcode,
                // One spelling per category, whoever typed it first.
                'category' => $this->category($validated['category'] ?? null),
                'tracks_expiry' => $tracksExpiry,
                // Turned on with stock on the shelf: record its serials before selling it.
                'tracks_serials' => (bool) ($validated['tracks_serials'] ?? false),
                'sell_price_minor' => Money::toMinor($validated['sell_price']),
                'reorder_level' => (int) $validated['reorder_level'],
                'active' => (bool) ($validated['active'] ?? true),
            ])->save();

            $packs->sync($product, array_values(array_map(fn (array $pack) => [
                'name' => (string) $pack['name'],
                'units' => (int) $pack['units'],
                'sell_price_minor' => isset($pack['sell_price']) && $pack['sell_price'] !== '' ? Money::toMinor($pack['sell_price']) : null,
            ], $validated['packs'] ?? [])));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'unit' => $product->unit,
            'barcode' => $product->barcode,
            'category' => $product->category,
            'tracksExpiry' => $product->tracks_expiry,
            'tracksSerials' => $product->tracks_serials,
            'sellPriceMinor' => $product->sell_price_minor,
            'reorderLevel' => $product->reorder_level,
            'active' => $product->active,
        ];
    }

    /**
     * Categories in use by products.
     *
     * @return list<string>
     */
    private function categories(): array
    {
        return array_values(Product::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->map(fn ($category) => (string) $category)->all());
    }

    private function category(?string $typed): ?string
    {
        $typed = trim((string) $typed);
        if ($typed === '') {
            return null;
        }

        return Item::query()->whereNotNull('category')->whereRaw('LOWER(category) = ?', [mb_strtolower($typed)])->value('category') ?? $typed;
    }
}
