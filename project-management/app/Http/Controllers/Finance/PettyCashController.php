<?php

namespace App\Http\Controllers\Finance;

use App\Actions\Alerts;
use App\Actions\PettyCash;
use App\Enums\CostCategory;
use App\Http\Controllers\Controller;
use App\Models\PettyCashCount;
use App\Models\PettyCashFund;
use App\Models\PettyCashPayment;
use App\Models\PettyCashTopUp;
use App\Models\Project;
use App\Models\User;
use App\Support\FinanceLink;
use App\Support\Money;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Petty cash boxes: paying from them, topping them up, counting them. The
 * books are Finance's: money into and out of a box comes from the bank.
 */
class PettyCashController extends Controller
{
    public function __construct(private readonly PettyCash $pettyCash) {}

    public function index(Request $request): Response
    {
        $funds = PettyCashFund::query()->with('custodian:id,name')
            ->orderByRaw("status <> 'active'")->orderBy('name')->get();
        $finance = $this->financeUrl();

        return Inertia::render('portal/finance/petty-cash/index', [
            'funds' => $funds->map(fn (PettyCashFund $fund) => [
                'id' => $fund->id,
                'name' => $fund->name,
                'custodian' => $fund->custodian->name,
                'floatMinor' => $fund->float_minor,
                'balanceMinor' => $this->pettyCash->balance($fund),
                'currency' => $fund->currency,
                'active' => $fund->isActive(),
                'moved' => $fund->isMoved(),
            ]),
            'custodians' => $this->custodians(),
            'currency' => Money::currency(),
            'limitMinor' => $this->limit(),
            'canManage' => $finance === null && $this->can($request, 'finance.pettycash.manage'),
            'financeUrl' => $finance,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = app(CurrentOrganization::class)->id();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('petty_cash_funds', 'name')->where('organization_id', $tenant)],
            'custodian_id' => ['required', 'uuid'],
            'float' => ['required', 'string', Money::rule()],
            'on' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $fund = $this->pettyCash->createFund(
            trim((string) $validated['name']),
            $this->custodian((string) $validated['custodian_id']),
            Money::toMinor((string) $validated['float']),
            CarbonImmutable::parse((string) $validated['on']),
            $this->member($request),
        );

        return to_route('petty-cash.show', $fund)->with('success', "{$fund->name} is set up.");
    }

    public function show(Request $request, PettyCashFund $fund): Response
    {
        $fund->load('custodian:id,name');
        $me = $this->member($request)->id;
        $manage = $this->can($request, 'finance.pettycash.manage');
        $isCustodian = $fund->custodian_id === $me;
        $balance = $this->pettyCash->balance($fund);
        $outstanding = $this->pettyCash->outstanding($fund);
        $finance = $this->financeUrl();
        // Kept in Finance: nothing more happens to the box here but moving it.
        $open = $fund->isActive() && $finance === null;

        return Inertia::render('portal/finance/petty-cash/show', [
            'fund' => [
                'id' => $fund->id,
                'name' => $fund->name,
                'custodianId' => $fund->custodian_id,
                'custodian' => $fund->custodian->name,
                'floatMinor' => $fund->float_minor,
                'balanceMinor' => $balance,
                'outstandingMinor' => $outstanding,
                // What counts found beyond the float and its receipts.
                'differenceMinor' => $balance + $outstanding - $fund->float_minor,
                'currency' => $fund->currency,
                'active' => $fund->isActive(),
                'movedAt' => $fund->moved_at?->toIso8601String(),
            ],
            'financeUrl' => $finance,
            'payments' => $fund->payments()->with('project:id,code', 'recorder:id,name', 'topUp:id,status')
                ->orderByDesc('paid_on')->orderByDesc('created_at')->limit(200)->get()
                ->map(fn (PettyCashPayment $payment) => [
                    'id' => $payment->id,
                    'paidOn' => $payment->paid_on->toDateString(),
                    'amountMinor' => $payment->amount_minor,
                    'description' => $payment->description,
                    'payee' => $payment->payee,
                    'chargedTo' => $payment->project->code ?? $payment->category?->label(),
                    'receiptName' => $payment->receipt_name,
                    'recordedBy' => $payment->recorder->name,
                    'topUpStatus' => $payment->topUp?->status,
                    'topUpId' => $payment->top_up_id,
                    'voidReason' => $payment->void_reason,
                    'void' => $payment->isVoid(),
                    'canVoid' => $open && ! $payment->isVoid() && $payment->top_up_id === null && ($manage || $isCustodian),
                ]),
            'topUps' => $fund->topUps()->with('requester:id,name', 'decider:id,name')
                ->orderByDesc('created_at')->limit(50)->get()
                ->map(fn (PettyCashTopUp $topUp) => [
                    'id' => $topUp->id,
                    'amountMinor' => $topUp->amount_minor,
                    'status' => $topUp->status,
                    'requestedBy' => $topUp->requester->name,
                    'requestedAt' => $topUp->created_at->toIso8601String(),
                    'decidedBy' => $topUp->decider?->name,
                    'decidedAt' => $topUp->decided_at?->toIso8601String(),
                    'note' => $topUp->decision_note,
                    'canDecide' => $topUp->isSubmitted() && $this->can($request, 'finance.pettycash.approve') && $fund->custodian_id !== $me && $topUp->requested_by !== $me,
                ]),
            'counts' => $fund->counts()->with('counter:id,name')->orderByDesc('counted_on')->orderByDesc('created_at')->limit(50)->get()
                ->map(fn (PettyCashCount $count) => [
                    'id' => $count->id,
                    'countedOn' => $count->counted_on->toDateString(),
                    'countedMinor' => $count->counted_minor,
                    'bookMinor' => $count->book_minor,
                    'differenceMinor' => $count->difference_minor,
                    'note' => $count->note,
                    'countedBy' => $count->counter->name,
                ]),
            'projects' => Project::query()->whereIn('status', Project::OPEN)->where('currency', $fund->currency)->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (Project $project) => ['value' => $project->id, 'label' => "{$project->code} · {$project->name}"])->values()->all(),
            'categories' => collect(CostCategory::cases())->reject(fn (CostCategory $category) => $category === CostCategory::Labour)
                ->map(fn (CostCategory $category) => ['value' => $category->value, 'label' => $category->label()])->values()->all(),
            'custodians' => $this->custodians(),
            'limitMinor' => $this->limit(),
            'can' => [
                'pay' => $open && $isCustodian && $this->can($request, 'finance.pettycash.record'),
                'requestTopUp' => $open && ($manage || ($isCustodian && $this->can($request, 'finance.pettycash.record'))),
                'manage' => $open && $manage,
                'count' => $open && $manage && ! $isCustodian,
                'moveToFinance' => $fund->isActive() && $finance !== null && $manage,
            ],
        ]);
    }

