<?php

namespace App\Http\Controllers\Work;

use App\Actions\Budgets;
use App\Actions\Procurement;
use App\Actions\Spending;
use App\Enums\CostCategory;
use App\Enums\ExpenseStatus;
use App\Enums\PurchaseRequestStatus;
use App\FinanceIntegration\FinanceRequests;
use App\Http\Controllers\Controller;
use App\Models\BudgetLine;
use App\Models\ExpenseDocument;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\GrantAllocation;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProcurementController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($this->can($request, 'procurement.view') || $this->can($request, 'procurement.request'), 403);
        $everyone = $this->can($request, 'procurement.view');
        $status = PurchaseRequestStatus::tryFrom($request->string('status')->toString());
        $requests = PurchaseRequest::query()->with(['project:id,code,name,currency', 'requester:id,name'])
            ->when(! $everyone, fn ($query) => $query->where('requested_by', $this->member($request)->id))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest()->orderByDesc('number')->paginate(30)->withQueryString();

        return Inertia::render('portal/procurement/index', [
            'requests' => $requests->through(fn (PurchaseRequest $purchase) => [
                'id' => $purchase->id,
                'number' => $purchase->number,
                'title' => $purchase->title,
                'project' => "{$purchase->project->code} {$purchase->project->name}",
                'requester' => $purchase->requester->name,
                'status' => $purchase->status->value,
                'statusLabel' => $purchase->status->label(),
                'estimateMinor' => $purchase->estimate_minor,
                'currency' => $purchase->project->currency,
                'neededBy' => $purchase->needed_by?->toDateString(),
            ]),
            'statuses' => array_map(fn (PurchaseRequestStatus $case) => ['value' => $case->value, 'label' => $case->label()], PurchaseRequestStatus::cases()),
            'status' => $status?->value,
            'canRequest' => $this->can($request, 'procurement.request'),
        ]);
    }

    public function create(Request $request): Response
    {
        $projects = Project::query()->whereIn('status', Project::OPEN)->orderBy('code')->with('grantAllocations.grant:id,reference,title,status')->get();
        // What is left on each line only for those who may see budgets.
        $seesBudget = $this->can($request, 'projects.finance.view');

        return Inertia::render('portal/procurement/create', [
            'projects' => $projects->map(fn (Project $project) => [
                'value' => $project->id,
                'label' => "{$project->code} {$project->name}",
                'currency' => $project->currency,
                'grants' => $project->grantAllocations
                    ->filter(fn (GrantAllocation $allocation) => $allocation->grant->status->value === 'active')
                    ->map(fn (GrantAllocation $allocation) => ['value' => $allocation->grant_id, 'label' => "{$allocation->grant->reference} {$allocation->grant->title}"])
                    ->values(),
                'budgetLines' => $this->budgetLines($project, $seesBudget),
            ])->values(),
            'categories' => CostCategory::options(withLabour: false),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:200'],
            'justification' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::enum(CostCategory::class), Rule::notIn([CostCategory::Labour->value])],
            'needed_by' => ['nullable', 'date'],
            'grant_id' => ['nullable', 'uuid'],
            'budget_line_id' => ['nullable', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
            'lines.*.unit_price' => ['required', 'string'],
        ], ['category.not_in' => 'Labour comes from time logged.', 'lines.*.quantity.*' => 'A quantity above zero, with up to two decimals.']);

        $project = Project::query()->whereKey($validated['project_id'])->firstOrFail();
        $request->validate(['lines.*.unit_price' => [Money::rule($project->currency)]]);
        // The form names a line of the approved version; the request keeps its key (ADR-0020).
        $lineKey = null;
        if (($validated['budget_line_id'] ?? null) !== null) {
            $line = Budgets::approved($project)?->lines()->whereKey($validated['budget_line_id'])->first();
            if ($line === null) {
                throw ValidationException::withMessages(['budget_line_id' => 'That line is not in the project\'s approved budget.']);
            }
            $lineKey = $line->line_key;
        }

        $purchase = Procurement::request($project, [
            'title' => trim($validated['title']),
            'justification' => trim($validated['justification']),
            'category' => CostCategory::from($validated['category']),
            'needed_by' => $this->text($validated['needed_by'] ?? null),
            'grant_id' => $this->text($validated['grant_id'] ?? null),
            'budget_line_key' => $lineKey,
        ], array_values(array_map(fn (array $line) => [
            'description' => trim($line['description']),
            'quantity' => $line['quantity'],
            'unit' => $this->text($line['unit'] ?? null),
            'unit_price_minor' => Money::toMinor($line['unit_price'], $project->currency),
        ], $validated['lines'])), $this->member($request));

        return to_route('procurement.show', ['purchaseRequest' => $purchase->id])->with('success', 'Saved as a draft: submit it for approval when ready.');
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest): Response
    {
        $me = $this->member($request);
        abort_unless($this->can($request, 'procurement.view') || ($purchaseRequest->requested_by === $me->id && $this->can($request, 'procurement.request')), 403);
        $purchase = $purchaseRequest->load(['project:id,code,name,currency', 'grant:id,reference,title', 'requester:id,name', 'decider:id,name']);
        $manage = $this->can($request, 'procurement.manage');
        $buying = $purchase->isBuying();
        $orders = $purchase->orders()->with(['expense', 'supplier:id,name'])->get();
        $live = $orders->first(fn (PurchaseOrder $order) => $order->isLive());
        $received = $live?->receivedByLine() ?? [];
        $rejected = [];
        foreach ($live?->receipts()->with('lines')->get() ?? [] as $receipt) {
            foreach ($receipt->lines as $line) {
                $rejected[$line->purchase_request_line_id] = Quantity::add($rejected[$line->purchase_request_line_id] ?? '0', (string) $line->rejected_quantity);
            }
        }

        return Inertia::render('portal/procurement/show', [
            'request' => [
                'id' => $purchase->id,
                'number' => $purchase->number,
                'title' => $purchase->title,
                'justification' => $purchase->justification,
                'category' => $purchase->category->label(),
                'project' => "{$purchase->project->code} {$purchase->project->name}",
                'grant' => $purchase->grant === null ? null : "{$purchase->grant->reference} {$purchase->grant->title}",
                'currency' => $purchase->project->currency,
                'status' => $purchase->status->value,
                'statusLabel' => $purchase->status->label(),
                'estimateMinor' => $purchase->estimate_minor,
                'neededBy' => $purchase->needed_by?->toDateString(),
                'requester' => $purchase->requester->name,
                'createdOn' => $purchase->created_at->toDateString(),
                'decider' => $purchase->decider?->name,
                'decidedAt' => $purchase->decided_at?->toIso8601String(),
                'decisionNote' => $purchase->decision_note,
                'selectionReason' => $purchase->selection_reason,
            ],
            'lines' => $purchase->lines()->get()->map(fn (PurchaseRequestLine $line) => [
                'id' => $line->id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
                'unitPriceMinor' => $line->unit_price_minor,
                'amountMinor' => $line->amount_minor,
                'received' => $received[$line->id] ?? '0.00',
                'rejected' => $rejected[$line->id] ?? '0.00',
                'left' => Quantity::subtract((string) $line->quantity, $received[$line->id] ?? '0'),
            ])->values(),
            'quotes' => $purchase->quotes()->with('supplier:id,name')->get()->map(fn (PurchaseQuote $quote) => [
                'id' => $quote->id,
                'supplier' => $quote->supplier->name,
                'amountMinor' => $quote->amount_minor,
                'reference' => $quote->reference,
                'validUntil' => $quote->valid_until?->toDateString(),
                'expired' => $quote->valid_until !== null && $quote->valid_until->lt(today()),
                'document' => $quote->original_name,
                'selected' => $purchase->selected_quote_id === $quote->id,
            ])->values(),
            // The line it is charged to (ADR-0020); what is left only for those who may see budgets.
            'budgetLine' => $this->lineOf($purchase),
            'budgetLines' => $purchase->status === PurchaseRequestStatus::Draft
                ? array_values(array_filter($this->budgetLines($purchase->project, $this->can($request, 'projects.finance.view')), fn (array $line) => $line['category'] === $purchase->category->value))
                : [],
            'budget' => $this->can($request, 'projects.finance.view') && in_array($purchase->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Submitted, PurchaseRequestStatus::Approved], true)
                ? [
                    'leftMinor' => Spending::categoryAvailable($purchase->project, $purchase->category),
                    'lineLeftMinor' => $purchase->budget_line_key === null ? null : Budgets::lineAvailable($purchase->project, $purchase->budget_line_key),
                ]
                : null,
            'minQuotes' => Procurement::MIN_QUOTES,
            'orders' => $orders->map(function (PurchaseOrder $order) use ($manage) {
                $financeRequest = FinanceRequests::current($order->expense);
                $open = $order->expense->status === ExpenseStatus::Approved && $financeRequest === null;

                return [
                    'id' => $order->id,
                    'number' => $order->number,
                    'supplier' => $order->supplier->name,
                    'amountMinor' => $order->amount_minor,
                    // The commitment: the order's spending, lower than ordered once closed short.
                    'commitmentMinor' => $order->expense->amount_minor,
                    'invoicedMinor' => $order->invoicedMinor(),
                    'terms' => $order->terms,
                    'spending' => $order->expense->status->value,
                    'spendingLabel' => $order->expense->status->label(),
                    // Where Finance pays: its own status, as Finance reported it.
                    'finance' => $financeRequest === null ? null : ['status' => $financeRequest->status->value, 'label' => $financeRequest->status->label()],
                    'live' => $order->isLive(),
                    'invoiceReference' => $order->invoice_reference,
                    'invoiceOn' => $order->invoice_on?->toDateString(),
                    'legacyInvoice' => $order->hasLegacyInvoice(),
                    'fullyReceived' => $order->isFullyReceived(),
                    'payable' => $order->isLive() && $order->isPayable(),
                    'closed' => $order->closed_at === null ? null : [
                        'on' => $order->closed_at->toDateString(),
                        'reason' => $order->close_reason,
                    ],
                    'invoices' => $order->invoices()->with('recorder:id,name')->get()->map(fn (SupplierInvoice $invoice) => [
                        'id' => $invoice->id,
                        'number' => $invoice->number,
                        'amountMinor' => $invoice->amount_minor,
                        'currency' => $invoice->currency,
                        'on' => $invoice->invoice_on->toDateString(),
                        'dueOn' => $invoice->due_on?->toDateString(),
                        'document' => $invoice->original_name,
                        'by' => $invoice->recorder->name,
                        'voided' => $invoice->isLive() ? null : ['reason' => $invoice->void_reason, 'on' => $invoice->voided_at?->toDateString()],
                    ])->values(),
                    'receipts' => $order->receipts()->with(['receiver:id,name', 'lines'])->get()->map(fn (GoodsReceipt $receipt) => [
                        'id' => $receipt->id,
                        'on' => $receipt->received_on->toDateString(),
                        'by' => $receipt->receiver->name,
                        'note' => $receipt->note,
                        'lines' => $receipt->lines->count(),
                        'rejected' => $receipt->lines->contains(fn (GoodsReceiptLine $line) => Quantity::compare((string) $line->rejected_quantity, '0') > 0),
                        'evidence' => $receipt->original_name,
                    ])->values(),
                    'can' => [
                        'invoice' => $manage && $open && ! $order->isClosed(),
                        'close' => $manage && $open && ! $order->isClosed() && ! $order->isPayable(),
                    ],
                ];
            })->values(),
            'suppliers' => $manage && $buying ? Supplier::query()->orderBy('name')->get(['id', 'name'])->map(fn (Supplier $supplier) => ['value' => $supplier->id, 'label' => $supplier->name])->values() : [],
            'can' => [
                'submit' => $purchase->status === PurchaseRequestStatus::Draft && ($purchase->requested_by === $me->id || $manage),
                'decide' => $purchase->status === PurchaseRequestStatus::Submitted && $this->can($request, 'procurement.approve') && $purchase->requested_by !== $me->id,
                'cancel' => in_array($purchase->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Submitted, PurchaseRequestStatus::Approved], true) && ($purchase->requested_by === $me->id || $manage),
                'buy' => $manage && $buying,
                'order' => $manage && $buying && $purchase->selected_quote_id !== null,
                'receive' => $manage && $live !== null && $live->expense->status->value === 'approved' && ! $live->isClosed(),
            ],
            'today' => today()->toDateString(),
        ]);
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->requireOwnOrManage($request, $purchaseRequest);
        $validated = $request->validate(['budget_line_id' => ['nullable', 'uuid']]);
        $lineKey = null;
        if (($validated['budget_line_id'] ?? null) !== null) {
            $line = Budgets::approved($purchaseRequest->project)?->lines()->whereKey($validated['budget_line_id'])->first();
            if ($line === null) {
                throw ValidationException::withMessages(['budget_line_id' => 'That line is not in the project\x27s approved budget.']);
            }
            $lineKey = $line->line_key;
        }
        Procurement::submit($purchaseRequest, $this->member($request), $lineKey);

        return back()->with('success', 'Sent for approval.');
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        Procurement::approve($purchaseRequest, $this->text($validated['note'] ?? null), $this->member($request));

        return back()->with('success', 'Approved: quotes can be taken now.');
    }

    public function reject(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        Procurement::reject($purchaseRequest, trim($validated['note']), $this->member($request));

        return back()->with('success', 'Rejected.');
    }

    public function cancel(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->requireOwnOrManage($request, $purchaseRequest);
        Procurement::cancel($purchaseRequest, $this->member($request));

        return back()->with('success', 'Cancelled.');
    }

    public function addQuote(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $currency = $purchaseRequest->project->currency;
        $validated = $request->validate([
            'supplier_id' => ['nullable', 'uuid', 'required_without:supplier_name'],
            'supplier_name' => ['nullable', 'string', 'max:255', 'required_without:supplier_id'],
            'amount' => ['required', 'string', Money::rule($currency)],
            'reference' => ['nullable', 'string', 'max:100'],
            'valid_until' => ['nullable', 'date'],
            'document' => ['nullable', ...ExpenseDocument::RULES],
        ]);

        $name = $this->text($validated['supplier_name'] ?? null);
        $supplier = $name !== null ? Procurement::supplierNamed($name) : Supplier::query()->whereKey($validated['supplier_id'])->firstOrFail();

        Procurement::addQuote($purchaseRequest, [
            'supplier_id' => $supplier->id,
            'amount_minor' => Money::toMinor($validated['amount'], $currency),
            'reference' => $this->text($validated['reference'] ?? null),
            'valid_until' => $this->text($validated['valid_until'] ?? null),
        ], $request->file('document'), $this->member($request));

        return back()->with('success', 'Quote added.');
    }

    public function removeQuote(Request $request, PurchaseRequest $purchaseRequest, PurchaseQuote $quote): RedirectResponse
    {
        Procurement::removeQuote($purchaseRequest, $quote, $this->member($request));

        return back()->with('success', 'Quote removed.');
    }

    public function quoteDocument(PurchaseRequest $purchaseRequest, PurchaseQuote $quote): StreamedResponse
    {
        abort_if($quote->path === null, 404);

        return Storage::disk(Procurement::DISK)->download($quote->path, $quote->original_name);
    }

    public function choose(Request $request, PurchaseRequest $purchaseRequest, PurchaseQuote $quote): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        Procurement::choose($purchaseRequest, $quote, $validated['reason'] ?? null, $this->member($request));

        return back()->with('success', 'Quote chosen.');
    }

    public function raiseOrder(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $validated = $request->validate(['terms' => ['nullable', 'string', 'max:5000']]);
        $order = Procurement::raiseOrder($purchaseRequest, $this->text($validated['terms'] ?? null), $this->member($request));

        return back()->with('success', "{$order->number} raised: it now waits for finance to approve the spending.");
    }

    public function receive(Request $request, PurchaseRequest $purchaseRequest, PurchaseOrder $order): RedirectResponse
    {
        $validated = $request->validate([
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
            'quantities' => ['required', 'array'],
            'quantities.*' => ['nullable', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'rejected' => ['nullable', 'array'],
            'rejected.*' => ['nullable', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'evidence' => ['nullable', ...ExpenseDocument::RULES],
        ], ['quantities.*.regex' => 'Quantities with up to two decimals.', 'rejected.*.regex' => 'Quantities with up to two decimals.']);
        $zeroed = fn (array $quantities) => array_map(fn (?string $quantity) => $quantity === null || $quantity === '' ? '0' : $quantity, $quantities);

        Procurement::receive(
            $order,
            $zeroed($validated['quantities']),
            CarbonImmutable::parse($validated['received_on']),
            $this->text($validated['note'] ?? null),
            $this->member($request),
            $zeroed($validated['rejected'] ?? []),
            $request->file('evidence'),
        );

        return back()->with('success', 'Goods received.');
    }

    /**
     * A supplier invoice (ADR-0020). The older form sent only a reference
     * and a date: that still works, as an invoice for what is left of the
     * commitment, in the project's currency.
     */
    public function recordInvoice(Request $request, PurchaseRequest $purchaseRequest, PurchaseOrder $order): RedirectResponse
    {
        $currency = $purchaseRequest->project->currency;
        $validated = $request->validate([
            'number' => ['nullable', 'string', 'max:100', 'required_without:invoice_reference'],
            'invoice_reference' => ['nullable', 'string', 'max:100'],
            'amount' => ['nullable', 'string', Money::rule($currency)],
            'currency' => ['nullable', 'string', 'size:3'],
            'invoice_on' => ['required', 'date', 'before_or_equal:today'],
            'due_on' => ['nullable', 'date', 'after_or_equal:invoice_on'],
            'document' => ['nullable', ...ExpenseDocument::RULES],
        ]);

        $left = $order->expense->amount_minor - $order->invoicedMinor();
        Procurement::recordInvoice($order, [
            'number' => (string) ($this->text($validated['number'] ?? null) ?? trim((string) $validated['invoice_reference'])),
            'amount_minor' => isset($validated['amount']) && $validated['amount'] !== '' ? Money::toMinor($validated['amount'], $currency) : $left,
            'currency' => strtoupper((string) ($validated['currency'] ?? $currency)),
            'invoice_on' => CarbonImmutable::parse($validated['invoice_on']),
            'due_on' => isset($validated['due_on']) ? CarbonImmutable::parse($validated['due_on']) : null,
        ], $request->file('document'), $this->member($request));

        return back()->with('success', 'Invoice recorded.');
    }

    public function voidInvoice(Request $request, PurchaseRequest $purchaseRequest, PurchaseOrder $order, SupplierInvoice $invoice): RedirectResponse
    {
        abort_unless($invoice->purchase_order_id === $order->id, 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Procurement::voidInvoice($invoice, trim($validated['reason']), $this->member($request));

        return back()->with('success', 'Invoice voided. It stays on record.');
    }

    public function closeOrder(Request $request, PurchaseRequest $purchaseRequest, PurchaseOrder $order): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Procurement::closeOrder($order, trim($validated['reason']), $this->member($request));

        return back()->with('success', "{$order->number} closed: what was not invoiced went back to the budget.");
    }

    public function invoiceDocument(PurchaseRequest $purchaseRequest, PurchaseOrder $order, SupplierInvoice $invoice): StreamedResponse
    {
        abort_if($invoice->purchase_order_id !== $order->id || $invoice->path === null, 404);

        return Storage::disk(Procurement::DISK)->download($invoice->path, $invoice->original_name);
    }

    public function receiptEvidence(PurchaseRequest $purchaseRequest, PurchaseOrder $order, GoodsReceipt $receipt): StreamedResponse
    {
        abort_if($receipt->purchase_order_id !== $order->id || $receipt->path === null, 404);

        return Storage::disk(Procurement::DISK)->download($receipt->path, $receipt->original_name);
    }

    /**
     * The approved budget's lines a request may be charged to (ADR-0020),
     * with what is left on each for those who may see budgets.
     *
     * @return list<array{value: string, label: string, category: string, leftMinor: int|null}>
     */
    private function budgetLines(Project $project, bool $seesBudget): array
    {
        $approved = Budgets::approved($project);
        if ($approved === null) {
            return [];
        }
        $used = $seesBudget ? Budgets::usedByLine($project) : [];

        return array_values($approved->lines()->orderBy('category')->orderBy('created_at')->get()->map(fn (BudgetLine $line) => [
            'value' => $line->id,
            'label' => $line->category->label().($line->description === null ? '' : " — {$line->description}"),
            'category' => $line->category->value,
            'leftMinor' => $seesBudget ? $line->amount_minor - ($used[$line->line_key] ?? 0) : null,
        ])->all());
    }

    /**
     * @return array{label: string, approved: bool}|null
     */
    private function lineOf(PurchaseRequest $purchase): ?array
    {
        if ($purchase->budget_line_key === null) {
            return null;
        }
        $approved = Budgets::approvedLine($purchase->project, $purchase->budget_line_key);
        $line = $approved ?? BudgetLine::query()->where('project_id', $purchase->project_id)->where('line_key', $purchase->budget_line_key)->latest()->first();

        return $line === null ? null : [
            'label' => $line->category->label().($line->description === null ? '' : " — {$line->description}"),
            'approved' => $approved !== null,
        ];
    }

    private function requireOwnOrManage(Request $request, PurchaseRequest $purchase): void
    {
        abort_unless($purchase->requested_by === $this->member($request)->id || $this->can($request, 'procurement.manage'), 403);
    }
}
