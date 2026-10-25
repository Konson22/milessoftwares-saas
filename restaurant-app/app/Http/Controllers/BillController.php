<?php

namespace App\Http\Controllers;

use App\Actions\Bills;
use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\Bill;
use App\Models\BillLine;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bills paid at the branch being worked in, and voiding one.
 */
class BillController extends Controller
{
    use WorksInBranch;

    public function index(Request $request): Response
    {
        $day = CarbonImmutable::parse(is_string($request->query('day')) ? $request->query('day') : 'today')->startOfDay();

        $bills = Bill::query()
            ->where('branch_id', $this->branchId())
            ->whereBetween('paid_at', [$day, $day->endOfDay()])
            ->with(['cashier:id,name', 'order:id,number,dining_table_id', 'order.table:id,name'])
            ->latest('paid_at')
            ->get();
        $kept = $bills->where('status', Bill::PAID);

        return Inertia::render('bills/index', [
            'day' => $day->toDateString(),
            'bills' => $bills->map(fn (Bill $bill) => [
                'id' => $bill->id,
                'number' => $bill->number,
                'paidAt' => $bill->paid_at->toIso8601String(),
                'paidBy' => $bill->cashier->name,
                'table' => $bill->order->table?->name,
                'customerName' => $bill->customer_name,
                'totalMinor' => $bill->total_minor,
                'paymentMethod' => $bill->payment_method->label(),
                'status' => $bill->status,
            ]),
            'takings' => ['count' => $kept->count(), 'totalMinor' => (int) $kept->sum('total_minor')],
        ]);
    }

    public function show(Bill $bill): Response
    {
        $this->ensureHere($bill->branch_id);
        $bill->load(['lines', 'cashier:id,name', 'voider:id,name', 'order:id,number,dining_table_id', 'order.table:id,name']);

        return Inertia::render('bills/show', [
            'bill' => [
                'id' => $bill->id,
                'number' => $bill->number,
                'status' => $bill->status,
                'paidAt' => $bill->paid_at->toIso8601String(),
                'paidBy' => $bill->cashier->name,
                'order' => ['id' => $bill->order->id, 'number' => $bill->order->number, 'table' => $bill->order->table?->name],
                'customerName' => $bill->customer_name,
                'currency' => $bill->currency,
                'totalMinor' => $bill->total_minor,
                'paymentMethod' => $bill->payment_method->label(),
                'paymentReference' => $bill->payment_reference,
                'tenderedMinor' => $bill->tendered_minor,
                'changeMinor' => $bill->tendered_minor === null ? null : $bill->change_minor,
                'voidedAt' => $bill->voided_at?->toIso8601String(),
                'voidedBy' => $bill->voider?->name,
                'voidReason' => $bill->void_reason,
                'lines' => $bill->lines->map(fn (BillLine $line) => [
                    'id' => $line->id,
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                    'unitPriceMinor' => $line->unit_price_minor,
                    'totalMinor' => $line->total_minor,
                ]),
            ],
            'canVoid' => $this->canHere('restaurant.bills.void'),
        ]);
    }

    public function void(Request $request, Bill $bill, Bills $bills): RedirectResponse
    {
        $this->ensureHere($bill->branch_id);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $voided = $bills->void($bill, $this->member($request), $validated['reason']);

        return to_route('bills.show', $voided)->with('success', "Bill {$voided->number} voided and refunded.");
    }
}
