<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Actions\Transfers;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockLocation;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferLine;
use App\Support\Branches;
use App\Support\CurrentBranch;
use App\Support\Industry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Api\Branch;

/**
 * Stock sent from and to the branch being worked in.
 */
class TransferController extends Controller
{
    public const MAX_BATCHES = 1000;

    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly Branches $branches,
        private readonly Industry $industry,
    ) {}

    public function index(Request $request): Response
    {
        $here = $this->branch->id();
        $direction = $request->query('direction') === 'outgoing' ? 'outgoing' : 'incoming';
        $names = $this->branches->names();

        $transfers = StockTransfer::query()
            ->where($direction === 'incoming' ? 'to_branch_id' : 'from_branch_id', $here)
            ->where('application', $this->industry->code())
            ->with('sender:id,name')
            ->withCount('lines')
            ->withSum('lines', 'quantity')
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [StockTransfer::IN_TRANSIT])
            ->latest('sent_at')
            ->limit(200)
            ->get();

        return Inertia::render($this->industry->page('transfers/index'), [
            'direction' => $direction,
            'transfers' => $transfers->map(fn (StockTransfer $transfer) => [
                'id' => $transfer->id,
                'number' => $transfer->number,
                'from' => $names[$transfer->from_branch_id] ?? '–',
                'to' => $names[$transfer->to_branch_id] ?? '–',
                'status' => $transfer->status,
                'lines' => (int) $transfer->getAttribute('lines_count'),
                'units' => (int) $transfer->getAttribute('lines_sum_quantity'),
                'sentBy' => $transfer->sender->name,
                'sentAt' => $transfer->sent_at->toIso8601String(),
            ]),
            'canSend' => $this->can($this->industry->permission('stock.adjust')),
        ]);
    }

    public function create(): Response
    {
        $here = $this->branch->id();

        return Inertia::render($this->industry->page('transfers/create'), [
            'branches' => array_values(array_map(
                fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name],
                array_filter($this->branches->allActive(), fn (Branch $branch) => $branch->id !== $here),
            )),
            'batches' => Batch::query()
                ->where('branch_id', $here)
                ->where('status', Batch::AVAILABLE)
                ->where('quantity_on_hand', '>', 0)
                ->unexpired(CarbonImmutable::today())
                ->whereHas('item', fn ($item) => $item->ofKind($this->industry->itemKinds()))
                ->with('item')
                ->firstToExpire()
                ->limit(self::MAX_BATCHES)
                ->get()
                ->sortBy(fn (Batch $batch) => $batch->item->name)
                ->values()
                ->map(fn (Batch $batch) => [
                    'id' => $batch->id,
                    'label' => "{$batch->item->name} · {$batch->batch_number}".($batch->expires_on === null ? '' : " · exp {$batch->expires_on->toDateString()}"),
                    'onHand' => $batch->quantity_on_hand,
                ]),
        ]);
    }

    public function store(Request $request, Transfers $transfers): RedirectResponse
    {
        $here = $this->branch->id();
        $validated = $request->validate([
            'to_branch_id' => ['required', 'uuid', Rule::in(array_map(fn (Branch $branch) => $branch->id, $this->branches->allActive()))],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.batch_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        $transfer = $transfers->send($here, $validated['to_branch_id'], array_values(array_map(fn (array $line) => [
            'batch_id' => (string) $line['batch_id'],
            'quantity' => (int) $line['quantity'],
        ], $validated['lines'])), $validated['note'] ?? null, $this->user($request));

        return to_route($this->industry->route('transfers.show'), $transfer)->with('success', "Transfer {$transfer->number} is on its way.");
    }

    public function show(StockTransfer $transfer): Response
    {
        $here = $this->ensureHere($transfer);
        $names = $this->branches->names();
        $transfer->load(['lines.item', 'sender:id,name', 'receiver:id,name', 'canceller:id,name']);
        $inTransit = $transfer->status === StockTransfer::IN_TRANSIT;
        $mayAdjust = $this->can($this->industry->permission('stock.adjust'));

        return Inertia::render($this->industry->page('transfers/show'), [
            'transfer' => [
                'id' => $transfer->id,
                'number' => $transfer->number,
                'from' => $names[$transfer->from_branch_id] ?? '–',
                'to' => $names[$transfer->to_branch_id] ?? '–',
                'status' => $transfer->status,
                'note' => $transfer->note,
                'sentBy' => $transfer->sender->name,
                'sentAt' => $transfer->sent_at->toIso8601String(),
                'receivedBy' => $transfer->receiver?->name,
                'receivedAt' => $transfer->received_at?->toIso8601String(),
                'cancelledBy' => $transfer->canceller?->name,
                'cancelledAt' => $transfer->cancelled_at?->toIso8601String(),
                'lines' => $transfer->lines->map(fn (StockTransferLine $line) => [
                    'id' => $line->id,
                    'itemId' => $line->item_id,
                    'item' => $line->item->name,
                    'batchNumber' => $line->batch_number,
                    'expiresOn' => $line->expires_on?->toDateString(),
                    'quantity' => $line->quantity,
                    'costMinor' => $line->quantity * $line->unit_cost_minor,
                ]),
            ],
            'canReceive' => $inTransit && $mayAdjust && $here === $transfer->to_branch_id,
            'canCancel' => $inTransit && $mayAdjust && $here === $transfer->from_branch_id,
            'locations' => StockLocation::query()->where('branch_id', $here)->where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function receive(Request $request, StockTransfer $transfer, Transfers $transfers): RedirectResponse
    {
        abort_unless($this->ensureHere($transfer) === $transfer->to_branch_id, 404);
        $validated = $request->validate(['location_id' => ['nullable', 'uuid']]);

        $location = isset($validated['location_id'])
            ? StockLocation::query()->where('branch_id', $transfer->to_branch_id)->where('active', true)->whereKey($validated['location_id'])->firstOrFail()
            : null;
        $transfers->receive($transfer, $this->user($request), $location);

        return back()->with('success', "Transfer {$transfer->number} received into stock.");
    }

    public function cancel(Request $request, StockTransfer $transfer, Transfers $transfers): RedirectResponse
    {
        abort_unless($this->ensureHere($transfer) === $transfer->from_branch_id, 404);

        $transfers->cancel($transfer, $this->user($request));

        return back()->with('success', "Transfer {$transfer->number} cancelled; the stock is back.");
    }

    /**
     * Only the two branches of a transfer see it, in the industry module that
     * sent it; returns the branch worked in.
     */
    private function ensureHere(StockTransfer $transfer): string
    {
        $here = $this->branch->id();
        abort_unless(($here === $transfer->from_branch_id || $here === $transfer->to_branch_id) && $transfer->application === $this->industry->code(), 404);

        return $here;
    }

    private function can(string $permission): bool
    {
        return app(CurrentAccess::class)->get()?->allowsIn($permission, branchId: $this->branch->id()) ?? false;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
