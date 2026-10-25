<?php

namespace App\Http\Controllers;

use App\Actions\Orders;
use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\KitchenTicket;
use App\Models\OrderLine;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The kitchen screen of the branch being worked in: tickets oldest first,
 * marked ready by the kitchen and served by the waiter.
 */
class KitchenController extends Controller
{
    use WorksInBranch;

    public function __construct(private readonly Orders $orders) {}

    public function index(): Response
    {
        $tickets = KitchenTicket::query()
            ->where('branch_id', $this->branchId())
            ->whereIn('status', [KitchenTicket::PREPARING, KitchenTicket::READY])
            ->with(['order:id,number,service,dining_table_id,customer_name', 'order.table:id,name', 'lines.item:id,name', 'sender:id,name'])
            ->orderBy('sent_at')
            ->get();

        return Inertia::render('kitchen', [
            'tickets' => $tickets->map(fn (KitchenTicket $ticket) => [
                'id' => $ticket->id,
                'number' => $ticket->number,
                'status' => $ticket->status,
                'sentAt' => $ticket->sent_at->toIso8601String(),
                'readyAt' => $ticket->ready_at?->toIso8601String(),
                'sentBy' => $ticket->sender->name,
                'order' => [
                    'id' => $ticket->order->id,
                    'number' => $ticket->order->number,
                    'service' => $ticket->order->service,
                    'table' => $ticket->order->table?->name,
                    'customerName' => $ticket->order->customer_name,
                ],
                'lines' => $ticket->lines->map(fn (OrderLine $line) => [
                    'id' => $line->id,
                    'name' => $line->item->name,
                    'quantity' => $line->quantity,
                    'note' => $line->note,
                    'voided' => $line->voided(),
                ]),
            ]),
        ]);
    }

    public function ready(KitchenTicket $ticket): RedirectResponse
    {
        $this->ensureHere($ticket->branch_id);
        $this->orders->ready($ticket);

        return back()->with('success', "Ticket {$ticket->number} is ready.");
    }

    public function served(KitchenTicket $ticket): RedirectResponse
    {
        $this->ensureHere($ticket->branch_id);
        $this->orders->served($ticket);

        return back()->with('success', "Ticket {$ticket->number} served.");
    }
}
