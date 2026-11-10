<?php

namespace App\Http\Controllers\Work;

use App\Actions\Procurement;
use App\Actions\Spending;
use App\Enums\CostCategory;
use App\Enums\PurchaseRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\ExpenseDocument;
use App\Models\GoodsReceipt;
use App\Models\GrantAllocation;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseQuote;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\Supplier;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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

    public function create(): Response
    {
        $projects = Project::query()->whereIn('status', Project::OPEN)->orderBy('code')->with('grantAllocations.grant:id,reference,title,status')->get();

        return Inertia::render('portal/procurement/create', [
            'projects' => $projects->map(fn (Project $project) => [
                'value' => $project->id,
                'label' => "{$project->code} {$project->name}",
                'currency' => $project->currency,
                'grants' => $project->grantAllocations
                    ->filter(fn (GrantAllocation $allocation) => $allocation->grant->status->value === 'active')
                    ->map(fn (GrantAllocation $allocation) => ['value' => $allocation->grant_id, 'label' => "{$allocation->grant->reference} {$allocation->grant->title}"])
                    ->values(),
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
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
            'lines.*.unit_price' => ['required', 'string'],
        ], ['category.not_in' => 'Labour comes from time logged.', 'lines.*.quantity.*' => 'A quantity above zero, with up to two decimals.']);

        $project = Project::query()->whereKey($validated['project_id'])->firstOrFail();
        $request->validate(['lines.*.unit_price' => [Money::rule($project->currency)]]);

        $purchase = Procurement::request($project, [
            'title' => trim($validated['title']),
            'justification' => trim($validated['justification']),
            'category' => CostCategory::from($validated['category']),
            'needed_by' => $this->text($validated['needed_by'] ?? null),
            'grant_id' => $this->text($validated['grant_id'] ?? null),
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
            // A warning only, for those who may see budgets: finance approval is the check.
            'budget' => $this->can($request, 'projects.finance.view') && in_array($purchase->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Submitted, PurchaseRequestStatus::Approved], true)
                ? ['leftMinor' => Spending::categoryAvailable($purchase->project, $purchase->category)]
                : null,
            'minQuotes' => Procurement::MIN_QUOTES,
            'orders' => $orders->map(fn (PurchaseOrder $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'supplier' => $order->supplier->name,
                'amountMinor' => $order->amount_minor,
                'terms' => $order->terms,
                'spending' => $order->expense->status->value,
                'spendingLabel' => $order->expense->status->label(),
                'live' => $order->isLive(),
                'invoiceReference' => $order->invoice_reference,
                'invoiceOn' => $order->invoice_on?->toDateString(),
                'fullyReceived' => $order->isFullyReceived(),
                'receipts' => $order->receipts()->with(['receiver:id,name', 'lines'])->get()->map(fn (GoodsReceipt $receipt) => [
                    'id' => $receipt->id,
                    'on' => $receipt->received_on->toDateString(),
                    'by' => $receipt->receiver->name,
                    'note' => $receipt->note,
                    'lines' => $receipt->lines->count(),
                ])->values(),
            ])->values(),
            'suppliers' => $manage && $buying ? Supplier::query()->orderBy('name')->get(['id', 'name'])->map(fn (Supplier $supplier) => ['value' => $supplier->id, 'label' => $supplier->name])->values() : [],
            'can' => [
                'submit' => $purchase->status === PurchaseRequestStatus::Draft && ($purchase->requested_by === $me->id || $manage),
                'decide' => $purchase->status === PurchaseRequestStatus::Submitted && $this->can($request, 'procurement.approve') && $purchase->requested_by !== $me->id,
                'cancel' => in_array($purchase->status, [PurchaseRequestStatus::Draft, PurchaseRequestStatus::Submitted, PurchaseRequestStatus::Approved], true) && ($purchase->requested_by === $me->id || $manage),
                'buy' => $manage && $buying,
                'order' => $manage && $buying && $purchase->selected_quote_id !== null,
                'receive' => $manage && $live !== null && $live->expense->status->value === 'approved',
            ],
            'today' => today()->toDateString(),
        ]);
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->requireOwnOrManage($request, $purchaseRequest);
        Procurement::submit($purchaseRequest, $this->member($request));

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
        ], ['quantities.*.regex' => 'Quantities with up to two decimals.']);

        Procurement::receive(
            $order,
            array_map(fn (?string $quantity) => $quantity === null || $quantity === '' ? '0' : $quantity, $validated['quantities']),
            CarbonImmutable::parse($validated['received_on']),
            $this->text($validated['note'] ?? null),
            $this->member($request),
        );

        return back()->with('success', 'Goods received.');
    }

    public function recordInvoice(Request $request, PurchaseRequest $purchaseRequest, PurchaseOrder $order): RedirectResponse
    {
        $validated = $request->validate([
            'invoice_reference' => ['required', 'string', 'max:100'],
            'invoice_on' => ['required', 'date', 'before_or_equal:today'],
        ]);

        Procurement::recordInvoice($order, trim($validated['invoice_reference']), CarbonImmutable::parse($validated['invoice_on']), $this->member($request));

        return back()->with('success', 'Invoice recorded.');
    }

    private function requireOwnOrManage(Request $request, PurchaseRequest $purchase): void
    {
        abort_unless($purchase->requested_by === $this->member($request)->id || $this->can($request, 'procurement.manage'), 403);
    }
}
