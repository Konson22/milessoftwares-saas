<?php

namespace App\Http\Controllers;

use App\Actions\Handovers;
use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\CashHandover;
use App\Models\TillShift;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cash handed over at the branch being worked in: a cashier hands a closed
 * till's cash to a manager, who counts and receives it. Cashiers see their
 * own handovers; managers (restaurant.tills.manage) every one, and receive.
 */
class HandoverController extends Controller
{
    use WorksInBranch;

    public function __construct(private readonly Handovers $handovers) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate(['status' => ['nullable', 'in:pending,received,all']]);
        $status = $validated['status'] ?? 'pending';
        $managing = $this->canHere('restaurant.tills.manage');
        $member = $this->member($request);

        $handovers = CashHandover::query()
            ->where('branch_id', $this->branchId())
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when(! $managing, fn ($query) => $query->where('handed_by', $member->id))
            ->with(['hander:id,name', 'receiver:id,name', 'till:id,number,opened_by'])
            ->latest('handed_at')
            ->limit(200)
            ->get();

        return Inertia::render('tills/handovers', [
            'status' => $status,
            'managing' => $managing,
            'handovers' => $handovers->map(fn (CashHandover $handover) => [
                ...self::present($handover),
                // A manager receives cash someone else handed over, not their own.
                'canReceive' => $managing && $handover->isPending() && $handover->handed_by !== $member->id && $handover->till->opened_by !== $member->id,
            ]),
        ]);
    }

    public function store(Request $request, TillShift $till): RedirectResponse
    {
        $this->ensureHere($till->branch_id);
        $member = $this->member($request);
        abort_unless($till->opened_by === $member->id || $this->canHere('restaurant.tills.manage'), 404);

        $validated = $request->validate([
            'amount' => ['required', 'string', Money::rule()],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $handover = $this->handovers->handOver($till, $member, Money::toMinor($validated['amount']), $validated['note'] ?? null);

        return to_route('tills.show', $till)->with('success', "Cash of shift {$till->number} handed over ({$handover->number}). A manager receives it.");
    }

    public function receive(Request $request, CashHandover $handover): RedirectResponse
    {
        $this->ensureHere($handover->branch_id);
        $validated = $request->validate([
            'received' => ['required', 'string', Money::rule()],
            'receiving_note' => ['nullable', 'string', 'max:255'],
        ]);

        $received = $this->handovers->receive($handover, $this->member($request), Money::toMinor($validated['received']), $validated['receiving_note'] ?? null);

        return back()->with('success', "Handover {$received->number} received.");
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(CashHandover $handover): array
    {
        return [
            'id' => $handover->id,
            'number' => $handover->number,
            'status' => $handover->status,
            'tillId' => $handover->till_shift_id,
            'till' => $handover->relationLoaded('till') ? $handover->till->number : null,
            'amountMinor' => $handover->amount_minor,
            'note' => $handover->note,
            'handedBy' => $handover->hander->name,
            'handedAt' => $handover->handed_at->toIso8601String(),
            'receivedBy' => $handover->receiver?->name,
            'receivedAt' => $handover->received_at?->toIso8601String(),
            'receivedMinor' => $handover->received_minor,
            'differenceMinor' => $handover->differenceMinor(),
            'receivingNote' => $handover->receiving_note,
        ];
    }
}
