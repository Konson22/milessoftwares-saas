<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Actions\Tills;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\TillSession;
use App\Support\CurrentBranch;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * Tills of the branch being worked in. Cashiers open, see and close their
 * own; with pharmacy.tills.manage a manager sees and closes everyone's
 * (the cashier reconciliation).
 */
class TillController extends Controller
{
    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly Tills $tills,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $day = CarbonImmutable::parse(is_string($request->query('day')) ? $request->query('day') : 'today')->startOfDay();
        $managing = $this->manages();

        $tills = TillSession::query()
            ->where('branch_id', $this->branch->id())
            ->when(! $managing, fn ($query) => $query->where('opened_by', $user->id))
            ->where(fn ($query) => $query
                ->whereBetween('opened_at', [$day, $day->endOfDay()])
                ->orWhere('status', TillSession::OPEN))
            ->with(['opener:id,name', 'closer:id,name'])
            ->latest('opened_at')
            ->get();

        $mine = $this->tills->openFor($user, $this->branch->id());

        return Inertia::render('tills/index', [
            'day' => $day->toDateString(),
            'managing' => $managing,
            'mine' => $mine === null ? null : $this->present($mine),
            'tills' => $tills->map(fn (TillSession $till) => $this->present($till)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['opening_float' => ['required', 'string', Money::rule()]]);

        $till = $this->tills->open($this->user($request), $this->branch->id(), Money::toMinor($validated['opening_float']));

        return back()->with('success', "Till {$till->number} is open.");
    }

    public function show(Request $request, TillSession $till): Response
    {
        $this->ensureVisible($request, $till);
        $till->load(['opener:id,name', 'closer:id,name']);

        return Inertia::render('tills/show', [
            'till' => $this->present($till),
            'summary' => $this->tills->summary($till),
            'methods' => array_map(fn (PaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()], PaymentMethod::cases()),
        ]);
    }

    public function close(Request $request, TillSession $till): RedirectResponse
    {
        $this->ensureVisible($request, $till);
        $validated = $request->validate([
            'counted_cash' => ['required', 'string', Money::rule()],
            'closing_note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->tills->close($till, $this->user($request), Money::toMinor($validated['counted_cash']), $validated['closing_note'] ?? null);

        return back()->with('success', "Till {$till->number} is closed.");
    }

    /**
     * @return array{id: string, number: string, status: string, openedBy: string, openedAt: string, openingFloatMinor: int, closedBy: string|null, closedAt: string|null, expectedCashMinor: int|null, countedCashMinor: int|null, differenceMinor: int|null, closingNote: string|null}
     */
    private function present(TillSession $till): array
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
            'differenceMinor' => $till->difference(),
            'closingNote' => $till->closing_note,
        ];
    }

    private function manages(): bool
    {
        return app(CurrentAccess::class)->get()?->allowsIn('pharmacy.tills.manage', branchId: $this->branch->id()) ?? false;
    }

    /**
     * Another branch's till looks unknown; someone else's is for managers.
     */
    private function ensureVisible(Request $request, TillSession $till): void
    {
        abort_unless($till->branch_id === $this->branch->id(), 404);
        abort_unless($till->opened_by === $this->user($request)->id || $this->manages(), 403);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
