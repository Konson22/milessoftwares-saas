<?php

namespace App\Actions;

use App\Access\VisibleThroughProjectScope;
use App\Audit\Audit;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Enums\GrantStatus;
use App\Enums\PurchaseRequestStatus;
use App\FinanceIntegration\FinanceRequests;
use App\Models\Expense;
use App\Models\FinanceRequest;
use App\Models\GrantAllocation;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ActivityLog;
use App\Support\DocumentNumbers;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Buying for a project: a request is approved, quotes are compared, one is
 * chosen, an order is raised, goods are received, the invoice is recorded, and
 * finance pays.
 *
 * Raising an order requests spending through App\Actions\Spending. That is the
 * only commitment: it is approved against the budget line, the category and
 * the grant with the usual bands, and paid the usual way (by Finance, where
 * Finance pays). A request is approved against a line of the approved budget
 * and must fit what is left on it, but reserves nothing (ADR-0020).
 * Receipts record accepted and rejected goods; supplier invoices are matched
 * to the order and never exceed its commitment; closing an order short
 * brings its commitment down to what was invoiced. Spending::pay, and the
 * hand-over to Finance, wait until requirePayable says yes.
 * Every change is audited.
 */
final class Procurement
{
    public const DISK = 'local';

    /** Fewer quotes than this needs a written reason. */
    public const MIN_QUOTES = 3;