    public function pay(Request $request, PettyCashFund $fund): RedirectResponse
    {
        $tenant = app(CurrentOrganization::class)->id();
        $validated = $request->validate([
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'string', Money::rule($fund->currency)],
            'description' => ['required', 'string', 'max:255'],
            'payee' => ['nullable', 'string', 'max:255'],
            'project_id' => ['nullable', 'uuid', Rule::exists('projects', 'id')->where('organization_id', $tenant)],
            'category' => ['required', Rule::enum(CostCategory::class)->except([CostCategory::Labour])],
            'receipt' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);
        $projectId = $validated['project_id'] ?? null;

        $this->pettyCash->pay($fund, [
            'paid_on' => CarbonImmutable::parse((string) $validated['paid_on']),
            'amount_minor' => Money::toMinor((string) $validated['amount'], $fund->currency),
            'description' => trim((string) $validated['description']),
            'payee' => $this->text($validated['payee'] ?? null),
            'project' => $projectId === null ? null : Project::query()->findOrFail((string) $projectId),
            'category' => CostCategory::from((string) $validated['category']),
        ], $request->file('receipt'), $this->member($request));

        return back()->with('success', 'Payment recorded.');
    }

    public function reverse(Request $request, PettyCashFund $fund, PettyCashPayment $payment): RedirectResponse
    {
        $this->requireCustodianOrManager($request, $fund);
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        $this->pettyCash->void($payment, trim((string) $validated['note']), $this->member($request));

        return back()->with('success', 'Payment voided: the cash goes back in the box.');
    }

    public function receipt(PettyCashFund $fund, PettyCashPayment $payment): StreamedResponse
    {
        return Storage::disk(PettyCash::DISK)->download($payment->receipt_path, $payment->receipt_name);
    }

