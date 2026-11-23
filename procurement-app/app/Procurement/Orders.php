<?php

namespace App\Procurement;

use App\Models\User;
use App\Models\Workspace;
use App\Procurement\Models\Award;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\PurchaseOrderLine;
use App\Procurement\Models\PurchaseOrderRevision;
use App\Procurement\Models\ReceiptLine;
use App\Procurement\Models\Requisition;
use App\Procurement\Models\SourcingItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase orders (ADR-0023 §3). Raised from an approved award (prices are
 * the awarded quotation's) or, for a direct purchase within its limit, from
 * an approved requisition. Approved and issued orders are never changed in
 * place: an amendment snapshots the order, sends it back to draft as a new
 * revision and needs approval again.
 */
final class Orders
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromAward(Award $award, array $data, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($award, $data, $by) {
            $award = Award::query()->lockForUpdate()->with(['event.requisition', 'quotation.lines', 'supplier'])->findOrFail($award->id);
            if ($award->status !== 'approved') {
                throw ValidationException::withMessages(['award' => 'Only an approved award is ordered.']);
            }
            if (PurchaseOrder::query()->where('award_id', $award->id)->where('status', '!=', 'cancelled')->exists()) {
                throw ValidationException::withMessages(['award' => 'This award already has an order.']);
            }
            if (! $award->supplier->isActive()) {
                throw ValidationException::withMessages(['award' => "{$award->supplier->name} is {$award->supplier->status}."]);
            }

            $quotation = $award->quotation;
            $requisition = $award->event->requisition;
            $order = self::newOrder($requisition, $award->supplier_id, $award->event->method, $quotation->currency, $quotation->rate_minor, $data, $by, [
                'sourcing_event_id' => $award->sourcing_event_id,
                'award_id' => $award->id,
                'payment_terms' => $data['payment_terms'] ?? $quotation->getAttribute('payment_terms'),
            ]);

            $items = SourcingItem::query()->where('sourcing_event_id', $award->sourcing_event_id)->get()->keyBy('id');
            foreach ($quotation->lines as $line) {
                /** @var SourcingItem $item */
                $item = $items[$line->sourcing_item_id];
                PurchaseOrderLine::query()->create([
                    'purchase_order_id' => $order->id,
                    'line_no' => $item->line_no,
                    'kind' => $item->kind,
                    'description' => $item->description,
                    'quantity' => $line->quantity,
                    'unit' => $item->unit,
                    'unit_price_minor' => $line->unit_price_minor,
                    'amount_minor' => $line->amount_minor,
                    'requisition_line_id' => $item->requisition_line_id,
                ]);
            }
            self::total($order, $quotation->discount_minor, $quotation->tax_minor, $quotation->delivery_minor);
            AuditTrail::record('order.created', $order->load('supplier'), $by, ['from' => $award->event->number, 'total' => Money::format($order->total_minor, $order->currency)]);

            return $order;
        });
    }

    /**
     * A direct purchase: an approved requisition ordered from one supplier,
     * within the direct-purchase limit.
     *
     * @param  array<string, mixed>  $data
     */
    public static function direct(Requisition $requisition, array $data, User $by): PurchaseOrder
    {
        return DB::transaction(function () use ($requisition, $data, $by) {
            $requisition = Requisition::query()->lockForUpdate()->with('lines')->findOrFail($requisition->id);
            Requisitions::requireBuyable($requisition);
            $supplier = Suppliers::active((string) $data['supplier_id']);

            $currency = strtoupper((string) ($data['currency'] ?? $requisition->currency));
            $rate = Money::rate($currency);
            $order = self::newOrder($requisition, $supplier->id, 'direct', $currency, $rate, $data, $by, [
                'method_reason' => $data['method_reason'] ?? null,
            ]);
            self::writeLines($order, (array) $data['lines'], $requisition);
            self::total($order, ...self::extras($data, $currency));

            Sourcing::checkMethod('direct', $order->total_base_minor, null);
            AuditTrail::record('order.created', $order->load('supplier'), $by, ['method' => 'Direct purchase', 'total' => Money::format($order->total_minor, $currency)]);

            return $order;
        });
    }

    /**
     * Changes a draft: header always; lines on a direct order or an
     * amendment (an awarded first revision keeps the awarded prices).
     *
     * @param  array<string, mixed>  $data
     */
    public static function update(PurchaseOrder $order, array $data, User $by): void
    {
        DB::transaction(function () use ($order, $data, $by) {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            self::requireStatus($order, ['draft'], 'Only a draft order is changed. Amend an approved one.');

            $order->fill([
                'delivery_date' => $data['delivery_date'] ?? null,
                'delivery_address' => $data['delivery_address'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'notes' => $data['notes'] ?? null,
            ])->save();

            if (isset($data['lines']) && ($order->award_id === null || $order->revision > 1)) {
                $received = self::accepted($order);
                self::writeLines($order, (array) $data['lines'], $order->requisition, $received);
                self::total($order, ...self::extras($data, $order->currency));
                if ($order->method === 'direct') {
                    Sourcing::checkMethod('direct', $order->total_base_minor, null);
                }
            }
            AuditTrail::record('order.updated', $order->load('supplier'), $by, ['total' => Money::format($order->total_minor, $order->currency)]);
        });
    }

    public static function submit(PurchaseOrder $order, User $by): void
    {
        DB::transaction(function () use ($order, $by) {
            $order = PurchaseOrder::query()->lockForUpdate()->with('supplier')->findOrFail($order->id);
            self::requireStatus($order, ['draft'], 'Only a draft order is submitted.');
            if (! $order->supplier->isActive()) {
                throw ValidationException::withMessages(['order' => "{$order->supplier->name} is {$order->supplier->status}."]);
            }

            $needsApproval = Approvals::start('order', $order, $order->total_base_minor, $order->getAttribute('department_id'), $order->method, $by);
            $order->forceFill([
                'status' => $needsApproval ? 'pending_approval' : 'approved',
                'prepared_by' => $by->id,
                'approved_at' => $needsApproval ? null : now(),
            ])->save();
            AuditTrail::record('order.submitted', $order, $by, ['revision' => $order->revision]);
        });
    }

    public static function decide(PurchaseOrder $order, string $decision, ?string $note, User $by): void
    {
        DB::transaction(function () use ($order, $decision, $note, $by) {
            $order = PurchaseOrder::query()->lockForUpdate()->with('supplier')->findOrFail($order->id);
            self::requireStatus($order, ['pending_approval'], 'This order is not waiting for approval.');

            $outcome = Approvals::decide('order', $order, $by, $decision, $note);
            match ($outcome) {
                'complete' => $order->forceFill(['status' => 'approved', 'approved_at' => now()])->save(),
                'rejected', 'returned' => $order->forceFill(['status' => 'draft', 'status_reason' => $note])->save(),
                default => null,
            };
            AuditTrail::record('order.'.match ($outcome) {
                'complete' => 'approved',
                'pending' => 'step_approved',
                default => $outcome,
            }, $order, $by, ['note' => $note]);
        });
    }

    public static function issue(PurchaseOrder $order, User $by): void
    {
        DB::transaction(function () use ($order, $by) {
            $order = PurchaseOrder::query()->lockForUpdate()->with('supplier')->findOrFail($order->id);
            self::requireStatus($order, ['approved'], 'Only an approved order is issued to the supplier.');
            $received = self::accepted($order);
            $order->forceFill(['status' => $received === [] ? 'issued' : self::receivedStatus($order), 'issued_at' => now()])->save();
            AuditTrail::record('order.issued', $order, $by, ['revision' => $order->revision]);
        });
    }

    /**
     * Opens an approved or issued order for change: what it said is kept as
     * a revision, and the new revision needs approval again.
     */
    public static function amend(PurchaseOrder $order, string $reason, User $by): void
    {
        DB::transaction(function () use ($order, $reason, $by) {
            $order = PurchaseOrder::query()->lockForUpdate()->with(['lines', 'supplier'])->findOrFail($order->id);
            self::requireStatus($order, ['approved', 'issued', 'partially_received'], 'Only an approved or issued order not yet fully received is amended.');
            if ($order->invoices()->where('status', 'recorded')->exists()) {
                throw ValidationException::withMessages(['order' => 'Supplier invoices are recorded against this order. Void them before amending it.']);
            }

            PurchaseOrderRevision::query()->create([
                'purchase_order_id' => $order->id,
                'revision' => $order->revision,
                'snapshot' => [
                    'status' => $order->status,
                    'total_minor' => $order->total_minor,
                    'currency' => $order->currency,
                    'delivery_date' => $order->getAttribute('delivery_date')?->toDateString(),
                    'payment_terms' => $order->getAttribute('payment_terms'),
                    'lines' => $order->lines->map(fn (PurchaseOrderLine $l) => $l->only(['line_no', 'description', 'quantity', 'unit', 'unit_price_minor', 'amount_minor']))->all(),
                ],
                'reason' => $reason,
                'created_by' => $by->id,
            ]);
            $order->forceFill(['status' => 'draft', 'revision' => $order->revision + 1, 'approved_at' => null, 'status_reason' => $reason])->save();
            AuditTrail::record('order.amended', $order, $by, ['reason' => $reason, 'revision' => $order->revision]);
        });
    }

    public static function cancel(PurchaseOrder $order, string $reason, User $by): void
    {
        DB::transaction(function () use ($order, $reason, $by) {
            $order = PurchaseOrder::query()->lockForUpdate()->with('supplier')->findOrFail($order->id);
            self::requireStatus($order, ['draft', 'pending_approval', 'approved', 'issued'], 'An order something was received against is closed, not cancelled.');
            if (ReceiptLine::query()->whereIn('purchase_order_line_id', $order->lines()->select('id'))->exists()) {
                throw ValidationException::withMessages(['order' => 'Goods or services were received on this order; close it instead.']);
            }
            if ($order->invoices()->where('status', 'recorded')->exists()) {
                throw ValidationException::withMessages(['order' => 'Supplier invoices are recorded against this order. Void them first.']);
            }

            Approvals::withdraw('order', $order);
            $order->forceFill(['status' => 'cancelled', 'status_reason' => $reason])->save();
            AuditTrail::record('order.cancelled', $order, $by, ['reason' => $reason]);
        });
    }

    /**
     * Closes an order: fully received, or short with a reason (what did not
     * arrive is no longer owed).
     */
    public static function close(PurchaseOrder $order, ?string $reason, User $by): void
    {
        DB::transaction(function () use ($order, $reason, $by) {
            $order = PurchaseOrder::query()->lockForUpdate()->with('supplier')->findOrFail($order->id);
            self::requireStatus($order, ['received', 'partially_received'], 'An order is closed once something was received; cancel one that received nothing.');
            if ($order->status === 'partially_received' && trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => 'Say why the rest will not be delivered.']);
            }

            $short = $order->status === 'partially_received';
            $order->forceFill(['status' => 'closed', 'closed_at' => now(), 'status_reason' => $reason])->save();
            AuditTrail::record('order.closed', $order, $by, ['reason' => $reason, 'short' => $short ? 'yes' : null]);
            Requisitions::closeIfDone($order->requisition_id, $by);
        });
    }

    /**
     * Accepted quantity per order line, as exact decimal strings.
     *
     * @return array<string, numeric-string>
     */
    public static function accepted(PurchaseOrder $order): array
    {
        return ReceiptLine::query()
            ->whereIn('purchase_order_line_id', $order->lines()->select('id'))
            ->get(['purchase_order_line_id', 'accepted_quantity'])
            ->groupBy('purchase_order_line_id')
            ->map(fn ($rows) => $rows->reduce(fn (string $sum, ReceiptLine $r) => bcadd(Money::quantity($sum), Money::quantity($r->accepted_quantity), 3), '0.000'))
            ->all();
    }

    /**
     * What has been accepted, valued at the order's prices, with its share
     * of tax, delivery and discount; in the order currency.
     */
    public static function acceptedValue(PurchaseOrder $order): int
    {
        $accepted = self::accepted($order);
        $value = 0;
        foreach ($order->lines()->get() as $line) {
            $value += Money::lineAmount($accepted[$line->id] ?? '0', $line->unit_price_minor);
        }
        if ($order->subtotal_minor <= 0) {
            return 0;
        }

        return (int) bcadd(bcdiv(bcmul((string) $order->total_minor, (string) $value), (string) $order->subtotal_minor, 3), '0.5', 0);
    }

    public static function receivedStatus(PurchaseOrder $order): string
    {
        $accepted = self::accepted($order);
        $all = $order->lines()->get()->every(fn (PurchaseOrderLine $l) => bccomp($accepted[$l->id] ?? '0', Money::quantity($l->quantity), 3) >= 0);
        $any = collect($accepted)->contains(fn ($q) => bccomp($q, '0', 3) > 0);

        return $all ? 'received' : ($any ? 'partially_received' : 'issued');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $extra
     */
    private static function newOrder(?Requisition $requisition, string $supplierId, string $method, string $currency, ?int $rate, array $data, User $by, array $extra): PurchaseOrder
    {
        $settings = Workspace::settings();

        return PurchaseOrder::query()->create([
            'number' => Numbers::next('order'),
            'supplier_id' => $supplierId,
            'requisition_id' => $requisition?->id,
            'method' => $method,
            'department_id' => $requisition?->department_id,
            'department_name' => $requisition?->department_name,
            'currency' => $currency,
            'rate_minor' => $rate,
            'subtotal_minor' => 0,
            'total_minor' => 0,
            'total_base_minor' => 0,
            'delivery_date' => $data['delivery_date'] ?? $requisition?->getAttribute('needed_by'),
            'delivery_address' => $data['delivery_address'] ?? $requisition?->getAttribute('delivery_location') ?? $settings->delivery_address,
            'payment_terms' => $data['payment_terms'] ?? null,
            'notes' => $data['notes'] ?? $settings->order_terms,
            'budget_reference' => $requisition?->getAttribute('budget_reference'),
            'project_reference' => $requisition?->getAttribute('project_reference'),
            'funding_reference' => $requisition?->getAttribute('funding_reference'),
            'status' => 'draft',
            'prepared_by' => $by->id,
            ...$extra,
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $lines  as validated by the controller
     * @param  array<string, numeric-string>  $received  accepted quantity per existing line
     */
    private static function writeLines(PurchaseOrder $order, array $lines, ?Requisition $requisition, array $received = []): void
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'An order needs at least one line.']);
        }

        $existing = $order->lines()->get()->keyBy('id');
        $kept = [];
        foreach (array_values($lines) as $i => $line) {
            if (! is_array($line)) {
                throw ValidationException::withMessages(["lines.{$i}" => 'Each line needs a description, quantity and price.']);
            }
            $id = $line['id'] ?? null;
            $quantity = Money::quantity($line['quantity'] ?? '', "lines.{$i}.quantity");
            if ($id !== null && isset($received[$id]) && bccomp($quantity, $received[$id], 3) < 0) {
                throw ValidationException::withMessages(["lines.{$i}.quantity" => "{$received[$id]} already accepted; the quantity cannot go below it."]);
            }
            $price = Money::parse($line['unit_price'], $order->currency, "lines.{$i}.unit_price");
            $values = [
                'line_no' => $i + 1,
                'kind' => $line['kind'] ?? 'goods',
                'description' => $line['description'],
                'quantity' => $quantity,
                'unit' => $line['unit'] ?? null,
                'unit_price_minor' => $price,
                'amount_minor' => Money::lineAmount($quantity, $price),
                'requisition_line_id' => $line['requisition_line_id'] ?? null,
            ];
            if ($id !== null && $existing->has($id)) {
                $existing[$id]->fill($values)->save();
                $kept[] = $id;
            } else {
                $kept[] = PurchaseOrderLine::query()->create(['purchase_order_id' => $order->id, ...$values])->id;
            }
        }

        foreach ($existing as $id => $line) {
            if (! in_array($id, $kept, true)) {
                if (isset($received[$id])) {
                    throw ValidationException::withMessages(['lines' => "\"{$line->description}\" was received; it stays on the order."]);
                }
                $line->delete();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: int, 2: int}
     */
    private static function extras(array $data, string $currency): array
    {
        return [
            Money::parse($data['discount'] ?? '0', $currency, 'discount'),
            Money::parse($data['tax'] ?? '0', $currency, 'tax'),
            Money::parse($data['delivery'] ?? '0', $currency, 'delivery'),
        ];
    }

    private static function total(PurchaseOrder $order, int $discount, int $tax, int $delivery): void
    {
        $subtotal = (int) $order->lines()->sum('amount_minor');
        $total = $subtotal - $discount + $tax + $delivery;
        if ($total <= 0) {
            throw ValidationException::withMessages(['discount' => 'The order total must be above zero.']);
        }
        $order->forceFill([
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'tax_minor' => $tax,
            'delivery_minor' => $delivery,
            'total_minor' => $total,
            'total_base_minor' => Money::toBase($total, $order->currency, $order->rate_minor),
        ])->save();
    }

    /**
     * @param  list<string>  $statuses
     */
    private static function requireStatus(PurchaseOrder $order, array $statuses, string $message): void
    {
        if (! in_array($order->status, $statuses, true)) {
            throw ValidationException::withMessages(['order' => $message]);
        }
    }
}
