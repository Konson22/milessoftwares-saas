<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Enums\AdjustmentReason;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\CurrentBranch;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    public function __construct(private readonly CurrentBranch $branch) {}

    public function index(Request $request): Response
    {
        $filter = in_array($request->query('filter'), ['low', 'expiring', 'expired'], true) ? (string) $request->query('filter') : 'all';
        $today = CarbonImmutable::today();

        // Per item in this branch: sellable (unexpired) quantity, expired
        // quantity, and the nearest expiry among batches with stock.
        $totals = Batch::query()
            ->where('branch_id', $this->branch->id())
            ->where('quantity_on_hand', '>', 0)
            ->groupBy('item_id')
            ->select('item_id')
            ->selectRaw('SUM(CASE WHEN expires_on > ? THEN quantity_on_hand ELSE 0 END) AS sellable', [$today->toDateString()])
            ->selectRaw('SUM(CASE WHEN expires_on <= ? THEN quantity_on_hand ELSE 0 END) AS expired', [$today->toDateString()])
            ->selectRaw('MIN(CASE WHEN expires_on > ? THEN expires_on END) AS next_expiry', [$today->toDateString()])
            ->get()
            ->keyBy('item_id');

        $rows = Item::query()
            ->where('active', true)
            ->search($request->string('search')->toString())
            ->orderBy('name')
            ->get()
            ->map(function (Item $item) use ($totals, $today) {
                $total = $totals->get($item->id);
                // Aggregates from the query above, not columns of the model.
                $sellable = (int) ($total?->getAttribute('sellable') ?? 0);
                $expired = (int) ($total?->getAttribute('expired') ?? 0);
                $next = $total?->getAttribute('next_expiry');
                $nextExpiry = is_string($next) ? CarbonImmutable::parse($next) : null;

                return [
                    'id' => $item->id,
                    'label' => $item->name,
                    'unit' => $item->unit,
                    'sellable' => $sellable,
                    'expired' => $expired,
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
                default => true,
            })
            ->values();

        return Inertia::render('stock/index', [
            'rows' => $rows,
            'filters' => ['search' => $request->string('search')->toString(), 'filter' => $filter],
            'expiringDays' => self::EXPIRING_DAYS,
        ]);
    }

    public function show(Request $request, Item $item): Response
    {
        $today = CarbonImmutable::today();

        $batches = Batch::query()
            ->where('branch_id', $this->branch->id())
            ->where('item_id', $item->id)
            ->orderBy('expires_on')
            ->get()
            ->map(fn (Batch $batch) => [
                'id' => $batch->id,
                'batchNumber' => $batch->batch_number,
                'expiresOn' => $batch->expires_on->toDateString(),
                'expired' => $batch->isExpired($today),
                'quantity' => $batch->quantity_on_hand,
                'unitCostMinor' => $batch->unit_cost_minor,
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
                'reason' => $movement->reason === null ? null : AdjustmentReason::tryFrom($movement->reason)?->label(),
                'note' => $movement->note,
                'by' => $movement->user->name,
            ]);

        $access = app(CurrentAccess::class)->get();

        return Inertia::render('stock/show', [
            'medicine' => [
                'id' => $item->id,
                'label' => $item->name,
                'unit' => $item->unit,
                'controlled' => $item->controlled,
                'reorderLevel' => $item->reorder_level,
            ],
            'batches' => $batches,
            'movements' => $movements,
            'reasons' => array_map(fn (AdjustmentReason $reason) => ['value' => $reason->value, 'label' => $reason->label()], AdjustmentReason::cases()),
            'canAdjust' => $access?->allowsIn('pharmacy.stock.adjust', branchId: $this->branch->id()) ?? false,
        ]);
    }

    /**
     * Stock already on the shelf when the pharmacy starts using the system,
     * or found during a count, as a new batch.
     */
    public function storeOpening(Request $request, Item $item, Stock $stock): RedirectResponse
    {
        $validated = $request->validate([
            'batch_number' => ['required', 'string', 'max:64'],
            'expires_on' => ['required', 'date', 'after:today'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'unit_cost' => ['required', 'string', Money::rule()],
        ]);

        $stock->add(
            item: $item,
            branchId: $this->branch->id(),
            batchNumber: trim($validated['batch_number']),
            expiresOn: CarbonImmutable::parse($validated['expires_on']),
            quantity: (int) $validated['quantity'],
            unitCostMinor: Money::toMinor($validated['unit_cost']),
            user: $this->user($request),
            type: MovementType::Opening,
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

        $stock->adjust(
            $batch,
            (int) $validated['change'],
            AdjustmentReason::from($validated['reason']),
            $validated['note'] ?? null,
            $this->user($request),
        );

        return back()->with('success', "Batch {$batch->batch_number} adjusted.");
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
