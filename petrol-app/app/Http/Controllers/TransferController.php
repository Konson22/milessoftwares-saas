<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\FuelTransfer;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\StoreTransfer;
use App\Models\Tank;
use App\Petrol\FuelMoves;
use App\Petrol\Stores;
use App\Support\Volume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransferController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        $branch = $this->branchId();
        $mine = Store::query()->where('branch_id', $branch)->pluck('id')->all();

        return Inertia::render('transfers/index', [
            'branchId' => $branch,
            'fuel' => FuelTransfer::query()
                ->where(fn ($query) => $query->where('branch_id', $branch)->orWhere('destination_branch_id', $branch))
                ->latest()->limit(40)->get()
                ->map(fn (FuelTransfer $transfer) => $transfer->toArray() + [
                    'outgoing' => $transfer->branch_id === $branch,
                    'incoming' => $transfer->destination_branch_id === $branch,
                ]),
            'store' => StoreTransfer::query()
                ->where(fn ($query) => $query->whereIn('source_store_id', $mine)->orWhereIn('destination_store_id', $mine))
                ->latest()->limit(40)->get()
                ->map(fn (StoreTransfer $transfer) => $transfer->toArray() + [
                    'outgoing' => in_array($transfer->source_store_id, $mine, true),
                    'incoming' => in_array($transfer->destination_store_id, $mine, true),
                ]),
            'tanks' => Tank::query()->where('status', Tank::ACTIVE)->orderBy('code')->get(['id', 'branch_id', 'code', 'name']),
            'stores' => Store::query()->where('active', true)->orderBy('code')->get(['id', 'branch_id', 'code', 'name']),
            'products' => StoreProduct::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function requestFuel(Request $request, FuelMoves $moves): RedirectResponse
    {
        $validated = $request->validate([
            'source_tank_id' => ['required', 'uuid'],
            'destination_tank_id' => ['required', 'uuid'],
            'litres' => ['required', Volume::rule()],
            'transport_ref' => ['nullable', 'string', 'max:255'],
        ]);
        $source = Tank::query()->whereKey($validated['source_tank_id'])->where('branch_id', $this->branchId())->firstOrFail();
        $destination = Tank::query()->whereKey($validated['destination_tank_id'])->firstOrFail();
        $moves->request($source, $destination, Volume::toMilli($validated['litres']), $this->text($validated['transport_ref'] ?? null), $this->member($request));

        return back()->with('success', 'Fuel transfer requested.');
    }

    public function approveFuel(FuelTransfer $transfer, FuelMoves $moves, Request $request): RedirectResponse
    {
        $this->ensureHere($transfer->branch_id);
        $moves->approve($transfer, $this->member($request));

        return back()->with('success', 'Fuel transfer approved.');
    }

    public function dispatchFuel(Request $request, FuelTransfer $transfer, FuelMoves $moves): RedirectResponse
    {
        $this->ensureHere($transfer->branch_id);
        $validated = $request->validate(['litres' => ['required', Volume::rule()]]);
        $moves->dispatch($transfer, Volume::toMilli($validated['litres']), $this->member($request));

        return back()->with('success', 'Fuel left the source tank.');
    }

    public function receiveFuel(Request $request, FuelTransfer $transfer, FuelMoves $moves): RedirectResponse
    {
        $this->ensureHere((string) $transfer->destination_branch_id);
        $validated = $request->validate(['litres' => ['required', Volume::rule()]]);
        $moves->receive($transfer, Volume::toMilli($validated['litres']), $this->member($request));

        return back()->with('success', 'Fuel received. A difference stays on the transfer.');
    }

    public function completeFuel(FuelTransfer $transfer, FuelMoves $moves, Request $request): RedirectResponse
    {
        $this->touch($transfer->branch_id, (string) $transfer->destination_branch_id);
        $moves->complete($transfer, $this->member($request));

        return back()->with('success', 'Fuel transfer completed.');
    }

    public function requestStore(Request $request, Stores $stores): RedirectResponse
    {
        $validated = $request->validate([
            'source_store_id' => ['required', 'uuid'],
            'destination_store_id' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', Volume::rule()],
        ]);
        $source = Store::query()->whereKey($validated['source_store_id'])->where('branch_id', $this->branchId())->firstOrFail();
        $destination = Store::query()->whereKey($validated['destination_store_id'])->firstOrFail();
        $product = StoreProduct::query()->whereKey($validated['product_id'])->where('active', true)->firstOrFail();
        $stores->requestTransfer($source, $destination, $product, Volume::toMilli($validated['quantity']), $this->member($request));

        return back()->with('success', 'Store transfer requested.');
    }

    public function approveStore(StoreTransfer $transfer, Stores $stores, Request $request): RedirectResponse
    {
        $this->ensureHere($this->storeBranch($transfer->source_store_id));
        $stores->approveTransfer($transfer, $this->member($request));

        return back()->with('success', 'Store transfer approved.');
    }

    public function dispatchStore(Request $request, StoreTransfer $transfer, Stores $stores): RedirectResponse
    {
        $this->ensureHere($this->storeBranch($transfer->source_store_id));
        $validated = $request->validate(['quantity' => ['required', Volume::rule()]]);
        $stores->dispatchTransfer($transfer, Volume::toMilli($validated['quantity']), $this->member($request));

        return back()->with('success', 'Store stock left the source.');
    }

    public function receiveStore(Request $request, StoreTransfer $transfer, Stores $stores): RedirectResponse
    {
        $this->ensureHere($this->storeBranch($transfer->destination_store_id));
        $validated = $request->validate(['quantity' => ['required', Volume::rule()]]);
        $stores->receiveTransfer($transfer, Volume::toMilli($validated['quantity']), $this->member($request));

        return back()->with('success', 'Store stock received.');
    }

    public function completeStore(StoreTransfer $transfer, Stores $stores, Request $request): RedirectResponse
    {
        $this->touch($this->storeBranch($transfer->source_store_id), $this->storeBranch($transfer->destination_store_id));
        $stores->completeTransfer($transfer, $this->member($request));

        return back()->with('success', 'Store transfer completed.');
    }

    /**
     * The sending station approves and dispatches, the receiving station
     * receives, and either one closes a received transfer.
     */
    private function touch(string $source, string $destination): void
    {
        abort_unless($this->branchId() === $source || $this->branchId() === $destination, 404);
    }

    private function storeBranch(string $storeId): string
    {
        return (string) Store::query()->whereKey($storeId)->value('branch_id');
    }
}
