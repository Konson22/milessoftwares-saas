<?php

namespace App\Http\Controllers;

use App\Finance\Money;
use App\Finance\ProjectSpending;
use App\Models\Account;
use App\Models\PaymentRequest;
use App\Models\ProjectSpendingRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Spending Projects handed to Finance: taken in as a payment request, or
 * refused. What happens next is the payment request's (review, approval,
 * disbursement), on the payment requests page.
 */
class ProjectSpendingController extends Controller
{
    public function index(Request $request): Response
    {
        $row = fn (ProjectSpendingRequest $r) => [
            'id' => $r->id,
            'project' => "{$r->project_code}: {$r->project_name}",
            'description' => $r->description,
            'supplier' => $r->supplier,
            'grantReference' => $r->grant_reference,
            'category' => $r->category,
            'currency' => $r->currency,
            'amountMinor' => $r->amount_minor,
            'amountMajor' => Money::toMajor($r->amount_minor, $r->currency),
            'revision' => $r->revision,
            'status' => $r->status,
            'requestedAt' => $r->requested_at->toDateString(),
            'cancellationRequested' => $r->cancellation_requested_at !== null,
            'decisionNote' => $r->decision_note,
            'paymentRequest' => $r->paymentRequest === null ? null : ['number' => $r->paymentRequest->number, 'status' => $r->paymentRequest->status, 'amountMinor' => $r->paymentRequest->amount_minor],
        ];

        return Inertia::render('project-requests/index', [
            'waiting' => ProjectSpendingRequest::query()->with('paymentRequest')->where('status', ProjectSpending::RECEIVED)->orderBy('created_at')->get()->map($row)->values(),
            'recent' => ProjectSpendingRequest::query()->with('paymentRequest')->where('status', '!=', ProjectSpending::RECEIVED)->orderByDesc('updated_at')->limit(50)->get()->map($row)->values(),
            'banks' => $this->accountOptions(bank: true),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function prepare(Request $request, ProjectSpendingRequest $projectRequest, ProjectSpending $spending): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid', $this->openAccount(bank: true)],
            'amount' => ['required', 'string', Money::rule()],
            'pay_on' => ['required', 'date_format:Y-m-d'],
        ]);

        $payment = $spending->prepare(
            $projectRequest,
            Account::query()->findOrFail((string) $data['account_id']),
            $this->amountIn((string) $data['amount'], $projectRequest->currency),
            CarbonImmutable::parse((string) $data['pay_on']),
            $this->user($request),
        );

        return redirect()->route('project-requests.index')->with('success', $payment->status === PaymentRequest::APPROVED
            ? "Taken in as {$payment->number}, below the approval limit and ready to disburse."
            : "Taken in as {$payment->number}, waiting for ".($payment->status === PaymentRequest::PENDING_REVIEW ? 'review.' : 'approval.'));
    }

    public function refuse(Request $request, ProjectSpendingRequest $projectRequest, ProjectSpending $spending): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $spending->refuse($projectRequest, trim((string) $data['reason']), $this->user($request));

        return redirect()->route('project-requests.index')->with('success', 'Refused. Projects has been told why.');
    }
}
