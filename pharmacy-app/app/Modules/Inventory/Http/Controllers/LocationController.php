<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Actions\Locations;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\BatchLocation;
use App\Modules\Inventory\Models\StockLocation;
use App\Support\CurrentBranch;
use App\Support\Industry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * The places stock is kept in the branch being worked in, what each holds
 * (for counting a shelf), and moving stock between them. The places are the
 * branch's, shared by the industries working there; what is on them is
 * shown to each industry for its own items only.
 */
class LocationController extends Controller
{
    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly Industry $industry,
    ) {}

    public function index(): Response
    {
        $totals = BatchLocation::query()
            ->whereIn('stock_location_id', StockLocation::query()->where('branch_id', $this->branch->id())->select('id'))
            ->whereIn('batch_id', $this->batches()->select('id'))
            ->where('quantity', '>', 0)
            ->groupBy('stock_location_id')
            ->selectRaw('stock_location_id, COUNT(*) AS batches, SUM(quantity) AS units')
            ->toBase()
            ->get()
            ->keyBy('stock_location_id');

        $notPutAway = (int) $this->batches()->sum('quantity_on_hand') - (int) $totals->sum('units');

        return Inertia::render($this->industry->page('locations/index'), [
            'locations' => StockLocation::query()
                ->where('branch_id', $this->branch->id())
                ->orderByDesc('active')
                ->orderBy('name')
                ->get()
                ->map(fn (StockLocation $location) => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'active' => $location->active,
                    'batches' => (int) ($totals->get($location->id)->batches ?? 0),
                    'units' => (int) ($totals->get($location->id)->units ?? 0),
                ]),
            'notPutAway' => $notPutAway,
            'canManage' => $this->canManage(),
        ]);
    }

    public function show(StockLocation $location): Response
    {
        $this->ensureHere($location);

        return Inertia::render($this->industry->page('locations/show'), [
            'location' => ['id' => $location->id, 'name' => $location->name, 'active' => $location->active],
            'contents' => BatchLocation::query()
                ->where('stock_location_id', $location->id)
                ->whereIn('batch_id', $this->batches()->select('id'))
                ->where('quantity', '>', 0)
                ->with('batch.item')
                ->get()
                ->sortBy(fn (BatchLocation $placement) => [$placement->batch->item->name, $placement->batch->expires_on?->toDateString()])
                ->values()
                ->map(fn (BatchLocation $placement) => [
                    'id' => $placement->id,
                    'itemId' => $placement->batch->item_id,
                    'item' => $placement->batch->item->name,
                    'batchNumber' => $placement->batch->batch_number,
                    'expiresOn' => $placement->batch->expires_on?->toDateString(),
                    'status' => $placement->batch->status,
                    'quantity' => $placement->quantity,
                ]),
            'canManage' => $this->canManage(),
        ]);
    }

    public function store(Request $request, Locations $locations): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:64']]);

        $location = $locations->create($this->branch->id(), $validated['name']);

        return back()->with('success', "{$location->name} added.");
    }

    public function update(Request $request, StockLocation $location, Locations $locations): RedirectResponse
    {
        $this->ensureHere($location);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'active' => ['required', 'boolean'],
        ]);

        $locations->update($location, $validated['name'], (bool) $validated['active']);

        return back()->with('success', "{$location->name} saved.");
    }

    public function move(Request $request, Batch $batch, Locations $locations): RedirectResponse
    {
        // Another industry's batch at this branch looks unknown too.
        abort_unless($batch->branch_id === $this->branch->id() && $this->industry->handles($batch->item->kind), 404);
        $validated = $request->validate([
            'from_location_id' => ['nullable', 'uuid'],
            'to_location_id' => ['nullable', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $find = fn (?string $id) => $id === null ? null : StockLocation::query()->where('branch_id', $this->branch->id())->whereKey($id)->firstOrFail();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $locations->move($batch, $find($validated['from_location_id'] ?? null), $find($validated['to_location_id'] ?? null), (int) $validated['quantity'], $user);

        return back()->with('success', "Moved {$validated['quantity']} of batch {$batch->batch_number}.");
    }

    /**
     * This branch's batches of this industry's items.
     *
     * @return Builder<Batch>
     */
    private function batches(): Builder
    {
        return Batch::query()
            ->where('branch_id', $this->branch->id())
            ->whereHas('item', fn ($item) => $item->ofKind($this->industry->itemKinds()));
    }

    private function canManage(): bool
    {
        return app(CurrentAccess::class)->get()?->allowsIn($this->industry->permission('stock.adjust'), branchId: $this->branch->id()) ?? false;
    }

    private function ensureHere(StockLocation $location): void
    {
        abort_unless($location->branch_id === $this->branch->id(), 404);
    }
}
