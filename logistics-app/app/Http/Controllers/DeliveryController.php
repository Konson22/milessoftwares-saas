<?php

namespace App\Http\Controllers;

use App\Actions\Shipments;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Work on the road: tracking updates, proof of delivery and failed
 * delivery attempts.
 */
class DeliveryController extends Controller
{
    public function track(Request $request, Shipment $shipment, Shipments $shipments): RedirectResponse
    {
        $validated = $request->validate([
            'location' => ['required_without:note', 'nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $shipments->track($shipment, $this->member($request), $validated['location'] ?? null, $validated['note'] ?? null);

        return back()->with('success', "Tracking of {$shipment->number} updated.");
    }

    public function deliver(Request $request, Shipment $shipment, Shipments $shipments): RedirectResponse
    {
        $validated = $request->validate([
            'received_by' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $shipments->deliver($shipment, $this->member($request), $validated['received_by'], $validated['note'] ?? null);

        return back()->with('success', "{$shipment->number} delivered.");
    }

    public function fail(Request $request, Shipment $shipment, Shipments $shipments): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $shipments->fail($shipment, $this->member($request), $validated['reason']);

        return back()->with('success', "{$shipment->number} marked as not delivered.");
    }
}
