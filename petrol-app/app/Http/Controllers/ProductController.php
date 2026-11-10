<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\FuelProduct;
use App\Petrol\Permissions;
use App\Petrol\StationSetup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        return Inertia::render('products/index', [
            'products' => FuelProduct::query()->orderBy('name')->get()->map(fn (FuelProduct $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'code' => $product->code,
                'active' => $product->active,
            ]),
            'canManage' => $this->canHere(Permissions::PRODUCTS_MANAGE),
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
