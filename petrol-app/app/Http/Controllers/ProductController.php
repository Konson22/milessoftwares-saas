<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\FuelPrice;
use App\Models\FuelProduct;
use App\Models\Nozzle;
use App\Models\Tank;
use App\Petrol\Permissions;
use App\Petrol\StationSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    use WorksInBranch;

    /**
     * Every grade, with what it means at this station: its selling price
     * now, and the tanks and nozzles that hold and sell it.
     */
    public function index(): Response
    {
        $branchId = $this->branchId();
        $prices = FuelPrice::query()->where('branch_id', $branchId)->whereNull('effective_to')->get()->keyBy('fuel_product_id');
        $tanks = Tank::query()->where('branch_id', $branchId)->where('status', Tank::ACTIVE)
            ->selectRaw('fuel_product_id, count(*) as total')->groupBy('fuel_product_id')->pluck('total', 'fuel_product_id');
        $nozzles = Nozzle::query()->where('branch_id', $branchId)->where('status', Nozzle::ACTIVE)
            ->selectRaw('fuel_product_id, count(*) as total')->groupBy('fuel_product_id')->pluck('total', 'fuel_product_id');

        return Inertia::render('products/index', [
            'products' => FuelProduct::query()->orderByDesc('active')->orderBy('name')->get()->map(fn (FuelProduct $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'code' => $product->code,
                'active' => $product->active,
                'priceMinor' => $prices->get($product->id)?->selling_price_minor,
                'priceCurrency' => $prices->get($product->id)?->currency,
                'tanks' => (int) ($tanks[$product->id] ?? 0),
                'nozzles' => (int) ($nozzles[$product->id] ?? 0),
            ]),
            'canManage' => $this->canHere(Permissions::PRODUCTS_MANAGE),
            'canPrice' => $this->canHere(Permissions::PRICES_MANAGE),
        ]);
    }

    public function store(Request $request, StationSetup $setup): RedirectResponse
    {
        $validated = $this->input($request);
        $product = $setup->saveProduct($validated['name'], $validated['code'], (bool) ($validated['active'] ?? true), null, $this->member($request));

        return back()->with('success', "{$product->name} added.");
    }

    public function update(Request $request, FuelProduct $product, StationSetup $setup): RedirectResponse
    {
        $validated = $this->input($request);
        $setup->saveProduct($validated['name'], $validated['code'], (bool) ($validated['active'] ?? false), $product, $this->member($request));

        return back()->with('success', "{$product->name} saved.");
    }

    /**
     * @return array{name: string, code: string, active?: bool}
     */
    private function input(Request $request): array
    {
        /** @var array{name: string, code: string, active?: bool} $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
            'active' => ['boolean'],
        ]);

        return $validated;
    }
}
