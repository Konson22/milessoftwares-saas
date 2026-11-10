<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\Store;
use App\Models\StoreBalance;
use App\Models\StoreProduct;
use App\Petrol\Permissions;
use App\Petrol\Stores;
use App\Support\Money;
use App\Support\Volume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        abort_unless($this->station()->runs('store'), 404);
        $stores = Store::query()->where('branch_id', $this->branchId())->orderBy('code')->get();

        return Inertia::render('stores/index', [
            'stores' => $stores,
            'products' => StoreProduct::query()->where('active', true)->orderBy('name')->get(),
            'balances' => StoreBalance::query()->whereIn('store_id', $stores->pluck('id'))->get(),
            'canManage' => $this->canHere(Permissions::STORE_MANAGE),
            'canMove' => $this->canHere(Permissions::STORE_MOVE),
        ]);
    }

    public function saveStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        Store::query()->updateOrCreate(
            ['branch_id' => $this->branchId(), 'code' => strtoupper(trim($validated['code']))],
            ['name' => trim($validated['name']), 'active' => true],
        );

        return back()->with('success', 'Store saved.');
    }

    public function saveProduct(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
            'unit' => ['required', 'string', 'max:16'],
            'category' => ['nullable', 'string', 'max:64'],
            'reorder' => ['nullable', Volume::rule()],
        ]);
        StoreProduct::query()->updateOrCreate(
            ['code' => strtoupper(trim($validated['code']))],
            [
                'name' => trim($validated['name']),
                'unit' => trim($validated['unit']),
                'category' => $this->text($validated['category'] ?? null),
                'reorder_milli' => isset($validated['reorder']) && $validated['reorder'] !== '' ? Volume::toMilli($validated['reorder']) : null,
                'active' => true,
            ],
        );

        return back()->with('success', 'Product saved.');
    }

    public function receive(Request $request, Stores $stores): RedirectResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', Volume::rule()],
            'cost' => ['required', Money::rule()],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $store = Store::query()->whereKey($validated['store_id'])->where('branch_id', $this->branchId())->firstOrFail();
        $product = StoreProduct::query()->whereKey($validated['product_id'])->firstOrFail();
        $stores->receive($store, $product, Volume::toMilli($validated['quantity']), Money::toMinor($validated['cost']), $this->member($request), $validated['idempotency_key']);

        return back()->with('success', 'Stock received.');
    }

    public function issue(Request $request, Stores $stores): RedirectResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', Volume::rule()],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        $store = Store::query()->whereKey($validated['store_id'])->where('branch_id', $this->branchId())->firstOrFail();
        $product = StoreProduct::query()->whereKey($validated['product_id'])->firstOrFail();
        $stores->issue($store, $product, Volume::toMilli($validated['quantity']), $this->member($request), $validated['idempotency_key'] ?? (string) Str::uuid());

        return back()->with('success', 'Stock issued.');
    }

    public function adjust(Request $request, Stores $stores): RedirectResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'direction' => ['required', 'in:increase,decrease'],
            'quantity' => ['required', Volume::rule()],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $store = Store::query()->whereKey($validated['store_id'])->where('branch_id', $this->branchId())->firstOrFail();
        $product = StoreProduct::query()->whereKey($validated['product_id'])->firstOrFail();
        $milli = Volume::toMilli($validated['quantity']);
        $stores->adjust($store, $product, $validated['direction'] === 'decrease' ? -$milli : $milli, $this->member($request), $validated['idempotency_key']);

        return back()->with('success', 'Stock adjusted.');
    }

    public function count(Request $request, Stores $stores): RedirectResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', Volume::rule()],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $store = Store::query()->whereKey($validated['store_id'])->where('branch_id', $this->branchId())->firstOrFail();
        $product = StoreProduct::query()->whereKey($validated['product_id'])->firstOrFail();
        $stores->count($store, $product, Volume::toMilli($validated['quantity']), $this->member($request), $validated['idempotency_key']);

        return back()->with('success', 'Stock count recorded.');
    }
}
