<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Actions\CounterPrinting;
use App\Modules\Sales\Enums\CounterWorkflow;
use App\Modules\Sales\Models\BranchCounterSettings;
use App\Modules\Sales\Models\CounterOrder;
use App\Modules\Sales\Models\CounterOrderLine;
use App\Modules\Sales\Models\PrintedDocument;
use App\Modules\Sales\Models\Sale;
use App\Support\CurrentBranch;
use App\Support\Industry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The counter's printed documents, laid out as the branch set them up: the
 * receipt of a sale, and an order's slip (to take to the cashier or the
 * dispensary) and collection slip.
 *
 * Opened with ?auto=1 after a step, the page prints by itself only when it
 * claims the original (CounterPrinting); otherwise it waits for someone to
 * print it, and each such print is logged as a numbered reprint first.
 */
class CounterPrintController extends Controller
{
    public function __construct(
        private readonly CurrentBranch $branch,
        private readonly Industry $industry,
        private readonly CounterPrinting $printing,
    ) {}

    public function sale(Request $request, Sale $sale): Response
    {
        abort_unless($sale->branch_id === $this->branch->id() && $sale->application === $this->industry->code(), 404);
        $sale->load(['seller:id,name', 'lines.item']);
        $order = CounterOrder::query()->where('sale_id', $sale->id)->with('handler:id,name')->first();
        $settings = $this->settings();

        return $this->render($request, PrintedDocument::RECEIPT, $sale, [
            'title' => $sale->status === Sale::VOIDED ? 'Receipt (voided)' : 'Receipt',
            'number' => $sale->number,
            'orderNumber' => $order?->number,
            'barcode' => $order->number ?? $sale->number,
            'at' => $sale->sold_at->toIso8601String(),
            'staff' => [
                ['role' => 'Cashier', 'name' => $sale->seller->name],
                ...($order?->handler === null ? [] : [['role' => $order->workflow->handOver() === 'collection' ? 'Handed over by' : 'Dispensed by', 'name' => $order->handler->name]]),
            ],
            'customerName' => $sale->customer_name,
            'customerPhone' => $sale->customer_phone,
            'prices' => true,
            // One line per medicine (not per batch); by the pack, so many packs.
            'lines' => $sale->lines->groupBy('item_id')->map(fn ($lines) => [
                'name' => $lines->first()->item->name,
                'pack' => $lines->first()->pack_name === null ? null : "{$lines->first()->pack_name} of {$lines->first()->units_per_pack}",
                'quantity' => intdiv((int) $lines->sum('quantity'), $lines->first()->units_per_pack),
                'unitPriceMinor' => $lines->first()->pack_price_minor ?? $lines->first()->unit_price_minor,
                'discountMinor' => (int) $lines->sum('discount_minor'),
                'lineTotalMinor' => (int) $lines->sum('line_total_minor'),
            ])->values(),
            'totalMinor' => $sale->total_minor,
            'discountMinor' => $sale->discount_minor,
            'payment' => [
                'method' => $sale->payment_method->label(),
                'reference' => $sale->payment_reference,
                'tenderedMinor' => $sale->amount_tendered_minor,
                'changeMinor' => $sale->change_minor,
            ],
            'instruction' => null,
            'voided' => $sale->status === Sale::VOIDED,
        ], $settings);
    }

    public function order(Request $request, CounterOrder $order, string $document): Response
    {
        abort_unless($order->branch_id === $this->branch->id() && $order->application === $this->industry->code(), 404);
        abort_unless(in_array($document, [PrintedDocument::ORDER_SLIP, PrintedDocument::COLLECTION_SLIP], true), 404);
        // A collection slip is proof of payment: only for paid orders that are collected.
        abort_if($document === PrintedDocument::COLLECTION_SLIP && (! $order->isPaid() || $order->workflow !== CounterWorkflow::OrderPayCollect), 404);
        $order->load(['orderer:id,name', 'sale:id,number', 'lines.item', 'lines.pack']);
        $settings = $this->settings();
        $slip = $document === PrintedDocument::ORDER_SLIP;

        return $this->render($request, $document, $order, [
            'title' => $slip ? 'Order slip' : 'Collection slip',
            'number' => $order->number,
            'orderNumber' => $order->number,
            'receiptNumber' => $order->sale?->number,
            'barcode' => $order->number,
            'at' => ($slip ? $order->ordered_at : $order->paid_at ?? $order->ordered_at)->toIso8601String(),
            'staff' => [['role' => 'Order taken by', 'name' => $order->orderer->name]],
            'customerName' => $order->customer_name,
            'customerPhone' => $order->customer_phone,
            'prices' => $settings->slip_prices,
            'lines' => $order->lines->map(fn (CounterOrderLine $line) => [
                'name' => $line->item->name,
                'pack' => $line->pack === null ? null : "{$line->pack->name} of {$line->pack->units}",
                'quantity' => $line->quantity,
                'unitPriceMinor' => null,
                'discountMinor' => 0,
                'lineTotalMinor' => $line->line_total_minor,
            ])->values(),
            'totalMinor' => $order->total_minor,
            'discountMinor' => 0,
            'payment' => null,
            'instruction' => match (true) {
                ! $slip => 'Paid. Show this slip at the collection point.',
                $order->workflow->paysFirst() && ! $order->isPaid() => 'Take this slip to the cashier to pay.',
                $order->workflow->paysFirst() => 'Paid. Take this slip to the dispensary.',
                $order->isHandedOver() && ! $order->isPaid() => 'Pay at the cashier with this slip.',
                default => 'Take this slip to the dispensary.',
            },
            'voided' => $order->status === CounterOrder::CANCELLED,
        ], $settings);
    }

    /**
     * Logs a print someone asked for (a reprint, or the first print when
     * nothing printed by itself), just before it prints.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'document' => ['required', Rule::in(PrintedDocument::DOCUMENTS)],
            'reference_id' => ['required', 'uuid'],
        ]);

        $reference = $validated['document'] === PrintedDocument::RECEIPT
            ? Sale::query()->whereKey($validated['reference_id'])->where('application', $this->industry->code())->first()
            : CounterOrder::query()->whereKey($validated['reference_id'])->where('application', $this->industry->code())->first();
        abort_if($reference === null || $reference->branch_id !== $this->branch->id(), 404);

        $this->printing->record($validated['document'], $reference, $this->user($request), automatic: false);

        return back();
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function render(Request $request, string $type, Sale|CounterOrder $reference, array $document, BranchCounterSettings $settings): Response
    {
        // Printing by itself: only when this page claims the original.
        $auto = $request->boolean('auto') && $this->printing->record($type, $reference, $this->user($request), automatic: true) !== null;

        return Inertia::render($this->industry->page('print/document'), [
            'document' => ['type' => $type, 'referenceId' => $reference->getKey(), ...$document],
            'copies' => $this->printing->copies($type, $reference->getKey()),
            'autoPrint' => $auto,
            'layout' => [
                'paperSize' => $settings->paper_size,
                'printerName' => $settings->printer_name,
                'header' => $settings->receipt_header,
                'footer' => $settings->receipt_footer,
                'businessName' => $settings->business_name,
                'address' => $settings->show_address ? $settings->address : null,
                'contact' => $settings->show_contact ? $settings->contact : null,
                'logo' => $settings->show_logo ? $settings->logo : null,
                'showCustomer' => $settings->show_customer,
                'showStaff' => $settings->show_staff,
                'showBarcode' => $settings->show_barcode,
            ],
        ]);
    }

    private function settings(): BranchCounterSettings
    {
        return BranchCounterSettings::forBranch($this->branch->id(), $this->industry->code());
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
