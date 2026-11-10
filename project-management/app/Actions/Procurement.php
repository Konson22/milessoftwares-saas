<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Enums\GrantStatus;
use App\Enums\PurchaseRequestStatus;
use App\Models\Expense;
use App\Models\GrantAllocation;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\ActivityLog;
use App\Support\DocumentNumbers;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
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
 * only commitment: finance approves it against the budget and the grant with
 * the usual bands, and pays it the usual way. Nothing here touches a budget.
 * Spending::pay refuses an order's expense until requirePayable says yes.
 * Every change is audited.
 */
final class Procurement
{
    public const DISK = 'local';

    /** Fewer quotes than this needs a written reason. */
    public const MIN_QUOTES = 3;

    /**
     * @param  array{title: string, justification: string, category: CostCategory, needed_by: ?string, grant_id: ?string}  $details
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
    public static function submit(PurchaseRequest $request, User $by): void
    {
        self::requireStatus($request, PurchaseRequestStatus::Draft, 'Only a draft is submitted.');
        self::requireOpen($request->project, 'status');

        $request->forceFill(['status' => PurchaseRequestStatus::Submitted, 'submitted_at' => now()])->save();
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
     * Whoever asked doesn't get to say yes.
     *
     * @throws ValidationException
     */
    private static function decide(PurchaseRequest $request, PurchaseRequestStatus $to, ?string $note, User $by): void
    {
        self::requireStatus($request, PurchaseRequestStatus::Submitted, 'Only a submitted request is decided.');
        if ($request->requested_by === $by->id) {
            throw ValidationException::withMessages(['status' => 'Whoever asked for this cannot also decide it.']);
        }

        $request->forceFill(['status' => $to, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note])->save();
        Audit::record('purchase.'.$to->value, $request, ['number' => $request->number, 'note' => $note], actor: $by);
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
     * than was ordered is not.
     *
     * @param  array<string, string>  $quantities  by request line id
     *
     * @throws ValidationException
     */
    public static function receive(PurchaseOrder $order, array $quantities, CarbonImmutable $on, ?string $note, User $by): void
    {
        DB::transaction(function () use ($order, $quantities, $on, $note, $by) {
            $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->expense->status !== ExpenseStatus::Approved) {
                throw ValidationException::withMessages(['receipt' => 'Finance must approve the order before goods are received against it.']);
            }

            $lines = PurchaseRequestLine::query()->where('purchase_request_id', $order->purchase_request_id)->get()->keyBy('id');
            $received = $order->receivedByLine();
            $wanted = [];
            foreach ($quantities as $lineId => $quantity) {
                if (Quantity::compare($quantity, '0') === 0) {
                    continue;
                }
                $line = $lines->get($lineId);
                if ($line === null) {
                    throw ValidationException::withMessages(['receipt' => 'That line is not on this order.']);
                }
                $left = Quantity::subtract((string) $line->quantity, $received[$lineId] ?? '0');
                if (Quantity::compare($quantity, $left) > 0) {
                    throw ValidationException::withMessages(['receipt' => "{$line->description}: only {$left} left to receive."]);
                }
                $wanted[$lineId] = $quantity;
            }
            if ($wanted === []) {
                throw ValidationException::withMessages(['receipt' => 'Enter what arrived.']);
            }

            $receipt = $order->receipts()->create(['received_on' => $on, 'note' => $note, 'received_by' => $by->id]);
            foreach ($wanted as $lineId => $quantity) {
                $receipt->lines()->create(['purchase_request_line_id' => $lineId, 'quantity' => $quantity]);
            }
            Audit::record('purchase.goods_received', $order, ['number' => $order->number, 'lines' => $wanted, 'on' => $on->toDateString()], actor: $by);
        });
    }

    /**
     * @throws ValidationException
     */
    public static function recordInvoice(PurchaseOrder $order, string $reference, CarbonImmutable $on, User $by): void
    {
        if ($order->expense->status !== ExpenseStatus::Approved) {
            throw ValidationException::withMessages(['invoice' => 'Only an order approved and not yet paid takes an invoice.']);
        }

        $order->forceFill(['invoice_reference' => $reference, 'invoice_on' => $on])->save();
        Audit::record('purchase.invoice_recorded', $order, ['number' => $order->number, 'reference' => $reference], actor: $by);
    }

    /**
     * Called by Spending::pay: an order is paid once what it bought has arrived
     * and the supplier has billed for it.
     *
     * @throws ValidationException
     */
    public static function requirePayable(Expense $expense): void
    {
        $order = PurchaseOrder::query()->where('expense_id', $expense->id)->first();
        if ($order === null) {
            return;
        }
        if (! $order->isFullyReceived()) {
            throw ValidationException::withMessages(['expense' => "{$order->number}: not everything ordered has been received."]);
        }
        if ($order->invoice_reference === null) {
            throw ValidationException::withMessages(['expense' => "{$order->number}: record the supplier's invoice first."]);
        }
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
