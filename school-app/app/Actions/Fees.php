<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\FeeItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Support\DocumentNumbers;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Events\Outbox;

/**
 * Student fees: a term's fee items billed to each enrolled student once
 * (finance.invoice.issued, a service: no cost of goods), paid in one or more
 * payments (finance.payment.received), voided while nothing is paid
 * (finance.invoice.voided), after which the student can be billed again.
 */
class Fees
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly Outbox $outbox,
    ) {}

    /**
     * Invoices every enrolled student of the class (or of every class) not
     * yet billed for the term. Returns the invoices issued.
     *
     * @return list<Invoice>
     *
     * @throws ValidationException
     */
    public function bill(Term $term, ?SchoolClass $class, ?CarbonImmutable $dueOn, User $by): array
    {
        return DB::transaction(function () use ($term, $class, $dueOn, $by) {
            $items = FeeItem::query()->where('term_id', $term->id)->orderBy('name')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['term_id' => "Set the fees for {$term->name} first."]);
            }

            $billed = Invoice::query()->where('term_id', $term->id)->where('status', '!=', Invoice::VOIDED)->select('student_id');
            $students = Student::query()
                ->where('status', Student::ACTIVE)
                ->whereNotNull('school_class_id')
                ->when($class !== null, fn ($query) => $query->where('school_class_id', $class?->id))
                ->whereNotIn('id', $billed)
                ->orderBy('number')
                ->lockForUpdate()
                ->get();

            $invoices = [];
            foreach ($students as $student) {
                $fees = $items->filter(fn (FeeItem $item) => $item->school_class_id === null || $item->school_class_id === $student->school_class_id)->values();
                if ($fees->isEmpty() || $fees->sum('amount_minor') === 0) {
                    continue;
                }

                $invoices[] = $this->issue($student, $term, array_values($fees->map(fn (FeeItem $item) => ['description' => $item->name, 'amount_minor' => $item->amount_minor])->all()), $dueOn, $by);
            }

            return $invoices;
        });
    }

    /**
     * @param  list<array{description: string, amount_minor: int}>  $lines
     */
    private function issue(Student $student, Term $term, array $lines, ?CarbonImmutable $dueOn, User $by): Invoice
    {
        $invoice = Invoice::query()->create([
            'number' => $this->numbers->next('invoice', 'INV'),
            'student_id' => $student->id,
            'term_id' => $term->id,
            'currency' => Money::currency(),
            'total_minor' => array_sum(array_column($lines, 'amount_minor')),
            'paid_minor' => 0,
            'status' => Invoice::ISSUED,
            'issued_on' => today(),
            'due_on' => $dueOn,
            'issued_by' => $by->id,
        ]);

        $events = [];
        foreach ($lines as $line) {
            $description = Str::limit("{$line['description']}, {$term->title()}", 250);
            $invoice->lines()->create(['description' => $description, 'amount_minor' => $line['amount_minor']]);
            $events[] = ['description' => $description, 'quantity' => 1, 'amount_minor' => $line['amount_minor'], 'revenue_account' => null];
        }

        $this->outbox->record('finance', 'finance.invoice.issued', 1, "invoice:{$invoice->id}:issued", [
            'invoice_number' => $invoice->number,
            'branch_id' => null,
            'customer_reference' => Str::limit("{$student->number} {$student->name()}", 125),
            'currency' => $invoice->currency,
            'total_minor' => $invoice->total_minor,
            'tax_minor' => 0,
            'due_on' => $invoice->due_on?->toDateString(),
            'lines' => $events,
        ]);

        return $invoice;
    }

    /**
     * @throws ValidationException
     */
    public function pay(Invoice $invoice, int $amountMinor, PaymentMethod $method, ?string $reference, CarbonImmutable $paidOn, User $by): Payment
    {
        return DB::transaction(function () use ($invoice, $amountMinor, $method, $reference, $paidOn, $by) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Invoice::ISSUED) {
                throw ValidationException::withMessages(['amount' => "Invoice {$locked->number} is {$locked->status}: it takes no payments."]);
            }
            if ($amountMinor < 1 || $amountMinor > $locked->balanceMinor()) {
                throw ValidationException::withMessages(['amount' => 'Between '.Money::format(1, $locked->currency).' and the balance, '.Money::format($locked->balanceMinor(), $locked->currency).'.']);
            }

            $payment = $locked->payments()->create([
                'number' => $this->numbers->next('payment', 'RCT'),
                'amount_minor' => $amountMinor,
                'method' => $method,
                'reference' => blank($reference) ? null : trim((string) $reference),
                'paid_on' => $paidOn,
                'received_by' => $by->id,
            ]);

            $paid = $locked->paid_minor + $amountMinor;
            $locked->forceFill([
                'paid_minor' => $paid,
                'status' => $paid >= $locked->total_minor ? Invoice::PAID : Invoice::ISSUED,
            ])->save();

            $this->outbox->record('finance', 'finance.payment.received', 1, "payment:{$payment->id}", [
                'payment_reference' => $payment->number,
                'invoice_number' => $locked->number,
                'branch_id' => null,
                'currency' => $locked->currency,
                'amount_minor' => $amountMinor,
                'method' => $method->value,
                'processor_reference' => $payment->reference,
            ], $paidOn);

            return $payment;
        });
    }

    /**
     * @throws ValidationException
     */
    public function void(Invoice $invoice, string $reason, User $by): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $by) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Invoice::ISSUED || $locked->paid_minor > 0) {
                throw ValidationException::withMessages(['reason' => "Invoice {$locked->number} is ".($locked->status === Invoice::VOIDED ? 'already voided.' : 'paid in part or whole: only unpaid invoices can be voided.')]);
            }

            $locked->forceFill([
                'status' => Invoice::VOIDED,
                'voided_by' => $by->id,
                'voided_at' => now(),
                'void_reason' => trim($reason),
            ])->save();

            $this->outbox->record('finance', 'finance.invoice.voided', 1, "invoice:{$locked->id}:void", [
                'invoice_number' => $locked->number,
                'branch_id' => null,
                'currency' => $locked->currency,
                'total_minor' => $locked->total_minor,
                'reason' => Str::limit(trim($reason), 250),
                'refund' => null,
            ], $locked->voided_at);

            return $locked;
        });
    }
}
