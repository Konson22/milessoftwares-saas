<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Inventory\Actions\Disposals;
use App\Modules\Inventory\Enums\DisposalReason;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockDisposal;
use App\Support\CurrentBranch;
use App\Support\Industry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * Stock to be destroyed in the branch being worked in: asked for by
 * storekeepers, decided by someone else who may approve disposals.
 */
class DisposalController extends Controller
{
    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly Industry $industry,
    ) {}

    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), [StockDisposal::PENDING, StockDisposal::APPROVED, StockDisposal::REJECTED], true)
            ? (string) $request->query('status')
            : StockDisposal::PENDING;

        $disposals = StockDisposal::query()
            ->where('branch_id', $this->branch->id())
            ->where('application', $this->industry->code())
            ->where('status', $status)
            ->with(['item', 'batch:id,batch_number,expires_on,unit_cost_minor', 'requester:id,name', 'decider:id,name'])
            ->latest('requested_at')
            ->limit(200)
            ->get();

        return Inertia::render($this->industry->page('disposals/index'), [
            'status' => $status,
            'disposals' => $disposals->map(fn (StockDisposal $disposal) => [
                'id' => $disposal->id,
                'number' => $disposal->number,
                'itemId' => $disposal->item_id,
                'medicine' => $disposal->item->name,
                'batchNumber' => $disposal->batch->batch_number,
                'expiresOn' => $disposal->batch->expires_on?->toDateString(),
                'quantity' => $disposal->quantity,
                'costMinor' => $disposal->quantity * $disposal->batch->unit_cost_minor,
                'reason' => $disposal->reason->label(),
                'note' => $disposal->note,
                'requestedBy' => $disposal->requester->name,
                'requestedAt' => $disposal->requested_at->toIso8601String(),
                'mine' => $disposal->requested_by === $request->user()?->getAuthIdentifier(),
                'decidedBy' => $disposal->decider?->name,
                'decidedAt' => $disposal->decided_at?->toIso8601String(),
                'decisionNote' => $disposal->decision_note,
            ]),
            'canDecide' => app(CurrentAccess::class)->get()?->allowsIn($this->industry->permission('stock.dispose'), branchId: $this->branch->id()) ?? false,
        ]);
    }

    public function store(Request $request, Batch $batch, Disposals $disposals): RedirectResponse
    {
        abort_unless($batch->branch_id === $this->branch->id(), 404);

        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', Rule::enum(DisposalReason::class)],
            'note' => ['nullable', 'string', 'max:255', Rule::requiredIf($request->input('reason') === DisposalReason::Other->value)],
        ]);

        $disposal = $disposals->request($batch, (int) $validated['quantity'], DisposalReason::from($validated['reason']), $validated['note'] ?? null, $this->user($request));

        return back()->with('success', "Disposal {$disposal->number} is waiting for approval.");
    }

    public function approve(Request $request, StockDisposal $disposal, Disposals $disposals): RedirectResponse
    {
        $this->ensureHere($disposal);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        $disposals->approve($disposal, $this->user($request), $validated['note'] ?? null);

        return back()->with('success', "Disposal {$disposal->number} approved; the stock is written off.");
    }

    public function reject(Request $request, StockDisposal $disposal, Disposals $disposals): RedirectResponse
    {
        $this->ensureHere($disposal);
        $validated = $request->validate(['note' => ['required', 'string', 'max:255']]);

        $disposals->reject($disposal, $this->user($request), $validated['note']);

        return back()->with('success', "Disposal {$disposal->number} turned down; the stock stays.");
    }

    private function ensureHere(StockDisposal $disposal): void
    {
        abort_unless($disposal->branch_id === $this->branch->id() && $disposal->application === $this->industry->code(), 404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
