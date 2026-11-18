<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\Bill;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Support\DocumentNumbers;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orders at a restaurant's tables and for takeaway: dishes and drinks added
 * with a note for the kitchen, sent to it as tickets, and billed.
 *
 * A table has one open order at a time: opening one at a table that is
 * already being served gives that order (a second waiter joins it). Lines
 * are priced at the bill, at today's menu prices. A line not yet sent is
 * simply removed; one the kitchen has is voided, with a reason, and stays
 * on the order unbilled. Paying makes the order's bill (Bills).
 *
 * An order may be paid in parts, each its own bill: some of its lines (each
 * guest their own), or equal shares of what is left (part 1 of 3, 2 of 3…;
 * the last takes the rest, to the minor unit). The order closes when nothing
 * is left to pay. A line once paid cannot be voided (void its bill), and an
 * order part paid cannot be cancelled.
 *
 * Every step locks the order, so a double click or two waiters acting at
 * once do one thing: a paid or cancelled order never changes.
 */
class Orders
{
    public function __construct(
        private readonly Bills $bills,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * $id: the order's id when the mobile app made it offline (OutboxController).
     * A table already being served still gives its order, with its own id.
     *
     * @throws ValidationException
     */
    public function open(User $by, string $branchId, string $service, ?string $tableId = null, ?int $guests = null, ?string $customerName = null, ?string $id = null): Order
    {
        if ($service === Order::DINE_IN && $tableId === null) {
            throw ValidationException::withMessages(['dining_table_id' => 'Choose the table.']);
        }

        try {
            return DB::transaction(function () use ($by, $branchId, $service, $tableId, $guests, $customerName, $id) {
                $table = null;
                if ($service === Order::DINE_IN) {
                    $table = DiningTable::query()->whereKey($tableId)->where('branch_id', $branchId)->where('active', true)->lockForUpdate()->first()
                        ?? throw ValidationException::withMessages(['dining_table_id' => 'This table is not in use at this branch.']);

                    if (($open = $this->openAt($table)) !== null) {
                        return $open;
                    }
                }

                if ($id !== null && Order::query()->withoutGlobalScopes()->whereKey($id)->exists()) {
                    throw ValidationException::withMessages(['order' => 'This order id is already taken.']);
                }

                return Order::query()->create([
                    ...($id === null ? [] : ['id' => $id]),
                    'branch_id' => $branchId,
                    'number' => $this->numbers->next('order', 'ORD', 6),
                    'service' => $service,
                    'dining_table_id' => $table?->id,
                    'guests' => $guests,
                    'customer_name' => ($name = trim((string) $customerName)) === '' ? null : $name,
                    'status' => Order::OPEN,
                    'opened_by' => $by->id,
                    'opened_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Opened at the same table by a request running alongside.
            if ($tableId !== null && ($open = Order::query()->where('dining_table_id', $tableId)->where('status', Order::OPEN)->first()) !== null) {
                return $open;
            }

            throw $e;
        }
    }

    /**
     * Adds dishes and drinks, not yet sent to the kitchen: only what is on
     * the menu now.
     *
     * @param  list<array{item_id: string, quantity: int, note?: string|null, id?: string}>  $lines  (id: made offline by the mobile app)
     *
     * @throws ValidationException
     */
    public function add(Order $order, User $by, array $lines): Order
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Add at least one dish or drink.']);
        }

        $ids = array_values(array_unique(array_column($lines, 'item_id')));
        $onMenu = MenuItem::query()->whereKey($ids)->where('active', true)->count();
        if ($onMenu !== count($ids)) {
            throw ValidationException::withMessages(['lines' => 'Something chosen is no longer on the menu.']);
        }

        $given = array_values(array_filter(array_column($lines, 'id')));

        return DB::transaction(function () use ($order, $by, $lines, $given) {
            $locked = $this->lockOpen($order, 'lines');

            // Checked under the order's lock: the same lines sent twice at once wait here.
            if ($given !== [] && OrderLine::query()->whereKey($given)->exists()) {
                throw ValidationException::withMessages(['lines' => 'These lines are already on an order.']);
            }

            foreach ($lines as $line) {
                $locked->lines()->create([
                    ...(isset($line['id']) ? ['id' => $line['id']] : []),
                    'menu_item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'note' => ($note = trim((string) ($line['note'] ?? ''))) === '' ? null : $note,
                    'added_by' => $by->id,
                    'added_at' => now(),
                ]);
            }

            return $locked;
        });
    }

    /**
     * Takes a line off before the kitchen has it.
     *
     * @throws ValidationException
     */
    public function remove(Order $order, OrderLine $line): void
    {
        DB::transaction(function () use ($order, $line) {
            $locked = $this->lockOpen($order, 'line');
            $current = $this->lineOf($locked, $line);

            if ($current->sent()) {
                throw ValidationException::withMessages(['line' => 'The kitchen already has this: void it instead.']);
            }

            $current->delete();
        });
    }

    /**
     * Sends every new line to the kitchen on one ticket.
     *
     * @throws ValidationException when there is nothing new
     */
    public function send(Order $order, User $by): KitchenTicket
    {
        return DB::transaction(function () use ($order, $by) {
            $locked = $this->lockOpen($order, 'lines');
            $new = $locked->lines()->whereNull('kitchen_ticket_id')->whereNull('voided_at')->get();

            if ($new->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Nothing new to send to the kitchen.']);
            }

            $ticket = KitchenTicket::query()->create([
                'branch_id' => $locked->branch_id,
                'order_id' => $locked->id,
                'number' => $this->numbers->next('kitchen_ticket', 'KT', 6),
                'status' => KitchenTicket::PREPARING,
                'sent_by' => $by->id,
                'sent_at' => now(),
            ]);

            // Not through lines(): its ordering does not belong in an update.
            OrderLine::query()->whereKey($new->modelKeys())->update(['kitchen_ticket_id' => $ticket->id]);

            return $ticket;
        });
    }

    /**
     * Voids a line the kitchen already has: it stays on the order and its
     * ticket, struck through, and is never billed.
     *
     * @throws ValidationException
     */
    public function void(Order $order, OrderLine $line, User $by, string $reason): void
    {
        DB::transaction(function () use ($order, $line, $by, $reason) {
            $locked = $this->lockOpen($order, 'line');
            $current = $this->lineOf($locked, $line);

            if ($current->voided()) {
                throw ValidationException::withMessages(['line' => 'This is already voided.']);
            }
            if (! $current->sent()) {
                throw ValidationException::withMessages(['line' => 'The kitchen does not have this yet: remove it instead.']);
            }
            if ($current->bill_id !== null) {
                throw ValidationException::withMessages(['line' => 'This is paid already: void its bill instead.']);
            }

            $current->forceFill(['voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => trim($reason)])->save();

            // Part paid and nothing else left to pay: the order is paid.
            if ($this->unbilled($locked)->isEmpty() && $this->keptBills($locked)->isNotEmpty()) {
                $locked->forceFill(['status' => Order::PAID, 'closed_by' => $by->id, 'closed_at' => now()])->save();
            }
        });
    }

    /**
     * Moves a table's order to another free table of the branch.
     *
     * @throws ValidationException
     */
    public function move(Order $order, string $tableId): Order
    {
        try {
            return DB::transaction(function () use ($order, $tableId) {
                $locked = $this->lockOpen($order, 'dining_table_id');
                if ($locked->service !== Order::DINE_IN) {
                    throw ValidationException::withMessages(['dining_table_id' => 'A takeaway has no table.']);
                }

                $table = DiningTable::query()->whereKey($tableId)->where('branch_id', $locked->branch_id)->where('active', true)->lockForUpdate()->first()
                    ?? throw ValidationException::withMessages(['dining_table_id' => 'This table is not in use at this branch.']);
                if ($table->id === $locked->dining_table_id) {
                    return $locked;
                }
                if (($other = $this->openAt($table)) !== null) {
                    throw ValidationException::withMessages(['dining_table_id' => "{$table->name} is serving order {$other->number}."]);
                }

                $locked->forceFill(['dining_table_id' => $table->id])->save();

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['dining_table_id' => 'Someone has just seated that table.']);
        }
    }

    /**
     * What is left to pay: the lines not voided nor paid, by item, at
     * today's menu prices (as paying will charge them), less the equal
     * shares already paid. lines_open: each such line on its own, for paying
     * some of them.
     *
     * @return array{lines: list<array{item_id: string, name: string, quantity: int, unit_price_minor: int, total_minor: int}>, lines_open: list<array{id: string, name: string, quantity: int, note: string|null, total_minor: int}>, total_minor: int, subtotal_minor: int, paid_minor: int, shares: array{parts: int, paid: int, paid_minor: int, next_minor: int}|null}
     */
    public function bill(Order $order): array
    {
        $open = $this->unbilled($order);
        ['lines' => $lines, 'total_minor' => $subtotal] = $this->bills->price($this->bills->quantities($open));
        $prices = collect($lines)->mapWithKeys(fn (array $line) => [$line['item']->id => $line['unit_price_minor']]);
        $kept = $this->keptBills($order);
        $shares = $kept->filter(fn (Bill $bill) => $bill->isShare());
        $due = max($subtotal - (int) $shares->sum('subtotal_minor'), 0);
        $parts = $shares->first()?->parts;

        return [
            'lines' => array_map(fn (array $line) => [
                'item_id' => $line['item']->id,
                'name' => $line['item']->name,
                'quantity' => $line['quantity'],
                'unit_price_minor' => $line['unit_price_minor'],
                'total_minor' => $line['total_minor'],
            ], $lines),
            'lines_open' => array_values($open->load('item:id,name')->map(fn (OrderLine $line) => [
                'id' => $line->id,
                'name' => $line->item->name,
                'quantity' => $line->quantity,
                'note' => $line->note,
                'total_minor' => $line->quantity * (int) $prices->get($line->menu_item_id, 0),
            ])->all()),
            'total_minor' => $due,
            'subtotal_minor' => $subtotal,
            'paid_minor' => (int) $kept->sum('total_minor'),
            'shares' => $parts === null ? null : [
                'parts' => $parts,
                'paid' => $shares->count(),
                'paid_minor' => (int) $shares->sum('subtotal_minor'),
                'next_minor' => $this->shareAmount($due, $parts, $shares->count() + 1),
            ],
        ];
    }

    /**
     * Pays a bill into the cashier's till: everything left (and closes the
     * order), the lines $lineIds of it, or the next of $parts equal shares
     * of what is left. Everything billed has gone to the kitchen first.
     * $takenOffline: see Bills::pay(). $discount: see Bills::discountOf().
     *
     * @param  list<string>|null  $lineIds
     * @param  array{percent?: int, amount_minor?: int, reason: string}|null  $discount
     *
     * @throws ValidationException
     */
    public function pay(Order $order, User $by, PaymentMethod $method, ?int $tenderedMinor = null, ?string $paymentReference = null, bool $takenOffline = false, ?array $lineIds = null, ?int $parts = null, ?array $discount = null): Bill
    {
        return DB::transaction(function () use ($order, $by, $method, $tenderedMinor, $paymentReference, $takenOffline, $lineIds, $parts, $discount) {
            $locked = $this->lockOpen($order, 'payment_method');

            if ($locked->lines()->whereNull('kitchen_ticket_id')->whereNull('voided_at')->exists()) {
                throw ValidationException::withMessages(['payment_method' => 'Send the new lines to the kitchen, or take them off, before the bill.']);
            }
            $open = $this->unbilled($locked);
            if ($open->isEmpty()) {
                throw ValidationException::withMessages(['payment_method' => 'Nothing to bill: cancel the order instead.']);
            }

            $shares = $this->keptBills($locked)->filter(fn (Bill $bill) => $bill->isShare());
            $running = $shares->first()?->parts;
            $due = max($this->bills->price($this->bills->quantities($open))['total_minor'] - (int) $shares->sum('subtotal_minor'), 0);
            if ($lineIds !== null) {
                if ($running !== null) {
                    throw ValidationException::withMessages(['line_ids' => "This bill is being paid in {$running} equal shares: take the next share."]);
                }
                $chosen = $open->whereIn('id', array_map('strtolower', $lineIds))->values();
                if ($chosen->isEmpty() || $chosen->count() !== count(array_unique($lineIds))) {
                    throw ValidationException::withMessages(['line_ids' => 'Choose what this part pays for, from what is still to pay.']);
                }
                $bill = $this->bills->pay($locked, $chosen, $by, $method, $tenderedMinor, $paymentReference, $takenOffline, discount: $discount);
                $closes = $chosen->count() === $open->count();
            } elseif ($parts !== null || $running !== null) {
                // No parts while shares are being paid: the rest of them at once.
                $rest = $parts === null;
                $parts ??= $running;
                if ($parts < 2 || $parts > Bills::MAX_PARTS) {
                    throw ValidationException::withMessages(['parts' => 'Split in 2 to '.Bills::MAX_PARTS.' shares.']);
                }
                if ($running !== null && $running !== $parts) {
                    throw ValidationException::withMessages(['parts' => "This bill is being split in {$running}: take the next of those shares."]);
                }
                $part = $shares->count() + 1;
                $closes = $rest || $part >= $parts;
                $label = $part >= $parts || ! $closes ? "Share {$part} of {$parts}" : "Shares {$part} to {$parts} of {$parts}";
                $share = ['part' => $part, 'parts' => $parts, 'amount_minor' => $closes ? $due : $this->shareAmount($due, $parts, $part), 'label' => $label];
                $bill = $this->bills->pay($locked, $closes ? $open : new Collection, $by, $method, $tenderedMinor, $paymentReference, $takenOffline, $share, $discount);
            } else {
                $bill = $this->bills->pay($locked, $open, $by, $method, $tenderedMinor, $paymentReference, $takenOffline, discount: $discount);
                $closes = true;
            }

            if ($closes) {
                $locked->forceFill(['status' => Order::PAID, 'closed_by' => $by->id, 'closed_at' => now()])->save();
            }

            return $bill;
        });
    }

    /**
     * Cancels an order: lines not yet sent are removed, those the kitchen
     * has are voided with the reason. Whether the member may void them is
     * the caller's to check (hasLiveTickets()).
     *
     * @throws ValidationException
     */
    public function cancel(Order $order, User $by, string $reason): Order
    {
        return DB::transaction(function () use ($order, $by, $reason) {
            $locked = $this->lockOpen($order, 'reason');
            if (($paid = $this->keptBills($locked)->first()) !== null) {
                throw ValidationException::withMessages(['reason' => "Part of this order is paid on bill {$paid->number}: take the rest, or void that bill first."]);
            }

            $lines = fn () => OrderLine::query()->where('order_id', $locked->id);
            $lines()->whereNull('kitchen_ticket_id')->delete();
            $lines()->whereNull('voided_at')->update(['voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => trim($reason)]);
            $locked->forceFill([
                'status' => Order::CANCELLED,
                'cancel_reason' => trim($reason),
                'closed_by' => $by->id,
                'closed_at' => now(),
            ])->save();

            return $locked;
        });
    }

    /**
     * Whether the kitchen has lines of this order that are not voided:
     * cancelling it then voids them.
     */
    public function hasLiveTickets(Order $order): bool
    {
        return $order->lines()->whereNotNull('kitchen_ticket_id')->whereNull('voided_at')->exists();
    }

    /**
     * The kitchen has made the ticket.
     *
     * @throws ValidationException
     */
    public function ready(KitchenTicket $ticket): KitchenTicket
    {
        return $this->advance($ticket, KitchenTicket::PREPARING, KitchenTicket::READY, 'ready_at');
    }

    /**
     * The ticket's dishes are at the table.
     *
     * @throws ValidationException
     */
    public function served(KitchenTicket $ticket): KitchenTicket
    {
        return $this->advance($ticket, KitchenTicket::READY, KitchenTicket::SERVED, 'served_at');
    }

    /**
     * Takes a ticket with nothing left to make (every line voided) off the
     * kitchen screen.
     *
     * @throws ValidationException
     */
    public function clear(KitchenTicket $ticket): KitchenTicket
    {
        return DB::transaction(function () use ($ticket) {
            $locked = KitchenTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [KitchenTicket::PREPARING, KitchenTicket::READY], true)) {
                throw ValidationException::withMessages(['ticket' => "Ticket {$locked->number} is already {$locked->status}."]);
            }
            if ($locked->lines()->whereNull('voided_at')->exists()) {
                throw ValidationException::withMessages(['ticket' => "Ticket {$locked->number} still has something to make."]);
            }

            $locked->forceFill(['status' => KitchenTicket::CLEARED])->save();

            return $locked;
        });
    }

    private function advance(KitchenTicket $ticket, string $from, string $to, string $stamp): KitchenTicket
    {
        return DB::transaction(function () use ($ticket, $from, $to, $stamp) {
            $locked = KitchenTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== $from) {
                throw ValidationException::withMessages(['ticket' => "Ticket {$locked->number} is already {$locked->status}."]);
            }

            $locked->forceFill(['status' => $to, $stamp => now()])->save();

            return $locked;
        });
    }

    private function openAt(DiningTable $table): ?Order
    {
        return Order::query()->where('dining_table_id', $table->id)->where('status', Order::OPEN)->first();
    }

    /**
     * @throws ValidationException when the order is no longer open
     */
    private function lockOpen(Order $order, string $field): Order
    {
        $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isOpen()) {
            throw ValidationException::withMessages([$field => "Order {$locked->number} is {$locked->status}: it can no longer change."]);
        }

        return $locked;
    }

    private function lineOf(Order $order, OrderLine $line): OrderLine
    {
        return $order->lines()->whereKey($line->id)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['line' => 'This line is not on the order.']);
    }

    /**
     * Lines still to pay: not voided, on no bill.
     *
     * @return Collection<int, OrderLine>
     */
    private function unbilled(Order $order): Collection
    {
        return $order->lines()->whereNull('voided_at')->whereNull('bill_id')->get();
    }

    /**
     * The order's bills not voided.
     *
     * @return Collection<int, Bill>
     */
    private function keptBills(Order $order): Collection
    {
        return $order->bills()->where('status', Bill::PAID)->get();
    }

    /**
     * Share $part of $parts of what is $due now (the shares before it paid):
     * an equal part of it, the last taking what is left to the minor unit.
     */
    private function shareAmount(int $due, int $parts, int $part): int
    {
        return $part >= $parts ? $due : intdiv($due, $parts - $part + 1);
    }
}
