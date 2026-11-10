<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\Customer;
use App\Models\ServiceBay;
use App\Models\ServiceOffering;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Petrol\ServiceDesk;
use App\Support\Money;
use App\Support\Volume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        abort_unless($this->station()->runs('service'), 404);
        $branch = $this->branchId();

        return Inertia::render('service/index', [
            'bays' => ServiceBay::query()->where('branch_id', $branch)->orderBy('code')->get(),
            'offerings' => ServiceOffering::query()->where('active', true)->orderBy('name')->get(),
            'orders' => WorkOrder::query()->with('lines')->where('branch_id', $branch)->latest('opened_at')->limit(40)->get(),
            'customers' => Customer::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'vehicles' => Vehicle::query()->orderBy('registration')->get(['id', 'customer_id', 'registration']),
            'stores' => Store::query()->where('branch_id', $branch)->where('active', true)->get(['id', 'name']),
            'products' => StoreProduct::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function bay(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
            'supervisor' => ['nullable', 'string', 'max:255'],
        ]);
        ServiceBay::query()->updateOrCreate(
            ['branch_id' => $this->branchId(), 'code' => strtoupper(trim($validated['code']))],
            ['name' => trim($validated['name']), 'supervisor' => $this->text($validated['supervisor'] ?? null), 'status' => ServiceBay::OPEN],
        );

        return back()->with('success', 'Bay saved.');
    }

    public function offering(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
            'price' => ['required', Money::rule()],
        ]);
        ServiceOffering::query()->updateOrCreate(
            ['code' => strtoupper(trim($validated['code']))],
            ['name' => trim($validated['name']), 'price_minor' => Money::toMinor($validated['price']), 'active' => true],
        );

        return back()->with('success', 'Service saved.');
    }

    public function vehicle(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'uuid'],
            'registration' => ['required', 'string', 'max:32'],
            'details' => ['nullable', 'string', 'max:255'],
        ]);
        abort_unless(Customer::query()->whereKey($validated['customer_id'])->exists(), 404);
        Vehicle::query()->updateOrCreate(
            ['registration' => strtoupper(trim($validated['registration']))],
            ['customer_id' => $validated['customer_id'], 'details' => $this->text($validated['details'] ?? null)],
        );

        return back()->with('success', 'Vehicle saved.');
    }

    public function open(Request $request, ServiceDesk $desk): RedirectResponse
    {
        $validated = $request->validate([
            'service_bay_id' => ['required', 'uuid'],
            'customer_id' => ['required', 'uuid'],
            'vehicle_id' => ['required', 'uuid'],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'technician' => ['nullable', 'string', 'max:255'],
        ]);
        $desk->open(
            $this->branchId(),
            ServiceBay::query()->whereKey($validated['service_bay_id'])->firstOrFail(),
            Customer::query()->whereKey($validated['customer_id'])->firstOrFail(),
            Vehicle::query()->whereKey($validated['vehicle_id'])->firstOrFail(),
            $validated['odometer'] ?? null,
            $this->text($validated['technician'] ?? null),
            $this->member($request),
        );

        return back()->with('success', 'Work order opened.');
    }

    public function part(Request $request, WorkOrder $order, ServiceDesk $desk): RedirectResponse
    {
        $this->ensureHere($order->branch_id);
        $validated = $request->validate([
            'store_id' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'quantity' => ['required', Volume::rule()],
            'amount' => ['required', Money::rule()],
        ]);
        $desk->addPart(
            $order,
            Store::query()->whereKey($validated['store_id'])->firstOrFail(),
            StoreProduct::query()->whereKey($validated['product_id'])->firstOrFail(),
            Volume::toMilli($validated['quantity']),
            Money::toMinor($validated['amount']),
            $this->member($request),
        );

        return back()->with('success', 'Part added to the estimate. Stock has not moved.');
    }

    public function issue(WorkOrderLine $line, ServiceDesk $desk, Request $request): RedirectResponse
    {
        $order = WorkOrder::query()->whereKey($line->work_order_id)->firstOrFail();
        $this->ensureHere($order->branch_id);
        $desk->issue($line, $this->member($request));

        return back()->with('success', 'Part issued from the store.');
    }

    public function advance(Request $request, WorkOrder $order, ServiceDesk $desk): RedirectResponse
    {
        $this->ensureHere($order->branch_id);
        $validated = $request->validate([
            'status' => ['required', Rule::in([WorkOrder::IN_PROGRESS, WorkOrder::COMPLETED, WorkOrder::INVOICED, WorkOrder::CLOSED, WorkOrder::CANCELLED])],
            'labor' => ['nullable', Money::rule()],
        ]);
        $member = $this->member($request);
        if ($validated['status'] === WorkOrder::CANCELLED) {
            $desk->cancel($order, $member);
        } else {
            $desk->advance($order, $validated['status'], Money::toMinor($validated['labor'] ?? '0'), $member);
        }

        return back()->with('success', 'Work order updated.');
    }

    public function pay(Request $request, WorkOrder $order, ServiceDesk $desk): RedirectResponse
    {
        $this->ensureHere($order->branch_id);
        $validated = $request->validate([
            'amount' => ['required', Money::rule()],
            'kind' => ['required', Rule::in(['cash', 'mobile_money', 'bank_transfer'])],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        $desk->pay($order, Money::toMinor($validated['amount']), $validated['kind'], $this->member($request), $validated['idempotency_key'] ?? null);

        return back()->with('success', 'Service payment recorded.');
    }
}
