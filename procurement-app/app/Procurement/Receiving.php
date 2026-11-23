<?php

namespace App\Procurement;

use App\Models\User;
use App\Models\Workspace;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\Receipt;
use App\Procurement\Models\ReceiptLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Receiving goods and accepting services (ADR-0023 §3), with or without
 * an Inventory application: Procurement keeps the purchasing and
 * acceptance record; stock balances and valuation are never kept here.
 *
 * Each line received is split into accepted and rejected; only accepted
 * quantities count against the order, so rejected goods can be delivered
 * again. Receipts are never edited. Whoever prepared the order does not
 * also confirm its delivery, unless settings allow one person both.
 */
final class Receiving
{
    /**
     * @param  array<string, mixed>  $data  received_on, delivery_note, inspection, notes, lines[{line_id, received, accepted, rejected, rejection_reason}]
     */
    public static function receive(PurchaseOrder $order, array $data, User $by): Receipt
    {
        return DB::transaction(function () use ($order, $data, $by) {
            $order = PurchaseOrder::query()->lockForUpdate()->with(['lines', 'supplier'])->findOrFail($order->id);
            if (! in_array($order->status, PurchaseOrder::RECEIVABLE, true)) {
                throw ValidationException::withMessages(['order' => 'Deliveries are recorded against an issued order that is not fully received.']);
            }
            if ($order->prepared_by === $by->id && ! Workspace::settings()->allow_self_approval) {
                throw ValidationException::withMessages(['order' => 'You prepared this order, so someone else confirms its delivery.']);
            }

            $accepted = Orders::accepted($order);
            $lines = $order->lines->keyBy('id');
            $rows = [];
            foreach (array_values((array) $data['lines']) as $i => $line) {
                $orderLine = $lines[$line['line_id'] ?? ''] ?? null;
                if ($orderLine === null) {
                    throw ValidationException::withMessages(["lines.{$i}.line_id" => 'Use the lines of this order.']);
                }
                $acceptedQty = Money::quantity(($line['accepted'] ?? '') === '' ? '0' : $line['accepted'], "lines.{$i}.accepted");
                $rejectedQty = Money::quantity(($line['rejected'] ?? '') === '' ? '0' : $line['rejected'], "lines.{$i}.rejected");
                if (bccomp($acceptedQty, '0', 3) < 0 || bccomp($rejectedQty, '0', 3) < 0) {
                    throw ValidationException::withMessages(["lines.{$i}.accepted" => 'Quantities cannot be negative.']);
                }
                $receivedQty = bcadd($acceptedQty, $rejectedQty, 3);
                if (bccomp($receivedQty, '0', 3) === 0) {
                    continue;
                }
                $remaining = bcsub(Money::quantity($orderLine->quantity), $accepted[$orderLine->id] ?? '0', 3);
                if (bccomp($acceptedQty, $remaining, 3) > 0) {
                    throw ValidationException::withMessages(["lines.{$i}.accepted" => "Only {$remaining} of \"{$orderLine->description}\" remain to be accepted."]);
                }
                if (bccomp($rejectedQty, '0', 3) > 0 && trim((string) ($line['rejection_reason'] ?? '')) === '') {
                    throw ValidationException::withMessages(["lines.{$i}.rejection_reason" => 'Say why it was rejected.']);
                }
                $rows[] = [$orderLine->id, $receivedQty, $acceptedQty, $rejectedQty, $line['rejection_reason'] ?? null];
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['lines' => 'Enter what arrived.']);
            }

            $receipt = Receipt::query()->create([
                'number' => Numbers::next('receipt'),
                'purchase_order_id' => $order->id,
                'received_on' => $data['received_on'],
                'delivery_note' => $data['delivery_note'] ?? null,
                'inspection' => $data['inspection'],
                'notes' => $data['notes'] ?? null,
                'received_by' => $by->id,
                'received_by_name' => $by->name,
            ]);
            foreach ($rows as [$lineId, $received, $acceptedQty, $rejectedQty, $reason]) {
                ReceiptLine::query()->create([
                    'receipt_id' => $receipt->id,
                    'purchase_order_line_id' => $lineId,
                    'received_quantity' => $received,
                    'accepted_quantity' => $acceptedQty,
                    'rejected_quantity' => $rejectedQty,
                    'rejection_reason' => $reason,
                ]);
            }

            $order->forceFill(['status' => Orders::receivedStatus($order)])->save();
            $receipt->setRelation('order', $order);
            AuditTrail::record('receipt.recorded', $receipt, $by, ['inspection' => $data['inspection'], 'order_status' => $order->status]);

            // Invoices waiting for these goods may now match.
            Invoices::rematch($order, $by);

            return $receipt;
        });
    }
}
