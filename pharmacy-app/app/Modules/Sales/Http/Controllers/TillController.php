<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Actions\Tills;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\TillCashMovement;
use App\Modules\Sales\Models\TillSession;
use App\Support\CurrentBranch;
use App\Support\Industry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * Cash shifts (tills) of the branch being worked in. Cashiers start, see
 * and close their own; with <industry>.tills.manage a manager sees and
 * closes everyone's (the cashier reconciliation). The branch's settings
 * say whether shifts are needed, the usual starting cash, whether cashiers
 * count blind, and whether only managers close shifts.
 */
class TillController extends Controller
{
    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly Tills $tills,
        private readonly Industry $industry,
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
        $settings = $this->tills->settings($this->branch->id());

        return Inertia::render($this->industry->page('tills/index'), [
            'day' => $day->toDateString(),
            'managing' => $managing,
            'mine' => $mine === null ? null : $this->present($mine, $managing),
            'tills' => $tills->map(fn (TillSession $till) => $this->present($till, $managing)),
            'shifts' => $this->shiftRules($settings, $managing),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['opening_float' => ['required', 'string', Money::rule()]]);

        $till = $this->tills->open($this->user($request), $this->branch->id(), Money::toMinor($validated['opening_float']));

        return back()->with('success', "Shift {$till->number} started.");
    }

    public function show(Request $request, TillSession $till): Response
    {
        $this->ensureVisible($request, $till);
        $till->load(['opener:id,name', 'closer:id,name']);
        $managing = $this->manages();
        $rules = $this->shiftRules($this->tills->settings($this->branch->id()), $managing);
        $summary = $this->tills->summary($till);
        if ($rules['blind']) {
            // Counted blind: the cashier does not see what the drawer should
            // hold, nor the totals that add up to it.
            $summary['expectedCashMinor'] = null;
            $summary['takenMinor'] = [];
            $summary['collectedMinor'] = [];
            $summary['cashRefundsMinor'] = null;
        }

        return Inertia::render($this->industry->page('tills/show'), [
            'till' => $this->present($till, $managing),
            'summary' => $summary,
            // Recorded by the cashier or a manager, so seen even when blind.
            'movements' => $this->tills->movements($till)->map(fn (TillCashMovement $movement) => [
                'id' => $movement->id,
                'direction' => $movement->direction,
                'counterpart' => $movement->counterpart,
                'label' => $movement->label(),
                'amountMinor' => $movement->amount_minor,
                'reason' => $movement->reason,
                'recordedBy' => $movement->recorder->name,
                'recordedAt' => $movement->created_at->toIso8601String(),
            ]),
            'methods' => array_map(fn (PaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()], PaymentMethod::cases()),
            'shifts' => $rules,
            'canClose' => $till->status === TillSession::OPEN && ($managing || ! $rules['managerCloses']),
            'canMove' => $till->status === TillSession::OPEN,
        ]);
    }

    /**
     * Cash put into or taken out of an open shift: to or from the safe or
     * the bank, or paid out for an expense.
     */
    public function move(Request $request, TillSession $till): RedirectResponse
    {
        $this->ensureVisible($request, $till);
        $validated = $request->validate([
            'direction' => ['required', 'in:'.TillCashMovement::IN.','.TillCashMovement::OUT],
            'counterpart' => ['nullable', 'in:'.implode(',', TillCashMovement::COUNTERPARTS)],
            'amount' => ['required', 'string', Money::rule()],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $movement = $this->tills->move($till, $this->user($request), $validated['direction'], Money::toMinor($validated['amount']), $validated['reason'], counterpart: $validated['counterpart'] ?? TillCashMovement::SAFE);

        return back()->with('success', "{$movement->label()}: ".Money::toMajor($movement->amount_minor).'.');
    }

    public function close(Request $request, TillSession $till): RedirectResponse
    {
        $this->ensureVisible($request, $till);
        $validated = $request->validate([
            'counted_cash' => ['required', 'string', Money::rule()],
            'closing_note' => ['nullable', 'string', 'max:255'],
        ]);

        $managing = $this->manages();
        $rules = $this->shiftRules($this->tills->settings($this->branch->id()), $managing);
        abort_if($rules['managerCloses'] && ! $managing, 403, 'A manager closes shifts at this branch.');

        $this->tills->close($till, $this->user($request), Money::toMinor($validated['counted_cash']), $validated['closing_note'] ?? null, blind: $rules['blind']);

        return back()->with('success', "Shift {$till->number} closed.");
    }

    /**
     * How shifts work here, for this member.
     *
     * @return array{required: bool, defaultFloat: string, blind: bool, managerCloses: bool}
     */
    private function shiftRules(BranchCounterSettings $settings, bool $managing): array
    {
        return [
            'required' => $settings->shifts_required,
            'defaultFloat' => Money::toMajor($settings->default_float_minor ?? 0),
            // Managers always see the figures.
            'blind' => $settings->blind_close && ! $managing,
            'managerCloses' => $settings->manager_closes_shifts,
        ];
    }

    /**
     * @return array{id: string, number: string, status: string, openedBy: string, openedAt: string, openingFloatMinor: int, closedBy: string|null, closedAt: string|null, expectedCashMinor: int|null, countedCashMinor: int|null, differenceMinor: int|null, closingNote: string|null}
     */
    private function present(TillSession $till, bool $managing): array
    {
        // Counted blind: a cashier sees what they counted, not what was expected.
        $blind = ! $managing && $this->tills->settings($this->branch->id())->blind_close;

        return [
            'id' => $till->id,
            'number' => $till->number,
            'status' => $till->status,
            'openedBy' => $till->opener->name,
            'openedAt' => $till->opened_at->toIso8601String(),
            'openingFloatMinor' => $till->opening_float_minor,
            'closedBy' => $till->closer?->name,
            'closedAt' => $till->closed_at?->toIso8601String(),
            'expectedCashMinor' => $blind ? null : $till->expected_cash_minor,
            'countedCashMinor' => $till->counted_cash_minor,
            'differenceMinor' => $blind ? null : $till->difference(),
            'closingNote' => $till->closing_note,
        ];
    }

    private function manages(): bool
    {
        return app(CurrentAccess::class)->get()?->allowsIn($this->industry->permission('tills.manage'), branchId: $this->branch->id()) ?? false;
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