    public function requestTopUp(Request $request, PettyCashFund $fund): RedirectResponse
    {
        $this->requireCustodianOrManager($request, $fund);

        $this->pettyCash->requestTopUp($fund, $this->member($request));

        return back()->with('success', 'Top-up sent for a decision.');
    }

    public function approveTopUp(Request $request, PettyCashFund $fund, PettyCashTopUp $topUp): RedirectResponse
    {
        $validated = $request->validate([
            'on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->pettyCash->approveTopUp(
            $topUp,
            CarbonImmutable::parse((string) $validated['on']),
            $this->text($validated['note'] ?? null),
            $this->member($request),
        );

        return back()->with('success', 'Top-up approved: the box is back to its full amount once the cash reaches it.');
    }

    public function rejectTopUp(Request $request, PettyCashFund $fund, PettyCashTopUp $topUp): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        $this->pettyCash->rejectTopUp($topUp, trim((string) $validated['note']), $this->member($request));

        return back()->with('success', 'Top-up rejected.');
    }

    public function count(Request $request, PettyCashFund $fund): RedirectResponse
    {
        $validated = $request->validate([
            'counted' => ['required', 'string', Money::rule($fund->currency)],
            'counted_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $count = $this->pettyCash->count(
            $fund,
            Money::toMinor((string) $validated['counted'], $fund->currency),
            CarbonImmutable::parse((string) $validated['counted_on']),
            $this->text($validated['note'] ?? null),
            $this->member($request),
        );

        return back()->with('success', $count->difference_minor === 0 ? 'Counted: it matches.' : 'Counted: Finance puts the difference in cash over and short.');
    }

    public function changeCustodian(Request $request, PettyCashFund $fund): RedirectResponse
    {
        $validated = $request->validate(['custodian_id' => ['required', 'uuid']]);

        $this->pettyCash->changeCustodian($fund, $this->custodian((string) $validated['custodian_id']), $this->member($request));

        return back()->with('success', 'Custodian changed. Count the box at the handover.');
    }

    public function close(Request $request, PettyCashFund $fund): RedirectResponse
    {
        $validated = $request->validate([
            'on' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $this->pettyCash->close(
            $fund,
            CarbonImmutable::parse((string) $validated['on']),
            $this->member($request),
        );

        return back()->with('success', "{$fund->name} closed.");
    }

    /**
     * Hands the box to Finance, which keeps the organization's petty cash.
     */
    public function moveToFinance(Request $request, PettyCashFund $fund): RedirectResponse
    {
        if (! $this->pettyCash->keptInFinance()) {
            throw ValidationException::withMessages(['fund' => 'This organization does not have Finance: the box stays here.']);
        }

        $this->pettyCash->moveToFinance($fund, CarbonImmutable::today(), $this->member($request));

        return back()->with('success', "{$fund->name} is in Finance now. Its managers name a custodian there after counting the cash.");
    }

    /**
     * Where Finance keeps the organization's petty cash, when it does.
     */
    private function financeUrl(): ?string
    {
        if (! $this->pettyCash->keptInFinance()) {
            return null;
        }

        return FinanceLink::pettyCashUrl(app(CurrentOrganization::class)->require());
    }

    private function requireCustodianOrManager(Request $request, PettyCashFund $fund): void
    {
        abort_unless($this->can($request, 'finance.pettycash.manage')
            || ($fund->custodian_id === $this->member($request)->id && $this->can($request, 'finance.pettycash.record')), 403);
    }

    /**
     * Only someone who can pay from a box can hold one.
     */
    private function custodian(string $id): User
    {
        $custodian = Alerts::holders('finance.pettycash.record')->firstWhere('id', $id);
        if ($custodian === null) {
            throw ValidationException::withMessages(['custodian_id' => 'Choose someone who may record petty cash payments.']);
        }

        return $custodian;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function custodians(): array
    {
        return array_values(Alerts::holders('finance.pettycash.record')
            ->map(fn (User $user) => ['value' => $user->id, 'label' => $user->name])->sortBy('label')->all());
    }

    private function limit(): ?int
    {
        return app(CurrentOrganization::class)->require()->petty_cash_max_payment_minor;
    }
}
