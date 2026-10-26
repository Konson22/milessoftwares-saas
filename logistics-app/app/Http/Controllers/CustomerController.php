<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customers shipments are carried for and invoiced to.
 */
class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();

        $customers = Customer::query()
            ->search($search)
            ->withCount(['shipments as open_shipments_count' => fn ($query) => $query->whereIn('status', [Shipment::BOOKED, Shipment::ASSIGNED, Shipment::IN_TRANSIT])])
            ->withSum(['invoices as invoiced_minor' => fn ($query) => $query->where('status', Invoice::ISSUED)], 'total_minor')
            ->withSum(['invoices as paid_minor' => fn ($query) => $query->where('status', Invoice::ISSUED)], 'paid_minor')
            ->orderByDesc('active')
            ->orderBy('name')
            ->limit(300)
            ->get();

        return Inertia::render('customers/index', [
            'customers' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'active' => $customer->active,
                'openShipments' => (int) $customer->getAttribute('open_shipments_count'),
                'balanceMinor' => (int) $customer->getAttribute('invoiced_minor') - (int) $customer->getAttribute('paid_minor'),
            ]),
            'filters' => ['search' => $search],
            'canManage' => $this->can($request, 'logistics.shipments.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('customers/form', ['customer' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $customer = new Customer;
        $this->save($request, $customer);

        return to_route('customers.index')->with('success', "{$customer->name} added.");
    }

    public function edit(Customer $customer): Response
    {
        return Inertia::render('customers/form', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'address' => $customer->address,
                'active' => $customer->active,
            ],
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->save($request, $customer);

        return to_route('customers.index')->with('success', "{$customer->name} saved.");
    }

    private function save(Request $request, Customer $customer): void
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'active' => ['boolean'],
        ]);

        $customer->fill([
            'name' => trim($validated['name']),
            'phone' => $this->text($validated['phone'] ?? null),
            'email' => $this->text($validated['email'] ?? null),
            'address' => $this->text($validated['address'] ?? null),
            'active' => (bool) ($validated['active'] ?? true),
        ])->save();
    }
}
