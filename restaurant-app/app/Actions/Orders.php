<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\DiningTable;
use App\Models\KitchenTicket;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Support\DocumentNumbers;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
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
     * @throws ValidationException
     */
    public function open(User $by, string $branchId, string $service, ?string $tableId = null, ?int $guests = null, ?string $customerName = null): Order
    {
        if ($service === Order::DINE_IN && $tableId === null) {
            throw ValidationException::withMessages(['dining_table_id' => 'Choose the table.']);
        }

        try {
            return DB::transaction(function () use ($by, $branchId, $service, $tableId, $guests, $customerName) {
                $table = null;
                if ($service === Order::DINE_IN) {
                    $table = DiningTable::query()->whereKey($tableId)->where('branch_id', $branchId)->where('active', true)->lockForUpdate()->first()
                        ?? throw ValidationException::withMessages(['dining_table_id' => 'This table is not in use at this branch.']);

                    if (($open = $this->openAt($table)) !== null) {
                        return $open;
                    }
                }

                return Order::query()->create([
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
     * @param  list<array{item_id: string, quantity: int, note?: string|null}>  $lines
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

        return DB::transaction(function () use ($order, $by, $lines) {
            $locked = $this->lockOpen($order, 'lines');

            foreach ($lines as $line) {
                $locked->lines()->create([
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

            $current->forceFill(['voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => trim($reason)])->save();
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
     * The bill as it stands: every line not voided, by item, at today's
     * menu prices (as paying will charge them).
     *
     * @return array{lines: list<array{item_id: string, name: string, quantity: int, unit_price_minor: int, total_minor: int}>, total_minor: int}
     */
    public function bill(Order $order): array
    {
        ['lines' => $lines, 'total_minor' => $total] = $this->bills->price($this->quantities($this->billable($order)));

        return [
            'lines' => array_map(fn (array $line) => [
                'item_id' => $line['item']->id,
                'name' => $line['item']->name,
                'quantity' => $line['quantity'],
                'unit_price_minor' => $line['unit_price_minor'],
                'total_minor' => $line['total_minor'],
            ], $lines),
            'total_minor' => $total,
        ];
    }

    /**
     * Pays the bill of every line not voided, into the cashier's till, and
     * closes the order with it. Everything on the bill has gone to the
     * kitchen first.
     *
     * @throws ValidationException
     */
    public function pay(Order $order, User $by, PaymentMethod $method, ?int $tenderedMinor = null, ?string $paymentReference = null): Order
    {
        return DB::transaction(function () use ($order, $by, $method, $tenderedMinor, $paymentReference) {
            $locked = $this->lockOpen($order, 'payment_method');

            if ($locked->lines()->whereNull('kitchen_ticket_id')->whereNull('voided_at')->exists()) {
                throw ValidationException::withMessages(['payment_method' => 'Send the new lines to the kitchen, or take them off, before the bill.']);
            }
            $billable = $this->billable($locked);
            if ($billable->isEmpty()) {
                throw ValidationException::withMessages(['payment_method' => 'Nothing to bill: cancel the order instead.']);
            }

            $this->bills->pay($locked, $this->quantities($billable), $by, $method, $tenderedMinor, $paymentReference);

            $locked->forceFill([
                'status' => Order::PAID,
                'closed_by' => $by->id,
                'closed_at' => now(),
            ])->save();

            return $locked;
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
     * @return Collection<int, OrderLine>
     */
    private function billable(Order $order): Collection
    {
        return $order->lines()->whereNull('voided_at')->get();
    }

    /**
     * The bill's quantities: one per item, whatever the notes.
     *
     * @param  Collection<int, OrderLine>  $lines
     * @return array<string, int>
     */
    private function quantities(Collection $lines): array
    {
        return $lines->groupBy('menu_item_id')
            ->map(fn (Collection $same) => (int) $same->sum('quantity'))
            ->all();
    }
}
