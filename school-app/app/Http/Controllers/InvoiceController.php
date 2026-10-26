<?php

namespace App\Http\Controllers;

use App\Actions\Fees;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\Student;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fee invoices, their payments and voids.
 */
class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $termId = $request->string('term')->toString();
        $search = $request->string('search')->toString();

        $invoices = Invoice::query()
            ->when(in_array($status, Invoice::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when($termId !== '', fn ($query) => $query->where('term_id', $termId))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereRaw('lower(number) like ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereIn('student_id', Student::query()->search($search)->select('id'))))
            ->with(['student:id,number,first_name,last_name', 'term.academicYear'])
            ->latest('issued_on')
            ->latest('number')
            ->limit(300)
            ->get();
        $open = Invoice::query()->where('status', Invoice::ISSUED);

        return Inertia::render('invoices/index', [
            'invoices' => $invoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'student' => $invoice->student->name(),
                'studentNumber' => $invoice->student->number,
                'term' => $invoice->term?->title(),
                'issuedOn' => $invoice->issued_on->toDateString(),
                'dueOn' => $invoice->due_on?->toDateString(),
                'status' => $invoice->status,
                'overdue' => $invoice->overdue(),
                'totalMinor' => $invoice->total_minor,
                'balanceMinor' => $invoice->balanceMinor(),
            ]),
            'filters' => ['status' => $status, 'term' => $termId, 'search' => $search],
            'terms' => $this->termOptions(),
            'outstandingMinor' => (int) (clone $open)->sum('total_minor') - (int) (clone $open)->sum('paid_minor'),
        ]);
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        $invoice->load(['student.schoolClass:id,name', 'term.academicYear', 'lines', 'payments.receiver:id,name', 'issuer:id,name', 'voider:id,name']);

        return Inertia::render('invoices/show', [
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'overdue' => $invoice->overdue(),
                'currency' => $invoice->currency,
                'student' => [
                    'id' => $invoice->student->id,
                    'number' => $invoice->student->number,
                    'name' => $invoice->student->name(),
                    'class' => $invoice->student->schoolClass?->name,
                    'guardianName' => $invoice->student->guardian_name,
                    'guardianPhone' => $invoice->student->guardian_phone,
                ],
                'term' => $invoice->term?->title(),
                'issuedOn' => $invoice->issued_on->toDateString(),
                'dueOn' => $invoice->due_on?->toDateString(),
                'issuedBy' => $invoice->issuer->name,
                'totalMinor' => $invoice->total_minor,
                'paidMinor' => $invoice->paid_minor,
                'balanceMinor' => $invoice->balanceMinor(),
                'balance' => Money::toMajor($invoice->balanceMinor(), $invoice->currency),
                'voidedAt' => $invoice->voided_at?->toIso8601String(),
                'voidedBy' => $invoice->voider?->name,
                'voidReason' => $invoice->void_reason,
                'lines' => $invoice->lines->map(fn (InvoiceLine $line) => [
                    'id' => $line->id,
                    'description' => $line->description,
                    'amountMinor' => $line->amount_minor,
                ]),
                'payments' => $invoice->payments->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'number' => $payment->number,
                    'paidOn' => $payment->paid_on->toDateString(),
                    'method' => $payment->method->label(),
                    'reference' => $payment->reference,
                    'amountMinor' => $payment->amount_minor,
                    'receivedBy' => $payment->receiver->name,
                ]),
            ],
            'paymentMethods' => PaymentMethod::options(),
            'today' => today()->toDateString(),
            'can' => [
                'pay' => $this->can($request, 'school.fees.manage'),
                'void' => $this->can($request, 'school.fees.void'),
            ],
        ]);
    }

    public function pay(Request $request, Invoice $invoice, Fees $fees): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'string', Money::rule($invoice->currency)],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:128'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $payment = $fees->pay(
            $invoice,
            Money::toMinor($validated['amount'], $invoice->currency),
            PaymentMethod::from($validated['method']),
            $validated['reference'] ?? null,
            CarbonImmutable::parse($validated['paid_on']),
            $this->member($request),
        );

        return back()->with('success', 'Payment '.$payment->number.' of '.Money::format($payment->amount_minor, $invoice->currency).' recorded.');
    }

    public function void(Request $request, Invoice $invoice, Fees $fees): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $fees->void($invoice, $validated['reason'], $this->member($request));

        return back()->with('success', "Invoice {$invoice->number} voided: the student can be billed again for the term.");
    }
}
