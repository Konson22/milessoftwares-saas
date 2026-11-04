<?php

namespace App\Http\Controllers;

use App\Finance\Currencies;
use App\Finance\Money;
use App\Finance\PaymentPolicy;
use App\Finance\PaymentRequests;
use App\Models\Account;
use App\Models\Bill;
use App\Models\DimensionValue;
use App\Models\PaymentRequest;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payment requests: supplier payments, expenses, employee advances and
 * transfers on their way through review, approval and disbursement.
 */
class PaymentRequestController extends Controller
{
    public function index(Request $request, PaymentPolicy $policy): Response
    {
        $user = $this->user($request);
        $with = ['account', 'otherAccount', 'costCentre', 'preparer', 'reviewer', 'approver', 'disburser', 'closer', 'journal'];
        $open = PaymentRequest::query()->with($with)->whereIn('status', PaymentRequest::OPEN)->orderBy('created_at')->get();
        $closed = PaymentRequest::query()->with($with)->whereNotIn('status', PaymentRequest::OPEN)->orderByDesc('updated_at')->limit(50)->get();
        $separated = $policy->separated();
        $row = fn (PaymentRequest $payment) => $this->row($payment, $user, $separated);

        return Inertia::render('payment-requests/index', [
            'open' => $open->map($row)->values(),
            'closed' => $closed->map($row)->values(),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function create(Request $request): Response
    {
        $supplier = is_string($request->query('supplier')) ? Supplier::query()->find($request->query('supplier')) : null;

        return Inertia::render('payment-requests/create', [
            'kind' => in_array($request->query('kind'), PaymentRequest::KINDS, true) ? $request->query('kind') : PaymentRequest::EXPENSE,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'supplierId' => $supplier?->id,
            'bills' => $supplier === null ? [] : $supplier->bills()
                ->whereColumn('paid_minor', '<', 'total_minor')
                ->orderBy('bill_date')
                ->get()
                ->map(fn (Bill $bill) => BillController::row($bill)),
            'banks' => $this->accountOptions(bank: true),
            'accounts' => $this->accountOptions(bank: false),
            'dimensions' => $this->dimensionOptions(),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function store(Request $request, PaymentRequests $requests): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(PaymentRequest::KINDS)],
            'account_id' => ['required', 'uuid', $this->openAccount(bank: true)],
            'amount' => ['required', 'string', Money::rule()],
            'pay_on' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'required_unless:kind,supplier', 'string', 'max:200'],
            'rate' => ['nullable', 'string', Currencies::RATE_RULE],
            'reference' => ['nullable', 'string', 'max:64'],
            'supplier_id' => ['required_if:kind,supplier', 'nullable', 'uuid', $this->ours('suppliers')],
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*' => ['nullable', 'string', Money::rule()],
            'payee' => ['required_if:kind,advance', 'nullable', 'string', 'max:255'],
            'other_account_id' => ['required_if:kind,expense', 'nullable', 'uuid', $this->openAccount(bank: false)],
            'to_account_id' => ['required_if:kind,transfer', 'nullable', 'uuid', 'different:account_id', $this->openAccount(bank: true)],
            'received' => ['nullable', 'string', Money::rule()],
            'cost_centre_id' => ['nullable', 'uuid', $this->ours('dimension_values')->where('kind', DimensionValue::COST_CENTRE)],
            'project_id' => ['nullable', 'uuid', $this->ours('dimension_values')->where('kind', DimensionValue::PROJECT)],
        ]);

        $from = Account::query()->findOrFail((string) $data['account_id']);
        $kind = (string) $data['kind'];
        $extra = [
            'reference' => $data['reference'] ?? null,
            'rate' => ($data['rate'] ?? '') === '' ? null : Currencies::normalize((string) $data['rate']),
        ];
        $currency = $from->currency;
        $description = (string) ($data['description'] ?? '');

        if ($kind === PaymentRequest::SUPPLIER) {
            $extra['supplier'] = Supplier::query()->findOrFail((string) $data['supplier_id']);
            [$extra['allocations'], $currency] = $this->billAllocations($data['allocations'] ?? [], $from);
            $extra['currency'] = $currency;
            $description = $description === '' ? "Payment to {$extra['supplier']->name}" : $description;
        }
        if ($kind === PaymentRequest::EXPENSE) {
            $extra['other_account'] = Account::query()->findOrFail((string) $data['other_account_id']);
            $extra['payee'] = $data['payee'] ?? null;
        }
        if ($kind === PaymentRequest::ADVANCE) {
            $extra['payee'] = $data['payee'];
        }
        if ($kind === PaymentRequest::EXPENSE || $kind === PaymentRequest::ADVANCE) {
            $extra['cost_centre'] = isset($data['cost_centre_id']) ? DimensionValue::query()->findOrFail((string) $data['cost_centre_id']) : null;
            $extra['project'] = isset($data['project_id']) ? DimensionValue::query()->findOrFail((string) $data['project_id']) : null;
        }
        if ($kind === PaymentRequest::TRANSFER) {
            $to = Account::query()->findOrFail((string) $data['to_account_id']);
            $extra['other_account'] = $to;
            $extra['received_minor'] = ($data['received'] ?? '') === '' ? null : $this->amountIn((string) $data['received'], $to->currency, 'received');
        }

        $payment = $requests->submit($kind, $from, $this->amountIn((string) $data['amount'], $currency), CarbonImmutable::parse((string) $data['pay_on']), $description, $this->user($request), $extra);

        return redirect()->route('payment-requests.index')->with('success', $payment->status === PaymentRequest::APPROVED
            ? "{$payment->number} is below the approval limit and ready to disburse."
            : "{$payment->number} is waiting for ".($payment->status === PaymentRequest::PENDING_REVIEW ? 'review.' : 'approval.'));
    }

    public function review(Request $request, PaymentRequest $paymentRequest, PaymentRequests $requests): RedirectResponse
    {
        $requests->review($paymentRequest, $this->user($request));

        return back()->with('success', "{$paymentRequest->number} reviewed; it now waits for approval.");
    }

    public function approve(Request $request, PaymentRequest $paymentRequest, PaymentRequests $requests): RedirectResponse
    {
        $requests->approve($paymentRequest, $this->user($request));

        return back()->with('success', "{$paymentRequest->number} approved; it now waits to be disbursed.");
    }

    public function reject(Request $request, PaymentRequest $paymentRequest, PaymentRequests $requests): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        // A reviewer stops a request at review; past it, the decision is an approver's.
        $user = $this->user($request);
        abort_unless($paymentRequest->status === PaymentRequest::PENDING_REVIEW || $user->can('finance.payments.approve'), 403);

        $requests->reject($paymentRequest, $data['reason'], $user);

        return back()->with('success', "{$paymentRequest->number} rejected.");
    }

