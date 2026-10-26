<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\Invoice;
use App\Models\Shipment;
use App\Models\Trip;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Today at a glance: work waiting, on the road and delivered, the fleet,
 * and what customers owe. Each figure only for members who may see it.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $shipments = $this->can($request, 'logistics.shipments.view');
        $trips = $this->can($request, 'logistics.trips.view');
        $fleet = $this->can($request, 'logistics.fleet.view');
        $billing = $this->can($request, 'logistics.billing.view');

        $openInvoices = Invoice::query()->where('status', Invoice::ISSUED);

        return Inertia::render('home', [
            'shipments' => ! $shipments ? null : [
                'waiting' => Shipment::query()->where('status', Shipment::BOOKED)->count(),
                'assigned' => Shipment::query()->where('status', Shipment::ASSIGNED)->count(),
                'inTransit' => Shipment::query()->where('status', Shipment::IN_TRANSIT)->count(),
                'deliveredToday' => Shipment::query()->where('status', Shipment::DELIVERED)->where('delivered_at', '>=', today())->count(),
            ],
            'trips' => ! $trips ? null : [
                'planned' => Trip::query()->where('status', Trip::PLANNED)->count(),
                'onTheRoad' => Trip::query()->where('status', Trip::IN_PROGRESS)->count(),
            ],
            'fleet' => ! $fleet ? null : [
                'available' => Vehicle::query()->where('status', Vehicle::AVAILABLE)->count(),
                'inMaintenance' => Vehicle::query()->where('status', Vehicle::IN_MAINTENANCE)->count(),
                'licencesDue' => Driver::query()->where('active', true)->whereNotNull('licence_expires_on')->where('licence_expires_on', '<=', today()->addDays(30))->count(),
            ],
            'billing' => ! $billing ? null : [
                'toInvoice' => Shipment::query()->where('status', Shipment::DELIVERED)->whereNull('invoice_id')->count(),
                'outstandingMinor' => (int) (clone $openInvoices)->sum('total_minor') - (int) (clone $openInvoices)->sum('paid_minor'),
                'overdue' => (clone $openInvoices)->whereNotNull('due_on')->where('due_on', '<', today())->count(),
            ],
        ]);
    }
}