    /**
     * budget_line_key names a line of the approved budget (ADR-0020). Left
     * out, the one approved line of the category is taken when there is
     * exactly one; otherwise it is chosen before the request is submitted.
     *
     * @param  array{title: string, justification: string, category: CostCategory, needed_by: ?string, grant_id: ?string, budget_line_key?: ?string}  $details
     * @param  list<array{description: string, quantity: string, unit: ?string, unit_price_minor: int}>  $lines
     *
     * @throws ValidationException
     */
    public static function request(Project $project, array $details, array $lines, User $by): PurchaseRequest
    {
        self::requireOpen($project, 'title');
        if ($details['category'] === CostCategory::Labour) {
            throw ValidationException::withMessages(['category' => 'Labour comes from time logged.']);
        }
        if ($details['grant_id'] !== null && ! self::fundedBy($project, $details['grant_id'])) {
            throw ValidationException::withMessages(['grant_id' => 'That grant does not fund this project.']);
        }
        $details['budget_line_key'] = self::lineFor($project, $details['category'], $details['budget_line_key'] ?? null, required: false);

        return DB::transaction(function () use ($project, $details, $lines, $by) {
            $priced = array_map(fn (array $line) => [...$line, 'amount_minor' => Quantity::price($line['quantity'], $line['unit_price_minor'])], $lines);
            $request = PurchaseRequest::query()->create([
                ...$details,
                'project_id' => $project->id,
                'number' => app(DocumentNumbers::class)->next('purchase_request', 'PR', 6),
                'status' => PurchaseRequestStatus::Draft,
                'estimate_minor' => array_sum(array_column($priced, 'amount_minor')),
                'requested_by' => $by->id,
            ]);
            foreach ($priced as $line) {
                $request->lines()->create($line);
            }
            Audit::record('purchase.requested', $request, ['number' => $request->number, 'estimate_minor' => $request->estimate_minor], actor: $by);

            return $request;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function submit(PurchaseRequest $request, User $by, ?string $lineKey = null): void
    {
        self::requireStatus($request, PurchaseRequestStatus::Draft, 'Only a draft is submitted.');
        self::requireOpen($request->project, 'status');
        // It goes for approval only against a line of the approved budget.
        $key = self::lineFor($request->project, $request->category, $lineKey ?? $request->budget_line_key, required: true);

        $request->forceFill(['status' => PurchaseRequestStatus::Submitted, 'submitted_at' => now(), 'budget_line_key' => $key])->save();
        Audit::record('purchase.submitted', $request, ['number' => $request->number], actor: $by);
        Alerts::tell(
            Alerts::holders('procurement.approve')->reject(fn (User $user) => $user->id === $request->requested_by),
            UserNotification::APPROVAL,
            "{$request->number} needs your approval",
            $request->title,
            route('procurement.show', ['purchaseRequest' => $request->id], false),
            $by,
        );
    }

    /**
     * @throws ValidationException
     */
    public static function approve(PurchaseRequest $request, ?string $note, User $by): void
    {
        self::decide($request, PurchaseRequestStatus::Approved, $note, $by);
    }

    /**
     * @throws ValidationException
     */
    public static function reject(PurchaseRequest $request, string $reason, User $by): void
    {
        self::decide($request, PurchaseRequestStatus::Rejected, $reason, $by);
    }

    /**
     * Whoever asked doesn't get to say yes. Saying yes needs the budget: the
     * line still approved, and the estimate within what is left on the line
     * and in its category (ADR-0020). It runs on the locked project, like
     * Spending::approve, so two approvals never both take the last of a
     * line. Approval reserves nothing: the commitment is made when the
     * order's spending is approved, and checked again then.
     *
     * @throws ValidationException
     */
    private static function decide(PurchaseRequest $request, PurchaseRequestStatus $to, ?string $note, User $by): void
    {
        $facts = DB::transaction(function () use ($request, $to, $note, $by) {
            $project = Project::query()->whereKey($request->project_id)->lockForUpdate()->firstOrFail();
            $request->refresh();
            self::requireStatus($request, PurchaseRequestStatus::Submitted, 'Only a submitted request is decided.');
            if ($request->requested_by === $by->id) {
                throw ValidationException::withMessages(['status' => 'Whoever asked for this cannot also decide it.']);
            }

            $facts = ['number' => $request->number, 'note' => $note];
            if ($to === PurchaseRequestStatus::Approved) {
                $facts += self::requireBudget($project, $request);
            }
            $request->forceFill(['status' => $to, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note])->save();

            return $facts;
        });
        Audit::record('purchase.'.$to->value, $request, $facts, actor: $by);
        $requester = User::query()->findOrFail($request->requested_by);
        Alerts::tell(
            [$requester],
            UserNotification::DECISION,
            "{$request->number} was ".mb_strtolower($to->label()),
            $note ?? $request->title,
            route('procurement.show', ['purchaseRequest' => $request->id], false),
            $by,
        );
    }

    /**
     * @throws ValidationException
     */
    public static function cancel(PurchaseRequest $request, User $by): void
    {
        if (! in_array($request->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Submitted, PurchaseRequestStatus::Approved], true)) {
            throw ValidationException::withMessages(['status' => 'Only a request not yet ordered is cancelled.']);
        }

        $request->forceFill(['status' => PurchaseRequestStatus::Cancelled])->save();
        Audit::record('purchase.cancelled', $request, ['number' => $request->number], actor: $by);
    }

    /**
     * @param  array{supplier_id: string, amount_minor: int, reference: ?string, valid_until: ?string}  $details
     *
     * @throws ValidationException
     */
    public static function addQuote(PurchaseRequest $request, array $details, ?UploadedFile $file, User $by): PurchaseQuote
    {
        self::requireBuying($request);
        if ($details['amount_minor'] <= 0) {
            throw ValidationException::withMessages(['amount' => 'More than zero.']);
        }
        if ($request->quotes()->where('supplier_id', $details['supplier_id'])->exists()) {
            throw ValidationException::withMessages(['supplier_id' => 'This supplier has quoted already. Remove that quote to replace it.']);
        }

        $path = $file?->store("organizations/{$request->organization_id}/purchase-requests/{$request->id}", self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['document' => 'The file could not be stored.']);
        }

        $quote = $request->quotes()->create([
            ...$details,
            'original_name' => $file === null ? null : mb_substr($file->getClientOriginalName(), 0, 255),
            'path' => $path,
            'created_by' => $by->id,
        ]);
        Audit::record('purchase.quote_added', $request, ['number' => $request->number, 'supplier_id' => $details['supplier_id'], 'amount_minor' => $details['amount_minor']], actor: $by);

        return $quote;
    }

    /**
     * @throws ValidationException
     */
    public static function removeQuote(PurchaseRequest $request, PurchaseQuote $quote, User $by): void
    {
        self::requireBuying($request);
        if (PurchaseOrder::query()->where('purchase_quote_id', $quote->id)->exists()) {
            throw ValidationException::withMessages(['quote' => 'An order was raised from this quote: it stays on record.']);
        }

        DB::transaction(function () use ($request, $quote, $by) {
            if ($request->selected_quote_id === $quote->id) {
                $request->forceFill(['selected_quote_id' => null, 'selection_reason' => null, 'selected_by' => null])->save();
            }
            $quote->delete();
            Audit::record('purchase.quote_removed', $request, ['number' => $request->number, 'supplier_id' => $quote->supplier_id, 'amount_minor' => $quote->amount_minor], actor: $by);
        });
        if ($quote->path !== null) {
            Storage::disk(self::DISK)->delete($quote->path);
        }
    }

    /**
     * Too few quotes, or not the cheapest, needs a written reason: that is what
     * an auditor or a donor will ask to see.
     *
     * @throws ValidationException
     */
    public static function choose(PurchaseRequest $request, PurchaseQuote $quote, ?string $reason, User $by): void
    {
        self::requireBuying($request);
        self::requireValid($quote);
        $quotes = $request->quotes()->get();
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        if ($quotes->count() < self::MIN_QUOTES && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'Fewer than '.self::MIN_QUOTES.' quotes: say why that is enough.']);
        }
        if ($quote->amount_minor > (int) $quotes->min('amount_minor') && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'This is not the lowest quote: say why it is the right one.']);
        }

        $request->forceFill(['selected_quote_id' => $quote->id, 'selection_reason' => $reason, 'selected_by' => $by->id])->save();
        Audit::record('purchase.quote_chosen', $request, ['number' => $request->number, 'supplier_id' => $quote->supplier_id, 'amount_minor' => $quote->amount_minor, 'quotes' => $quotes->count(), 'reason' => $reason], actor: $by);
    }

    /**
     * Requests the spending. Finance approves that before anything is committed,
     * and whoever raised the order can't approve or pay it (App\Actions\Spending).
     *
     * @throws ValidationException
     */
    public static function raiseOrder(PurchaseRequest $request, ?string $terms, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($request, $terms, $by) {
            $request = PurchaseRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            self::requireBuying($request);
            $quote = $request->selected_quote_id === null ? null : PurchaseQuote::query()->with('supplier')->find($request->selected_quote_id);
            if ($quote === null) {
                throw ValidationException::withMessages(['order' => 'Choose a quote first.']);
            }
            self::requireValid($quote);

            $number = app(DocumentNumbers::class)->next('purchase_order', 'PO', 6);
            $expense = Spending::request($request->project, [
                'category' => $request->category,
                'description' => mb_substr("{$number} {$request->title}", 0, 255),
                'supplier' => $quote->supplier->name,
                'amount_minor' => $quote->amount_minor,
                'grant_id' => $request->grant_id,
                // The line the request was approved against: the commitment is checked on it.
                'budget_line_key' => $request->budget_line_key,
            ], $by);
            $order = PurchaseOrder::query()->create([
                'purchase_request_id' => $request->id,
                'purchase_quote_id' => $quote->id,
                'supplier_id' => $quote->supplier_id,
                'expense_id' => $expense->id,
                'number' => $number,
                'amount_minor' => $quote->amount_minor,
                'terms' => $terms,
                'created_by' => $by->id,
            ]);
            $request->forceFill(['status' => PurchaseRequestStatus::Ordered])->save();
            Audit::record('purchase.order_raised', $order, ['number' => $number, 'request' => $request->number, 'amount_minor' => $order->amount_minor], actor: $by);
            ActivityLog::record($request->project, $by, "Raised {$number} to {$quote->supplier->name}, ".Money::format($order->amount_minor, $request->project->currency));

            return $order;
        });
    }

    /**
     * Goods arrive only against money committed. Part deliveries are fine; more
     * than was ordered is not. Rejected goods are recorded with the delivery
     * and do not count as received: what is still to come is what was
     * ordered less what was accepted (ADR-0020). One evidence file may go
     * with the receipt.
     *
     * @param  array<string, string>  $quantities  accepted, by request line id
     * @param  array<string, string>  $rejected  rejected, by request line id
     *
     * @throws ValidationException
     */
    public static function receive(PurchaseOrder $order, array $quantities, CarbonImmutable $on, ?string $note, User $by, array $rejected = [], ?UploadedFile $evidence = null): void
    {
        $path = null;
        DB::transaction(function () use ($order, $quantities, $rejected, $evidence, $on, $note, $by, &$path) {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->expense->status !== ExpenseStatus::Approved) {
                throw ValidationException::withMessages(['receipt' => 'Finance must approve the order before goods are received against it.']);
            }
            if ($order->isClosed()) {
                throw ValidationException::withMessages(['receipt' => "{$order->number} is closed: nothing more is received against it."]);
            }

            $lines = PurchaseRequestLine::query()->where('purchase_request_id', $order->purchase_request_id)->get()->keyBy('id');
            $received = $order->receivedByLine();
            $wanted = [];
            foreach (array_unique([...array_keys($quantities), ...array_keys($rejected)]) as $lineId) {
                $accepted = $quantities[$lineId] ?? '0';
                $refused = $rejected[$lineId] ?? '0';
                if (Quantity::compare($accepted, '0') === 0 && Quantity::compare($refused, '0') === 0) {
                    continue;
                }
                $line = $lines->get($lineId);
                if ($line === null) {
                    throw ValidationException::withMessages(['receipt' => 'That line is not on this order.']);
                }
                $left = Quantity::subtract((string) $line->quantity, $received[$lineId] ?? '0');
                if (Quantity::compare($accepted, $left) > 0) {
                    throw ValidationException::withMessages(['receipt' => "{$line->description}: only {$left} left to receive."]);
                }
                $wanted[$lineId] = ['accepted' => $accepted, 'rejected' => $refused];
            }
            if ($wanted === []) {
                throw ValidationException::withMessages(['receipt' => 'Enter what arrived.']);
            }

            $path = $evidence?->store("organizations/{$order->organization_id}/purchase-orders/{$order->id}/receipts", self::DISK);
            if ($path === false) {
                throw ValidationException::withMessages(['evidence' => 'The file could not be stored.']);
            }
            $receipt = $order->receipts()->create([
                'received_on' => $on,
                'note' => $note,
                'received_by' => $by->id,
                'original_name' => $evidence === null ? null : mb_substr($evidence->getClientOriginalName(), 0, 255),
                'path' => $path,
            ]);
            foreach ($wanted as $lineId => $quantity) {
                $receipt->lines()->create(['purchase_request_line_id' => $lineId, 'quantity' => $quantity['accepted'], 'rejected_quantity' => $quantity['rejected']]);
            }
            Audit::record('purchase.goods_received', $order, [
                'number' => $order->number,
                'lines' => array_map(fn (array $q) => $q['accepted'], $wanted),
                'rejected' => array_filter(array_map(fn (array $q) => $q['rejected'], $wanted), fn (string $q) => Quantity::compare($q, '0') > 0),
                'evidence' => $receipt->original_name,
                'on' => $on->toDateString(),
            ], actor: $by);
            // Integrated with Finance: payable now (received and invoiced)? Then Finance is asked to pay.
            app(FinanceRequests::class)->submitIfPayable($order->refresh(), $by);
        });
    }

    /**
     * A supplier's invoice against the order (ADR-0020). Refused when:
     * - the order's spending is not approved, or is with Finance or paid
     *   already (what Finance pays was fixed when it was sent);
     * - the order was closed (its commitment is the invoices it had);
     * - it is in another currency than the project's;
     * - the invoices would come to more than the commitment;
     * - this supplier's invoice number is recorded already, here or on any
     *   other order (also as an older single reference).
     * Recording an invoice approves nothing and pays nothing.
     *
     * @param  array{number: string, amount_minor: int, currency: string, invoice_on: CarbonImmutable, due_on: ?CarbonImmutable}  $details
     *
     * @throws ValidationException
     */
    public static function recordInvoice(PurchaseOrder $order, array $details, ?UploadedFile $file, User $by): SupplierInvoice
    {
        $path = null;
        try {
            return DB::transaction(function () use ($order, $details, $file, $by, &$path) {
                $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $expense = $order->expense;
                $project = Project::query()->findOrFail($expense->project_id);
                self::requireInvoiceable($order, 'invoice');
                if ($order->isClosed()) {
                    throw ValidationException::withMessages(['invoice' => "{$order->number} is closed: its invoices are settled."]);
                }
                $number = trim($details['number']);
                $key = SupplierInvoice::keyOf($number);
                if ($key === '') {
                    throw ValidationException::withMessages(['number' => 'The supplier\'s invoice number.']);
                }
                if (strtoupper($details['currency']) !== $project->currency) {
                    throw ValidationException::withMessages(['currency' => "This order is in {$project->currency}: an invoice in another currency is not matched to it."]);
                }
                if ($details['amount_minor'] <= 0) {
                    throw ValidationException::withMessages(['amount' => 'More than zero.']);
                }
                $invoiced = $order->invoicedMinor() + ($order->hasLegacyInvoice() ? $expense->amount_minor : 0);
                if ($invoiced + $details['amount_minor'] > $expense->amount_minor) {
                    throw ValidationException::withMessages(['amount' => 'The invoices would come to more than the order commits ('.Money::format($expense->amount_minor, $project->currency).', '.Money::format($invoiced, $project->currency).' invoiced so far). More needs a new request.']);
                }
                self::refuseDuplicateInvoice($order->supplier_id, $key);

                $path = $file?->store("organizations/{$order->organization_id}/purchase-orders/{$order->id}/invoices", self::DISK);
                if ($path === false) {
                    throw ValidationException::withMessages(['document' => 'The file could not be stored.']);
                }
                $invoice = $order->invoices()->create([
                    'supplier_id' => $order->supplier_id,
                    'number' => mb_substr($number, 0, 100),
                    'number_key' => mb_substr($key, 0, 100),
                    'amount_minor' => $details['amount_minor'],
                    'currency' => $project->currency,
                    'invoice_on' => $details['invoice_on'],
                    'due_on' => $details['due_on'],
                    'original_name' => $file === null ? null : mb_substr($file->getClientOriginalName(), 0, 255),
                    'path' => $path,
                    'status' => SupplierInvoice::RECORDED,
                    'recorded_by' => $by->id,
                ]);
                // Older readers see the first invoice as the order's reference.
                if ($order->invoice_reference === null) {
                    $order->forceFill(['invoice_reference' => $invoice->number, 'invoice_on' => $invoice->invoice_on])->save();
                }
                Audit::record('purchase.invoice_recorded', $order, [
                    'number' => $order->number,
                    'reference' => $invoice->number,
                    'amount_minor' => $invoice->amount_minor,
                    'currency' => $invoice->currency,
                    'invoiced_minor' => $invoiced + $invoice->amount_minor,
                    'commitment_minor' => $expense->amount_minor,
                ], actor: $by);
                app(FinanceRequests::class)->submitIfPayable($order->refresh(), $by);

                return $invoice;
            });
        } catch (UniqueConstraintViolationException) {
            // Two people entering the same invoice at once: the index decides.
            throw ValidationException::withMessages(['number' => 'This supplier\'s invoice is recorded already.']);
        }
    }

    /**
     * A wrong invoice is voided, never edited or deleted: until the spending
     * is sent to Finance or paid.
     *
     * @throws ValidationException
     */
    public static function voidInvoice(SupplierInvoice $invoice, string $reason, User $by): void
    {
        DB::transaction(function () use ($invoice, $reason, $by) {
            $order = PurchaseOrder::query()->whereKey($invoice->purchase_order_id)->lockForUpdate()->firstOrFail();
            $invoice->refresh();
            if (! $invoice->isLive()) {
                throw ValidationException::withMessages(['invoice' => 'It is voided already.']);
            }
            self::requireInvoiceable($order, 'invoice');
            if ($order->isClosed()) {
                throw ValidationException::withMessages(['invoice' => "{$order->number} was closed on its invoices: they stay."]);
            }

            $invoice->forceFill(['status' => SupplierInvoice::VOIDED, 'voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => $reason])->save();
            $first = $order->invoices()->live()->first();
            $order->forceFill(['invoice_reference' => $first?->number, 'invoice_on' => $first?->invoice_on])->save();
            Audit::record('purchase.invoice_voided', $order, ['number' => $order->number, 'reference' => $invoice->number, 'amount_minor' => $invoice->amount_minor, 'reason' => $reason], actor: $by);
        });
    }

    /**
     * Closes an order with what arrived (ADR-0020): no more is coming, or the
     * supplier billed less. Its commitment (the order's spending) becomes
     * what the supplier invoiced, and the rest goes back to the budget line.
     * The order keeps what was ordered; who closed it, when and why are kept.
     * Needs something received and invoiced, and nothing yet sent to Finance
     * or paid. With nothing received, the order's spending is cancelled
     * instead (Spending::cancel).
     *
     * @throws ValidationException
     */
    public static function closeOrder(PurchaseOrder $order, string $reason, User $by): void
    {
        DB::transaction(function () use ($order, $reason, $by) {
            $order = PurchaseOrder::query()->whereKey($order->id)->firstOrFail();
            $project = Project::query()->whereKey($order->expense->project_id)->lockForUpdate()->firstOrFail();
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $expense = Expense::query()->whereKey($order->expense_id)->lockForUpdate()->firstOrFail();
            if ($order->isClosed()) {
                throw ValidationException::withMessages(['close' => "{$order->number} is closed already."]);
            }
            self::requireInvoiceable($order, 'close');
            if (! $order->receipts()->exists()) {
                throw ValidationException::withMessages(['close' => 'Nothing has arrived: cancel the order\'s spending instead.']);
            }
            if ($order->hasLegacyInvoice()) {
                throw ValidationException::withMessages(['close' => 'This order\'s invoice was recorded as a reference only: record the supplier\'s invoice with its amount first.']);
            }
            $invoiced = $order->invoicedMinor();
            if ($invoiced === 0) {
                throw ValidationException::withMessages(['close' => 'Record the supplier\'s invoice for what arrived first.']);
            }

            $from = $expense->amount_minor;
            $expense->forceFill(['amount_minor' => $invoiced])->save();
            $order->forceFill(['closed_at' => now(), 'closed_by' => $by->id, 'close_reason' => $reason])->save();
            Audit::record('purchase.order_closed', $order, [
                'number' => $order->number,
                'reason' => $reason,
                'ordered_minor' => $order->amount_minor,
                'commitment_from_minor' => $from,
                'commitment_to_minor' => $invoiced,
                'released_minor' => $from - $invoiced,
                'budget_line_key' => $expense->budget_line_key,
            ], actor: $by);
            Audit::record('expense.commitment_adjusted', $expense, ['from_minor' => $from, 'to_minor' => $invoiced, 'order' => $order->number, 'reason' => $reason], actor: $by);
            ActivityLog::record($project, $by, "Closed {$order->number}: commitment ".Money::format($from, $project->currency).' → '.Money::format($invoiced, $project->currency));
            app(FinanceRequests::class)->submitIfPayable($order->refresh(), $by);
        });
    }

    /**
     * Called by Spending::pay: an order is paid once what it bought has
     * arrived (or the order was closed with what did) and the supplier's
     * invoices come to its commitment.
     *
     * @throws ValidationException
     */
    public static function requirePayable(Expense $expense): void
    {
        $order = PurchaseOrder::query()->where('expense_id', $expense->id)->first();
        if ($order === null || $order->isPayable()) {
            return;
        }
        if (! $order->isComplete()) {
            throw ValidationException::withMessages(['expense' => "{$order->number}: not everything ordered has been received. If no more is coming, close the order with what arrived."]);
        }
        if ($order->invoice_reference === null) {
            throw ValidationException::withMessages(['expense' => "{$order->number}: record the supplier's invoice first."]);
        }
        $currency = Project::query()->findOrFail($expense->project_id)->currency;
        throw ValidationException::withMessages(['expense' => "{$order->number}: the invoices (".Money::format($order->invoicedMinor(), $currency).') do not come to the commitment ('.Money::format($expense->amount_minor, $currency).'). Record the rest, or close the order on what was invoiced.']);
    }

    /**
     * @throws ValidationException
     */
    private static function requireInvoiceable(PurchaseOrder $order, string $field): void
    {
        if ($order->expense->status !== ExpenseStatus::Approved) {
            throw ValidationException::withMessages([$field => 'Only an order approved and not yet paid takes this.']);
        }
        if (FinanceRequest::query()->where('expense_id', $order->expense_id)->exists()) {
            throw ValidationException::withMessages([$field => 'This order\'s spending is with Finance already: it pays what was sent.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private static function refuseDuplicateInvoice(string $supplierId, string $key): void
    {
        $taken = SupplierInvoice::query()->withoutGlobalScope(VisibleThroughProjectScope::class)->live()
            ->where('supplier_id', $supplierId)->where('number_key', $key)->exists();
        // Orders invoiced before invoices were kept carry the number on the order.
        $taken = $taken || PurchaseOrder::query()->withoutGlobalScope(VisibleThroughProjectScope::class)
            ->where('supplier_id', $supplierId)->whereNotNull('invoice_reference')
            ->whereDoesntHave('invoices')
            ->pluck('invoice_reference')
            ->contains(fn (string $reference) => SupplierInvoice::keyOf($reference) === $key);
        if ($taken) {
            throw ValidationException::withMessages(['number' => 'This supplier\'s invoice is recorded already, on this or another order.']);
        }
    }

    /**
     * The approved line a request is charged to, by key (ADR-0020). Without
     * a key, the one approved line of the category; several need a choice,
     * which $required insists on (submit) and a draft may leave for later.
     *
     * @throws ValidationException
     */
    private static function lineFor(Project $project, CostCategory $category, ?string $key, bool $required): ?string
    {
        $approved = Budgets::approved($project);
        if ($approved === null) {
            if ($required) {
                throw ValidationException::withMessages(['budget_line_id' => 'The project has no approved budget yet: requests go against an approved budget line.']);
            }

            return null;
        }
        if ($key !== null) {
            $line = Budgets::approvedLine($project, $key);
            if ($line === null) {
                throw ValidationException::withMessages(['budget_line_id' => 'That line is not in the project\'s approved budget.']);
            }
            if ($line->category !== $category) {
                throw ValidationException::withMessages(['budget_line_id' => "That line is for {$line->category->label()}, not {$category->label()}."]);
            }

            return $key;
        }

        $lines = $approved->lines()->where('category', $category)->get();
        if ($lines->count() === 1) {
            return $lines->first()->line_key;
        }
        if ($required) {
            throw ValidationException::withMessages(['budget_line_id' => $lines->isEmpty()
                ? "The approved budget has no {$category->label()} line."
                : "Choose which {$category->label()} line of the budget this is charged to."]);
        }

        return null;
    }

    /**
     * The budget facts an approval is given on, or the refusal.
     *
     * @return array{budget_line_key: string, line_left_minor: int, category_left_minor: int}
     *
     * @throws ValidationException
     */
    private static function requireBudget(Project $project, PurchaseRequest $request): array
    {
        $key = self::lineFor($project, $request->category, $request->budget_line_key, required: true);
        $lineLeft = (int) Budgets::lineAvailable($project, (string) $key);
        $categoryLeft = (int) Spending::categoryAvailable($project, $request->category);
        if ($request->estimate_minor > $lineLeft) {
            throw ValidationException::withMessages(['status' => 'Only '.Money::format(max(0, $lineLeft), $project->currency).' is left on the budget line, less than the estimate.']);
        }
        if ($request->estimate_minor > $categoryLeft) {
            throw ValidationException::withMessages(['status' => "{$request->category->label()}: only ".Money::format(max(0, $categoryLeft), $project->currency).' of the approved budget is left, less than the estimate.']);
        }
        if ($request->budget_line_key !== $key) {
            $request->forceFill(['budget_line_key' => $key]);
        }

        return ['budget_line_key' => (string) $key, 'line_left_minor' => $lineLeft, 'category_left_minor' => $categoryLeft];
    }

    public static function supplierNamed(string $name): Supplier
    {
        return Supplier::query()->firstOrCreate(['name' => trim($name)]);
    }

    private static function fundedBy(Project $project, string $grantId): bool
    {
        return GrantAllocation::query()->with('grant')->where('project_id', $project->id)->where('grant_id', $grantId)->get()
            ->contains(fn (GrantAllocation $allocation) => $allocation->grant->status === GrantStatus::Active);
    }

    /**
     * @throws ValidationException
     */
    private static function requireStatus(PurchaseRequest $request, PurchaseRequestStatus $status, string $message): void
    {
        if ($request->status !== $status) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    /**
     * @throws ValidationException
     */
    private static function requireBuying(PurchaseRequest $request): void
    {
        if (! $request->isBuying()) {
            throw ValidationException::withMessages(['status' => 'This request is not open for quotes.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private static function requireValid(PurchaseQuote $quote): void
    {
        if ($quote->valid_until !== null && $quote->valid_until->lt(today())) {
            throw ValidationException::withMessages(['quote' => 'That quote has expired.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private static function requireOpen(Project $project, string $field): void
    {
        if (! $project->isOpen()) {
            throw ValidationException::withMessages([$field => 'The project is closed.']);
        }
    }
}