    public function withdraw(Request $request, PaymentRequest $paymentRequest, PaymentRequests $requests): RedirectResponse
    {
        $requests->withdraw($paymentRequest, $this->user($request));

        return back()->with('success', "{$paymentRequest->number} withdrawn.");
    }

    public function disburse(Request $request, PaymentRequest $paymentRequest, PaymentRequests $requests): RedirectResponse
    {
        $data = $request->validate([
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:64'],
        ]);

        $paid = $requests->disburse($paymentRequest, CarbonImmutable::parse($data['paid_on']), ($data['reference'] ?? '') === '' ? null : $data['reference'], $this->user($request));

        return back()->with('success', "{$paid->number} disbursed and posted to the books.");
    }

    public function settings(Request $request, PaymentPolicy $policy): RedirectResponse
    {
        $data = $request->validate([
            'payment_approval' => ['required', 'boolean'],
            'threshold' => ['nullable', 'string', Money::rule()],
            'payment_review' => ['required', 'boolean'],
            'payment_separation' => ['required', 'boolean'],
        ]);

        $policy->configure(
            (bool) $data['payment_approval'],
            ($data['threshold'] ?? '') === '' ? null : Money::toMinor($data['threshold']),
            (bool) $data['payment_review'],
            (bool) $data['payment_separation'],
        );

        return back()->with('success', 'Payment approval rules saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PaymentRequest $payment, User $user, bool $separated): array
    {
        $own = $payment->prepared_by === $user->id;

        return [
            'id' => $payment->id,
            'number' => $payment->number,
            'kind' => $payment->kind,
            'status' => $payment->status,
            'description' => $payment->description,
            'payee' => $payment->payee,
            'from' => $payment->account->label(),
            'to' => $payment->otherAccount?->label(),
            'costCentre' => $payment->costCentre?->label(),
            'amountMinor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'booksMinor' => $payment->books_minor,
            'payOn' => $payment->pay_on->toDateString(),
            'preparedBy' => $payment->preparer->name,
            'preparedAt' => $payment->created_at->toIso8601String(),
            'reviewedBy' => $payment->reviewer?->name,
            'approvedBy' => $payment->approver?->name,
            'disbursedBy' => $payment->disburser?->name,
            'closedBy' => $payment->closer?->name,
            'closeNote' => $payment->close_note,
            'journal' => $payment->journal === null ? null : ['id' => $payment->journal->id, 'number' => $payment->journal->number],
            'own' => $own,
            // For showing and hiding buttons: App\Finance\PaymentRequests decides.
            'canReview' => $payment->status === PaymentRequest::PENDING_REVIEW && ! ($separated && $own) && $user->can('finance.payments.review'),
            'canApprove' => $payment->status === PaymentRequest::PENDING_APPROVAL && ! ($separated && ($own || $payment->reviewed_by === $user->id)) && $user->can('finance.payments.approve'),
            'canDisburse' => $payment->status === PaymentRequest::APPROVED
                && ! ($separated && ($own || $payment->approved_by === $user->id || $payment->reviewed_by === $user->id))
                && $user->can('finance.payments.disburse'),
            'canReject' => in_array($payment->status, PaymentRequest::OPEN, true) && ! $own
                && ($user->can('finance.payments.approve') || ($payment->status === PaymentRequest::PENDING_REVIEW && $user->can('finance.payments.review'))),
            'canWithdraw' => in_array($payment->status, PaymentRequest::OPEN, true) && $own,
        ];
    }
}
