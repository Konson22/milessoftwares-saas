<?php

namespace App\Procurement;

use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\Requisition;
use App\Procurement\Models\SourcingEvent;
use App\Procurement\Models\SupplierInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Dashboard and report figures, in the workspace currency's minor units.
 *
 * Committed, received, invoiced and paid are kept apart: an order is a
 * commitment, never expenditure. "Paid" here is only what was recorded by
 * hand as paid elsewhere; with Finance connected, payment is Finance's.
 */
final class Figures
{
    /**
     * @return array<string, int>
     */
    public static function overview(): array
    {
        $today = now()->toDateString();

        return [
            'pendingRequisitions' => Requisition::query()->where('status', 'pending_approval')->count(),
            'approvedUnbought' => Requisition::query()->where('status', 'approved')
                ->whereDoesntHave('sourcingEvents', fn ($q) => $q->where('status', '!=', 'cancelled'))
                ->whereDoesntHave('purchaseOrders', fn ($q) => $q->where('status', '!=', 'cancelled'))->count(),
            'activeSourcing' => SourcingEvent::query()->whereIn('status', ['pending_approval', 'issued'])->count(),
            'awaitingEvaluation' => SourcingEvent::query()->whereIn('status', ['closed', 'evaluation'])->count(),
            'ordersAwaitingDelivery' => PurchaseOrder::query()->whereIn('status', ['issued', 'partially_received'])->count(),
            'partialReceipts' => PurchaseOrder::query()->where('status', 'partially_received')->count(),
            'overdueOrders' => PurchaseOrder::query()->whereIn('status', ['issued', 'partially_received'])->whereNotNull('delivery_date')->where('delivery_date', '<', $today)->count(),
            'overdueRequisitions' => Requisition::query()->whereIn('status', ['pending_approval', 'approved'])->whereNotNull('needed_by')->where('needed_by', '<', $today)
                ->whereDoesntHave('purchaseOrders', fn ($q) => $q->where('status', '!=', 'cancelled'))->count(),
            ...self::amounts(),
        ];
    }

    /**
     * @param  Collection<int, PurchaseOrder>|null  $orders
     * @return array{committedMinor: int, invoicedMinor: int, paidExternallyMinor: int, sentToFinanceMinor: int}
     */
    public static function amounts(?Collection $orders = null): array
    {
        $orders ??= PurchaseOrder::query()->whereIn('status', PurchaseOrder::COMMITTED)->get(['id', 'total_base_minor']);
        $ids = $orders->pluck('id');
        $invoices = SupplierInvoice::query()->whereIn('purchase_order_id', $ids)->where('status', 'recorded')->get(['amount_base_minor', 'payment_status', 'finance_status']);

        return [
            'committedMinor' => (int) $orders->sum('total_base_minor'),
            'invoicedMinor' => (int) $invoices->sum('amount_base_minor'),
            'paidExternallyMinor' => (int) $invoices->where('payment_status', 'paid_externally')->sum('amount_base_minor'),
            'sentToFinanceMinor' => (int) $invoices->where('finance_status', 'sent')->sum('amount_base_minor'),
        ];
    }

    /**
     * Committed orders grouped by a column, with invoiced and paid alongside.
     *
     * @return list<array{key: string, label: string, orders: int, committedMinor: int, invoicedMinor: int, paidExternallyMinor: int}>
     */
    public static function by(string $dimension, ?string $from = null, ?string $to = null): array
    {
        $orders = PurchaseOrder::query()->with('supplier:id,name')
            ->whereIn('status', PurchaseOrder::COMMITTED)
            ->when($from, fn ($q) => $q->where('approved_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('approved_at', '<', CarbonImmutable::parse($to)->addDay()->toDateString()))
            ->get();

        $groups = $orders->groupBy(fn (PurchaseOrder $o): string => match ($dimension) {
            'supplier' => $o->supplier_id,
            'department' => (string) $o->getAttribute('department_id'),
            'method' => $o->method,
            'month' => $o->getAttribute('approved_at')?->format('Y-m') ?? '',
            default => '',
        });

        $rows = [];
        foreach ($groups as $key => $group) {
            $key = (string) $key;
            /** @var PurchaseOrder $first */
            $first = $group->first();
            $amounts = self::amounts($group);

            $rows[] = [
                'key' => $key,
                'label' => match ($dimension) {
                    'supplier' => $first->supplier->name,
                    'department' => $first->getAttribute('department_name') ?? 'No department',
                    'method' => Sourcing::LABELS[$first->method] ?? $first->method,
                    default => $key === '' ? 'Not approved yet' : $key,
                },
                'orders' => $group->count(),
                'committedMinor' => $amounts['committedMinor'],
                'invoicedMinor' => $amounts['invoicedMinor'],
                'paidExternallyMinor' => $amounts['paidExternallyMinor'],
            ];
        }
        usort($rows, fn (array $a, array $b) => $b['committedMinor'] <=> $a['committedMinor']);

        return $rows;
    }
}
