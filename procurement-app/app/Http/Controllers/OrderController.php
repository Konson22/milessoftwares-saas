<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Procurement\Access;
use App\Procurement\Invoices;
use App\Procurement\Models\Award;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\PurchaseOrderLine;
use App\Procurement\Models\PurchaseOrderRevision;
use App\Procurement\Models\Receipt;
use App\Procurement\Models\ReceiptLine;
use App\Procurement\Models\SupplierInvoice;
use App\Procurement\Money;
use App\Procurement\Orders;
use App\Procurement\Receiving;
use App\Procurement\Sourcing;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchase orders with their receipts and supplier invoices.
 */
class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        return Inertia::render('orders/index', [
            'status' => $status,
            'orders' => PurchaseOrder::query()->with('supplier:id,name')
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->latest()->limit(200)->get()
                ->map(fn (PurchaseOrder $o) => self::row($o)),
            'statuses' => PurchaseOrder::STATUSES,
        ]);
    }

    /**
     * Orders waiting for goods or services, and the latest receipts.
     */
    public function receiving(): Response
    {
        return Inertia::render('receiving/index', [
            'orders' => PurchaseOrder::query()->with('supplier:id,name')->whereIn('status', PurchaseOrder::RECEIVABLE)
                ->orderByRaw('delivery_date is null')->orderBy('delivery_date')->get()
                ->map(fn (PurchaseOrder $o) => self::row($o)),
            'receipts' => Receipt::query()->with('order:id,number,supplier_id', 'order.supplier:id,name')->latest()->limit(50)->get()
                ->map(fn (Receipt $r) => [
                    'id' => $r->id, 'number' => $r->number, 'orderId' => $r->purchase_order_id, 'order' => $r->order->number,
                    'supplier' => $r->order->supplier->name, 'receivedOn' => self::date($r->getAttribute('received_on')),
                    'inspection' => $r->inspection, 'by' => $r->received_by_name,
                ]),
            'canReceive' => Gate::allows('procurement.receive'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'requisition_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'uuid'],
            'currency' => ['nullable', 'string', 'size:3'],
            ...$this->lineRules(),
        ]);
        $requisition = Access::requisition($data['requisition_id'])->load('lines');
        if (! isset($data['lines'])) {
            // Start from the requisition's lines at its estimated prices; the draft is then edited.
            $data['lines'] = $requisition->lines->map(fn ($l) => [
                'kind' => $l->kind, 'description' => $l->description, 'quantity' => $l->quantity, 'unit' => $l->unit,
                'unit_price' => Money::major($l->unit_price_minor, $requisition->currency), 'requisition_line_id' => $l->id,
            ])->all();
            $data['currency'] = $requisition->currency;
        }
        $order = Orders::direct($requisition, $data, $this->user());

        return to_route('orders.show', $order)->with('success', 'Direct purchase order drafted. Check the supplier\'s prices before submitting it.');
    }

    public function fromAward(Request $request, Award $award): RedirectResponse
    {
        $data = $request->validate([
            'delivery_date' => ['nullable', 'date'],
            'delivery_address' => ['nullable', 'string', 'max:2000'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
        ]);
        $order = Orders::fromAward($award, $data, $this->user());

        return to_route('orders.show', $order)->with('success', 'Purchase order drafted from the award.');
    }

    public function show(PurchaseOrder $order): Response
    {
        $order->load(['supplier', 'lines', 'requisition:id,number,title', 'sourcingEvent:id,number', 'preparer:id,name', 'receipts.lines', 'invoices', 'revisions']);
        $accepted = Orders::accepted($order);
        $settings = Workspace::settings();
        $currency = $order->currency;

        return Inertia::render('orders/show', [
            'order' => [
                ...self::row($order),
                'methodLabel' => Sourcing::LABELS[$order->method] ?? $order->method,
                'methodReason' => $order->getAttribute('method_reason'),
                'statusReason' => $order->getAttribute('status_reason'),
                'revision' => $order->revision,
                'department' => $order->getAttribute('department_name'),
                'rate' => $order->rate_minor === null ? null : Money::major($order->rate_minor, Money::base()),
                'subtotal' => self::major($order->subtotal_minor, $currency),
                'discount' => self::major($order->discount_minor, $currency),
                'tax' => self::major($order->tax_minor, $currency),
                'delivery' => self::major($order->delivery_minor, $currency),
                'deliveryAddress' => $order->getAttribute('delivery_address'),
                'paymentTerms' => $order->getAttribute('payment_terms'),
                'notes' => $order->getAttribute('notes'),
                'budgetReference' => $order->getAttribute('budget_reference'),
                'projectReference' => $order->getAttribute('project_reference'),
                'fundingReference' => $order->getAttribute('funding_reference'),
                'preparedBy' => $order->preparer->name,
                'awardBased' => $order->award_id !== null,
                'requisition' => $order->requisition === null ? null : ['id' => $order->requisition->id, 'number' => $order->requisition->number, 'title' => $order->requisition->title],
                'sourcing' => $order->sourcingEvent === null ? null : ['id' => $order->sourcingEvent->id, 'number' => $order->sourcingEvent->number],
                'acceptedValue' => self::major(Orders::acceptedValue($order), $currency),
                'invoiced' => self::major((int) $order->invoices->where('status', 'recorded')->sum('amount_minor'), $currency),
                'lines' => $order->lines->map(fn (PurchaseOrderLine $l) => [
                    'id' => $l->id, 'kind' => $l->kind, 'description' => $l->description, 'quantity' => $l->quantity, 'unit' => $l->unit,
                    'unitPrice' => self::major($l->unit_price_minor, $currency), 'amount' => self::major($l->amount_minor, $currency),
                    'accepted' => $accepted[$l->id] ?? '0.000',
                    'remaining' => bcsub(Money::quantity($l->quantity), $accepted[$l->id] ?? '0', 3),
                    'requisitionLineId' => $l->requisition_line_id,
                ]),
                'receipts' => $order->receipts->map(fn (Receipt $r) => [
                    'id' => $r->id, 'number' => $r->number, 'receivedOn' => self::date($r->getAttribute('received_on')),
                    'deliveryNote' => $r->getAttribute('delivery_note'), 'inspection' => $r->inspection, 'notes' => $r->getAttribute('notes'), 'by' => $r->received_by_name,
                    'lines' => $r->lines->map(fn (ReceiptLine $l) => [
                        'lineId' => $l->purchase_order_line_id, 'received' => $l->received_quantity, 'accepted' => $l->accepted_quantity,
                        'rejected' => $l->rejected_quantity, 'reason' => $l->rejection_reason,
                    ]),
                ]),
                'invoices' => $order->invoices->map(fn (SupplierInvoice $i) => [
                    'id' => $i->id, 'number' => $i->number, 'invoiceDate' => self::date($i->getAttribute('invoice_date')),
                    'dueDate' => self::date($i->getAttribute('due_date')), 'amount' => self::major($i->amount_minor, $i->currency),
                    'matchStatus' => $i->match_status, 'status' => $i->status, 'voidReason' => $i->getAttribute('void_reason'),
                    'paymentStatus' => $i->payment_status, 'paidOn' => self::date($i->getAttribute('paid_on')),
                    'paymentReference' => $i->getAttribute('payment_reference'), 'financeStatus' => $i->finance_status,
                ]),
                'revisions' => $order->revisions->map(fn (PurchaseOrderRevision $r) => [
                    'revision' => $r->revision, 'reason' => $r->reason, 'total' => self::major((int) ($r->snapshot['total_minor'] ?? 0), $currency), 'date' => self::date($r->created_at),
                ]),
            ],
            'baseCurrency' => Money::base(),
            'financeConnected' => $settings->finance_bills_enabled,
            'approval' => $this->approval('order', $order),
            'documents' => $this->documents('order', $order),
            'can' => [
                'manage' => Gate::allows('procurement.orders.manage'),
                'receive' => Gate::allows('procurement.receive') && in_array($order->status, PurchaseOrder::RECEIVABLE, true),
                'invoice' => Gate::allows('procurement.invoices.manage'),
            ],
        ]);
    }

    public function update(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'delivery_date' => ['nullable', 'date'],
            'delivery_address' => ['nullable', 'string', 'max:2000'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            ...$this->lineRules(),
        ]);
        Orders::update($order, $data, $this->user());

        return back()->with('success', 'Order saved.');
    }

    public function submit(PurchaseOrder $order): RedirectResponse
    {
        Orders::submit($order, $this->user());

        return back()->with('success', 'Order submitted.');
    }

    public function decide(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject', 'return'])], 'note' => ['nullable', 'string', 'max:2000']]);
        Orders::decide($order, $data['decision'], $data['note'] ?? null, $this->user());

        return back()->with('success', 'Decision recorded.');
    }

    public function issue(PurchaseOrder $order): RedirectResponse
    {
        Orders::issue($order, $this->user());

        return back()->with('success', 'Order issued to the supplier.');
    }

    public function amend(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Orders::amend($order, $data['reason'], $this->user());

        return back()->with('success', 'Order back to draft as a new revision.');
    }

    public function cancel(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Orders::cancel($order, $data['reason'], $this->user());

        return back()->with('success', 'Order cancelled.');
    }

    public function close(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        Orders::close($order, $data['reason'] ?? null, $this->user());

        return back()->with('success', 'Order closed.');
    }

    public function receive(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'delivery_note' => ['nullable', 'string', 'max:100'],
            'inspection' => ['required', Rule::in(['passed', 'partial', 'failed'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'uuid'],
            'lines.*.accepted' => ['nullable', 'numeric', 'min:0'],
            'lines.*.rejected' => ['nullable', 'numeric', 'min:0'],
            'lines.*.rejection_reason' => ['nullable', 'string', 'max:255'],
        ]);
        Receiving::receive($order, $data, $this->user());

        return back()->with('success', 'Receipt recorded.');
    }

    public function storeInvoice(Request $request, PurchaseOrder $order): RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:64'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'amount' => ['required'],
        ]);
        Invoices::record($order, $data, $this->user());

        return back()->with('success', 'Supplier invoice recorded.');
    }

    public function voidInvoice(Request $request, SupplierInvoice $invoice): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        Invoices::void($invoice, $data['reason'], $this->user());

        return back()->with('success', 'Invoice voided.');
    }

    public function paidInvoice(Request $request, SupplierInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
        ]);
        Invoices::markPaid($invoice, $data['paid_on'], $data['payment_reference'] ?? null, $this->user());

        return back()->with('success', 'Payment noted (recorded by hand, not verified).');
    }

    /**
     * A printable order for the supplier (the browser's print or save as PDF).
     */
    public function print(PurchaseOrder $order): View
    {
        abort_unless(in_array($order->status, ['approved', 'issued', 'partially_received', 'received', 'closed'], true), 404);
        $order->load(['supplier', 'lines', 'preparer:id,name']);

        return view('orders.print', [
            'order' => $order,
            'settings' => Workspace::settings(),
            'money' => fn (int $minor) => number_format($minor / (10 ** Money::decimals($order->currency)), Money::decimals($order->currency)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(PurchaseOrder $o): array
    {
        return [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'method' => $o->method,
            'supplier' => $o->supplier->name, 'supplierId' => $o->supplier_id, 'currency' => $o->currency,
            'total' => self::major($o->total_minor, $o->currency),
            'totalBase' => $o->currency === Money::base() ? null : self::major($o->total_base_minor, Money::base()),
            'deliveryDate' => self::date($o->getAttribute('delivery_date')),
            'late' => in_array($o->status, PurchaseOrder::RECEIVABLE, true) && $o->getAttribute('delivery_date') !== null && $o->getAttribute('delivery_date')->lt(now()->startOfDay()),
            'date' => self::date($o->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineRules(): array
    {
        return [
            'discount' => ['nullable'],
            'tax' => ['nullable'],
            'delivery' => ['nullable'],
            'method_reason' => ['nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array', 'min:1', 'max:100'],
            'lines.*.id' => ['nullable', 'uuid'],
            'lines.*.kind' => ['required', Rule::in(['goods', 'service'])],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit' => ['nullable', 'string', 'max:32'],
            'lines.*.unit_price' => ['required'],
            'lines.*.requisition_line_id' => ['nullable', 'uuid'],
        ];
    }
}
