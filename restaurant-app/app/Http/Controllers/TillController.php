<?php

namespace App\Http\Controllers;

use App\Actions\Tills;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\TillShift;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cash shifts at the branch being worked in: cashiers open and close their
 * own; managers (restaurant.tills.manage) see and close everyone's.
 */
class TillController extends Controller
{
    use WorksInBranch;

    public function __construct(private readonly Tills $tills) {}

    public function index(Request $request): Response
    {
        $day = CarbonImmutable::parse(is_string($request->query('day')) ? $request->query('day') : 'today')->startOfDay();
        $member = $this->member($request);
        $managing = $this->canHere('restaurant.tills.manage');

        $tills = TillShift::query()
            ->where('branch_id', $this->branchId())
            ->whereBetween('opened_at', [$day, $day->endOfDay()])
            ->when(! $managing, fn ($query) => $query->where('opened_by', $member->id))
            ->with(['opener:id,name', 'closer:id,name'])
            ->latest('opened_at')
            ->get();
        $mine = $this->tills->openFor($member, $this->branchId());

        return Inertia::render('tills/index', [
            'day' => $day->toDateString(),
            'managing' => $managing,
            'mine' => $mine === null ? null : $this->summary($mine->load(['opener:id,name', 'closer:id,name'])),
            'tills' => $tills->map(fn (TillShift $till) => $this->summary($till)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['opening_float' => ['nullable', 'string', Money::rule()]]);
        $float = isset($validated['opening_float']) && $validated['opening_float'] !== '' ? Money::toMinor($validated['opening_float']) : 0;

        $till = $this->tills->open($this->member($request), $this->branchId(), $float);

        return to_route('tills.index')->with('success', "Shift {$till->number} started.");
    }

    public function show(Request $request, TillShift $till): Response
    {
        $this->ensureMine($request, $till);
        $till->load(['opener:id,name', 'closer:id,name']);

        return Inertia::render('tills/show', [
            'till' => $this->summary($till),
            'summary' => $this->tills->summary($till),
            'methods' => PaymentMethod::options(),
        ]);
    }

    public function close(Request $request, TillShift $till): RedirectResponse
    {
        $this->ensureMine($request, $till);
        $validated = $request->validate([
            'counted_cash' => ['required', 'string', Money::rule()],
            'closing_note' => ['nullable', 'string', 'max:255'],
        ]);

        $closed = $this->tills->close($till, $this->member($request), Money::toMinor($validated['counted_cash']), $validated['closing_note'] ?? null);

        return to_route('tills.show', $closed)->with('success', "Shift {$closed->number} closed.");
    }

    /**
     * A cashier sees only their own shifts; managers every shift here.
     */
    private function ensureMine(Request $request, TillShift $till): void
    {
        $this->ensureHere($till->branch_id);
        abort_unless($till->opened_by === $this->member($request)->id || $this->canHere('restaurant.tills.manage'), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(TillShift $till): array
    {
        return [
            'id' => $till->id,
            'number' => $till->number,
            'status' => $till->status,
            'openedBy' => $till->opener->name,
            'openedAt' => $till->opened_at->toIso8601String(),
            'openingFloatMinor' => $till->opening_float_minor,
            'closedBy' => $till->closer?->name,
            'closedAt' => $till->closed_at?->toIso8601String(),
            'expectedCashMinor' => $till->expected_cash_minor,
            'countedCashMinor' => $till->counted_cash_minor,
            'differenceMinor' => $till->counted_cash_minor === null || $till->expected_cash_minor === null
                ? null
                : $till->counted_cash_minor - $till->expected_cash_minor,
            'closingNote' => $till->closing_note,
        ];
    }
}
