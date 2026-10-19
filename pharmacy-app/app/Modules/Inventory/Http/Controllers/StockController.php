<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Actions\BatchHolds;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Enums\AdjustmentReason;
use App\Modules\Inventory\Enums\DisposalReason;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\BatchLocation;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockDisposal;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\CurrentBranch;
use App\Support\Industry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * Stock of the branch the member is working in.
 */
class StockController extends Controller
{
    /** "Expiring soon" means within this many days. */
    public const EXPIRING_DAYS = 90;

    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly Industry $industry,
    ) {}

    public function index(Request $request): Response
    {
        $filter = in_array($request->query('filter'), ['low', 'expiring', 'expired', 'held'], true) ? (string) $request->query('filter') : 'all';
        $today = CarbonImmutable::today();

        // Per item in this branch: sellable (unexpired, not on hold)
        // quantity, expired quantity, unexpired quantity on hold, and the
        // nearest expiry among sellable batches.
        $totals = Batch::query()
            ->where('branch_id', $this->branch->id())
            ->where('quantity_on_hand', '>', 0)
            ->groupBy('item_id')
            ->select('item_id')
            ->selectRaw("SUM(CASE WHEN (expires_on IS NULL OR expires_on > ?) AND status = 'available' THEN quantity_on_hand ELSE 0 END) AS sellable", [$today->toDateString()])
            ->selectRaw('SUM(CASE WHEN expires_on <= ? THEN quantity_on_hand ELSE 0 END) AS expired', [$today->toDateString()])
            ->selectRaw("SUM(CASE WHEN (expires_on IS NULL OR expires_on > ?) AND status <> 'available' THEN quantity_on_hand ELSE 0 END) AS held", [$today->toDateString()])
            ->selectRaw("MIN(CASE WHEN expires_on > ? AND status = 'available' THEN expires_on END) AS next_expiry", [$today->toDateString()])
            ->get()
            ->keyBy('item_id');

        $rows = Item::query()
            ->where('active', true)
            ->ofKind($this->industry->itemKinds())
            ->search($request->string('search')->toString())
            ->orderBy('name')
            ->get()
            ->map(function (Item $item) use ($totals, $today) {
                $total = $totals->get($item->id);
                // Aggregates from the query above, not columns of the model.
                $sellable = (int) ($total?->getAttribute('sellable') ?? 0);
                $expired = (int) ($total?->getAttribute('expired') ?? 0);
                $held = (int) ($total?->getAttribute('held') ?? 0);
                $next = $total?->getAttribute('next_expiry');
                $nextExpiry = is_string($next) ? CarbonImmutable::parse($next) : null;

                return [
                    'id' => $item->id,
                    'label' => $item->name,
                    'unit' => $item->unit,
                    'sellable' => $sellable,
                    'expired' => $expired,
                    'held' => $held,
                    'nextExpiry' => $nextExpiry?->toDateString(),
                    'low' => $sellable <= $item->reorder_level,
                    'expiringSoon' => $nextExpiry !== null && $nextExpiry->lessThanOrEqualTo($today->addDays(self::EXPIRING_DAYS)),
                    'reorderLevel' => $item->reorder_level,
                ];
            })
            ->filter(fn (array $row) => match ($filter) {
                'low' => $row['low'],
                'expiring' => $row['expiringSoon'],
                'expired' => $row['expired'] > 0,
                'held' => $row['held'] > 0,
                default => true,
            })
            ->values();

        return Inertia::render($this->industry->page('stock/index'), [
            'rows' => $rows,
            'filters' => ['search' => $request->string('search')->toString(), 'filter' => $filter],
            'expiringDays' => self::EXPIRING_DAYS,
        ]);
    }

    public function show(Request $request, Item $item): Response
    {
        $this->ensureOurs($item);
        $today = CarbonImmutable::today();

        $batches = Batch::query()
            ->where('branch_id', $this->branch->id())
            ->where('item_id', $item->id)
            ->firstToExpire()
            ->get();
        // Where each batch's units are kept; the rest is not put away.
        $placed = BatchLocation::query()
            ->whereIn('batch_id', $batches->modelKeys())
            ->where('quantity', '>', 0)
            ->with('location:id,name')
            ->get()
            ->groupBy('batch_id');
        $batches = $batches
            ->map(fn (Batch $batch) => [
                'id' => $batch->id,
                'batchNumber' => $batch->batch_number,
                'expiresOn' => $batch->expires_on?->toDateString(),
                'expired' => $batch->isExpired($today),
                'quantity' => $batch->quantity_on_hand,
                'unitCostMinor' => $batch->unit_cost_minor,
                'status' => $batch->status,
                'holdReason' => $batch->hold_reason,
                'heldAt' => $batch->held_at?->toIso8601String(),
                'locations' => $placed->get($batch->id, collect())->map(fn (BatchLocation $placement) => [
                    'id' => $placement->stock_location_id,
                    'name' => $placement->location->name,
                    'quantity' => $placement->quantity,
                ])->sortBy('name')->values(),
                'notPutAway' => $batch->quantity_on_hand - (int) $placed->get($batch->id, collect())->sum('quantity'),
            ]);

        $disposals = StockDisposal::query()
            ->where('branch_id', $this->branch->id())
            ->where('item_id', $item->id)
            ->where('status', StockDisposal::PENDING)
            ->with(['batch:id,batch_number', 'requester:id,name'])
            ->latest('requested_at')
            ->get()
            ->map(fn (StockDisposal $disposal) => [
                'id' => $disposal->id,
                'number' => $disposal->number,
                'batchNumber' => $disposal->batch->batch_number,
                'quantity' => $disposal->quantity,
                'reason' => $disposal->reason->label(),
                'requestedBy' => $disposal->requester->name,
            ]);

        $movements = StockMovement::query()
            ->where('branch_id', $this->branch->id())
            ->where('item_id', $item->id)
            ->with(['batch:id,batch_number', 'user:id,name'])
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (StockMovement $movement) => [
                'id' => $movement->id,
                'at' => $movement->created_at->toIso8601String(),
                'type' => $movement->type->value,
                'quantity' => $movement->quantity,
                'batchNumber' => $movement->batch->batch_number,
                'reason' => $movement->reason === null ? null : (AdjustmentReason::tryFrom($movement->reason) ?? DisposalReason::tryFrom($movement->reason))?->label(),
                'note' => $movement->note,
                'by' => $movement->user->name,
            ]);

        $access = app(CurrentAccess::class)->get();
        $can = fn (string $permission) => $access?->allowsIn($permission, branchId: $this->branch->id()) ?? false;

        return Inertia::render($this->industry->page('stock/show'), [
            'medicine' => [
                'id' => $item->id,
                'label' => $item->name,
                'unit' => $item->unit,
                'controlled' => $item->controlled,
                'reorderLevel' => $item->reorder_level,
                'tracksExpiry' => $item->tracks_expiry,
            ],
            'batches' => $batches,
            'movements' => $movements,
            'pendingDisposals' => $disposals,
            'locations' => StockLocation::query()->where('branch_id', $this->branch->id())->where('active', true)->orderBy('name')->get(['id', 'name'])->map(fn (StockLocation $location) => ['id' => $location->id, 'name' => $location->name]),
            'reasons' => array_map(fn (AdjustmentReason $reason) => ['value' => $reason->value, 'label' => $reason->label()], AdjustmentReason::cases()),
            'disposalReasons' => array_map(fn (DisposalReason $reason) => ['value' => $reason->value, 'label' => $reason->label()], DisposalReason::cases()),
            'canAdjust' => $can($this->industry->permission('stock.adjust')),
            'canHold' => $can($this->industry->permission('stock.hold')),
        ]);
    }

    /**
     * Stock already on the shelf when the pharmacy starts using the system,
     * or found during a count, as a new batch.
     */
    public function storeOpening(Request $request, Item $item, Stock $stock): RedirectResponse
    {
        $this->ensureOurs($item);
        $validated = $request->validate([
            'batch_number' => ['required', 'string', 'max:64'],
            'expires_on' => [Rule::requiredIf($item->tracks_expiry), 'nullable', 'date', 'after:today'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'unit_cost' => ['required', 'string', Money::rule()],
            'location_id' => ['nullable', 'uuid'],
        ]);

        $stock->add(
            item: $item,
            branchId: $this->branch->id(),
            batchNumber: trim($validated['batch_number']),
            expiresOn: isset($validated['expires_on']) ? CarbonImmutable::parse($validated['expires_on']) : null,
            quantity: (int) $validated['quantity'],
            unitCostMinor: Money::toMinor($validated['unit_cost']),
            user: $this->user($request),
            type: MovementType::Opening,
            location: isset($validated['location_id'])
                ? StockLocation::query()->where('branch_id', $this->branch->id())->where('active', true)->whereKey($validated['location_id'])->firstOrFail()
                : null,
        );

        return back()->with('success', "Added {$validated['quantity']} to batch {$validated['batch_number']}.");
    }

    public function storeAdjustment(Request $request, Batch $batch, Stock $stock): RedirectResponse
    {
        // Batches of other branches look unknown.
        abort_unless($batch->branch_id === $this->branch->id(), 404);

        $validated = $request->validate([
            'change' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', Rule::enum(AdjustmentReason::class)],
            'note' => ['nullable', 'string', 'max:255', Rule::requiredIf($request->input('reason') === AdjustmentReason::Other->value)],
        ]);

        // Destroying stock needs a second person: it goes through a disposal.
        $reason = AdjustmentReason::from($validated['reason']);
        if ((int) $validated['change'] < 0 && in_array($reason, [AdjustmentReason::Damaged, AdjustmentReason::Expired], true)) {
            throw ValidationException::withMessages(['change' => 'Damaged or expired stock leaves through a disposal, which someone else approves.']);
        }

        $stock->adjust(
            $batch,
            (int) $validated['change'],
            $reason,
            $validated['note'] ?? null,
            $this->user($request),
        );

        return back()->with('success', "Batch {$batch->batch_number} adjusted.");
    }

    public function hold(Request $request, Batch $batch, BatchHolds $holds): RedirectResponse
    {
        abort_unless($batch->branch_id === $this->branch->id(), 404);

        $validated = $request->validate([
            'status' => ['required', Rule::in([Batch::QUARANTINED, Batch::RECALLED])],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $holds->hold($batch, $validated['status'], $validated['reason'], $this->user($request));

        return back()->with('success', "Batch {$batch->batch_number} {$validated['status']}: it will not be sold.");
    }

    public function release(Batch $batch, BatchHolds $holds): RedirectResponse
    {
        abort_unless($batch->branch_id === $this->branch->id(), 404);

        $holds->release($batch);

        return back()->with('success', "Batch {$batch->batch_number} released for sale.");
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /**
     * Another industry's items look unknown here.
     */
    private function ensureOurs(Item $item): void
    {
        abort_unless(in_array($item->kind, $this->industry->itemKinds(), true), 404);
    }
}
