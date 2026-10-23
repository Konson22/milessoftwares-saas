<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Actions\StockCounts;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountLine;
use App\Support\CurrentBranch;
use App\Support\Industry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * Stock-takes of the branch being worked in, for this industry's items.
 * Counting is blind while the count is open: what the system expects is
 * shown, with the differences, once the count is completed.
 */
class StockCountController extends Controller
{
    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly Industry $industry,
    ) {}

    public function index(): Response
    {
        return Inertia::render($this->industry->page('counts/index'), [
            'counts' => StockCount::query()
                ->where('branch_id', $this->branch->id())
                ->where('application', $this->industry->code())
                ->with(['starter:id,name', 'closer:id,name'])
                ->withCount(['lines', 'lines as counted_count' => fn ($lines) => $lines->whereNotNull('counted')])
                ->latest('started_at')
                ->limit(50)
                ->get()
                ->map(fn (StockCount $count) => [
                    'id' => $count->id,
                    'number' => $count->number,
                    'category' => $count->category,
                    'status' => $count->status,
                    'startedAt' => $count->started_at->toIso8601String(),
                    'startedBy' => $count->starter->name,
                    'closedAt' => $count->closed_at?->toIso8601String(),
                    'closedBy' => $count->closer?->name,
                    'lines' => (int) $count->getAttribute('lines_count'),
                    'counted' => (int) $count->getAttribute('counted_count'),
                ]),
            'categories' => Item::query()
                ->where('active', true)
                ->ofKind($this->industry->itemKinds())
                ->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category'),
            'canManage' => $this->canManage(),
        ]);
    }

    public function store(Request $request, StockCounts $counts): RedirectResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $count = $counts->start($this->branch->id(), $validated['category'] ?? null, $validated['note'] ?? null, $this->user($request));

        return to_route($this->industry->route('counts.show'), $count)->with('success', "Count {$count->number} started: count each line, then complete it.");
    }

    public function show(StockCount $count): Response
    {
        $this->ensureHere($count);
        $count->load(['starter:id,name', 'closer:id,name']);
        $open = $count->isOpen();

        return Inertia::render($this->industry->page('counts/show'), [
            'count' => [
                'id' => $count->id,
                'number' => $count->number,
                'category' => $count->category,
                'note' => $count->note,
                'status' => $count->status,
                'startedAt' => $count->started_at->toIso8601String(),
                'startedBy' => $count->starter->name,
                'closedAt' => $count->closed_at?->toIso8601String(),
                'closedBy' => $count->closer?->name,
            ],
            'lines' => $count->lines()
                ->with(['item:id,name,unit', 'batch:id,batch_number,expires_on', 'counter:id,name'])
                ->get()
                ->sortBy(fn (StockCountLine $line) => [$line->item->name, $line->batch->expires_on?->toDateString(), $line->batch->batch_number])
                ->values()
                ->map(fn (StockCountLine $line) => [
                    'id' => $line->id,
                    'itemId' => $line->item_id,
                    'item' => $line->item->name,
                    'unit' => $line->item->unit,
                    'batchNumber' => $line->batch->batch_number,
                    'expiresOn' => $line->batch->expires_on?->toDateString(),
                    'counted' => $line->counted,
                    'countedBy' => $line->counter?->name,
                    // Blind while open.
                    'expected' => $open ? null : $line->expected,
                    'difference' => $line->difference,
                ]),
            'canManage' => $open && $this->canManage(),
        ]);
    }

    public function update(Request $request, StockCount $count, StockCounts $counts): RedirectResponse
    {
        $this->ensureHere($count);
        $validated = $request->validate([
            'counts' => ['required', 'array', 'max:2000'],
            'counts.*' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $counts->record($count, array_map(fn ($value) => $value === null ? null : (int) $value, $validated['counts']), $this->user($request));

        return back()->with('success', 'Counts saved.');
    }

    public function complete(Request $request, StockCount $count, StockCounts $counts): RedirectResponse
    {
        $this->ensureHere($count);

        $counts->complete($count, $this->user($request));

        return back()->with('success', "Count {$count->number} completed: the differences are posted as count corrections.");
    }

    public function cancel(Request $request, StockCount $count, StockCounts $counts): RedirectResponse
    {
        $this->ensureHere($count);

        $counts->cancel($count, $this->user($request));

        return back()->with('success', "Count {$count->number} cancelled: nothing was changed.");
    }

    private function canManage(): bool
    {
        return app(CurrentAccess::class)->get()?->allowsIn($this->industry->permission('stock.adjust'), branchId: $this->branch->id()) ?? false;
    }

    /**
     * Counts of other branches, and of the other industry here, look unknown.
     */
    private function ensureHere(StockCount $count): void
    {
        abort_unless($count->branch_id === $this->branch->id() && $count->application === $this->industry->code(), 404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
