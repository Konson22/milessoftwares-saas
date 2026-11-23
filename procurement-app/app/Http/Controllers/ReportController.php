<?php

namespace App\Http\Controllers;

use App\Procurement\Figures;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\SupplierInvoice;
use App\Procurement\Money;
use App\Procurement\Sourcing;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tracked purchasing, in the workspace currency: committed (approved
 * orders), invoiced by suppliers and paid as recorded by hand. Orders are
 * commitments, not expenditure; Finance reports actual spending.
 */
class ReportController extends Controller
{
    public const DIMENSIONS = ['supplier', 'department', 'method', 'month'];

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'by' => ['nullable', Rule::in(self::DIMENSIONS)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $by = $data['by'] ?? 'supplier';
        $base = Money::base();

        return Inertia::render('reports/index', [
            'by' => $by,
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'currency' => $base,
            'rows' => array_map(fn (array $row) => [
                'label' => $row['label'],
                'orders' => $row['orders'],
                'committed' => self::major($row['committedMinor'], $base),
                'invoiced' => self::major($row['invoicedMinor'], $base),
                'paidExternally' => self::major($row['paidExternallyMinor'], $base),
            ], Figures::by($by, $data['from'] ?? null, $data['to'] ?? null)),
            'totals' => array_map(fn (int $minor) => self::major($minor, $base), Figures::amounts()),
            'unpaidInvoices' => SupplierInvoice::query()->with(['supplier:id,name', 'order:id,number'])
                ->where('status', 'recorded')->where('payment_status', 'unpaid')->where('finance_status', 'none')
                ->orderByRaw('due_date is null')->orderBy('due_date')->limit(100)->get()
                ->map(fn (SupplierInvoice $i) => [
                    'id' => $i->id, 'number' => $i->number, 'supplier' => $i->supplier->name, 'orderId' => $i->purchase_order_id,
                    'order' => $i->order->number, 'amount' => self::major($i->amount_minor, $i->currency), 'currency' => $i->currency,
                    'dueDate' => self::date($i->getAttribute('due_date')), 'matchStatus' => $i->match_status,
                ]),
        ]);
    }

    /**
     * Every order as CSV, for audit and spreadsheets.
     */
    public function export(): StreamedResponse
    {
        $base = Money::base();

        return response()->streamDownload(function () use ($base) {
            $out = fopen('php://output', 'w');
            assert($out !== false);
            fputcsv($out, ['Order', 'Revision', 'Status', 'Supplier', 'Method', 'Department', 'Currency', 'Total', "Total ({$base})", 'Approved', 'Issued', 'Delivery date', 'Requisition', 'Project reference', 'Funding reference'], escape: '');
            PurchaseOrder::query()->with(['supplier:id,name', 'requisition:id,number'])->orderBy('number')->chunk(500, function ($orders) use ($out, $base) {
                foreach ($orders as $o) {
                    /** @var PurchaseOrder $o */
                    fputcsv($out, [
                        $o->number, $o->revision, $o->status, $o->supplier->name, Sourcing::LABELS[$o->method] ?? $o->method,
                        $o->getAttribute('department_name'), $o->currency, Money::major($o->total_minor, $o->currency), Money::major($o->total_base_minor, $base),
                        self::date($o->getAttribute('approved_at')), self::date($o->getAttribute('issued_at')), self::date($o->getAttribute('delivery_date')),
                        $o->requisition?->number, $o->getAttribute('project_reference'), $o->getAttribute('funding_reference'),
                    ], escape: '');
                }
            });
            fclose($out);
        }, 'purchase-orders-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
