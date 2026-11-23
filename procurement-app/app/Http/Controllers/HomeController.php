<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Procurement\Access;
use App\Procurement\Approvals;
use App\Procurement\Figures;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\Requisition;
use App\Procurement\Money;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The overview: what waits on this member, and (for those who see all
 * procurement) the organization's pipeline and committed, invoiced and
 * paid amounts kept apart.
 */
class HomeController extends Controller
{
    public function __invoke(): Response
    {
        $settings = Workspace::settings();
        $seesAll = Access::seesAll();

        return Inertia::render('home', [
            'currency' => $settings->currency,
            'awaitingMe' => Approvals::awaiting($this->user()),
            'myRequisitions' => Requisition::query()->where('requester_id', $this->user()->id)
                ->whereNotIn('status', ['closed', 'cancelled'])->latest()->limit(8)->get()
                ->map(fn (Requisition $r) => [
                    'id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'status' => $r->status,
                    'total' => self::major($r->total_minor, $r->currency), 'currency' => $r->currency,
                ]),
            'figures' => $seesAll ? Figures::overview() : null,
            'lateOrders' => $seesAll ? PurchaseOrder::query()->with('supplier:id,name')
                ->whereIn('status', ['issued', 'partially_received'])->whereNotNull('delivery_date')
                ->where('delivery_date', '<', now()->toDateString())->orderBy('delivery_date')->limit(8)->get()
                ->map(fn (PurchaseOrder $o) => [
                    'id' => $o->id, 'number' => $o->number, 'supplier' => $o->supplier->name,
                    'deliveryDate' => self::date($o->getAttribute('delivery_date')), 'status' => $o->status,
                ]) : [],
            'canRequest' => Gate::allows('procurement.requisitions.create'),
            'decimals' => Money::decimals($settings->currency),
        ]);
    }
}
