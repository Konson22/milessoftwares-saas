<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\Sale;
use App\Support\CurrentBranch;
use App\Support\DocumentNumbers;
use App\Support\Industry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Registered customers of a counter that does not sell on account (the
 * pharmacy): who they are and what they bought, in every branch. Naming a
 * customer is optional at the counter; registering one keeps their history.
 */
class CounterCustomerController extends Controller
{
    public function __construct(private readonly Industry $industry) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $showInactive = $request->boolean('inactive');

        $customers = Customer::query()
            ->where('application', $this->industry->code())
            ->search($search)
            ->when(! $showInactive, fn ($query) => $query->where('active', true))
            ->withCount(['sales as purchases' => fn ($query) => $query->where('status', Sale::COMPLETED)])
            ->withMax('sales as last_bought_at', 'sold_at')
            ->orderBy('name')
            ->limit(500)
            ->get();

        return Inertia::render($this->industry->page('customers/index'), [
            'customers' => $customers->map(fn (Customer $customer) => [
                ...$this->summary($customer),
                'purchases' => (int) $customer->getAttribute('purchases'),
                'lastBoughtAt' => $customer->getAttribute('last_bought_at'),
            ]),
            'filters' => ['search' => $search, 'inactive' => $showInactive],
            'canManage' => $request->user()?->can($this->industry->permission('customers.manage')) ?? false,
        ]);
    }

    /**
     * Customers matching a name, number or phone, for the counter's picker.
     */
    public function lookup(Request $request): JsonResponse
    {
        $search = trim($request->string('search')->toString());

        return response()->json(['customers' => $search === '' ? [] : Customer::query()
            ->where('application', $this->industry->code())
            ->where('active', true)
            ->search($search)
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Customer $customer) => $this->summary($customer))
            ->values()]);
    }

    public function create(): Response
    {
        return Inertia::render($this->industry->page('customers/form'), ['customer' => null]);
    }

    public function store(Request $request, DocumentNumbers $numbers): RedirectResponse
    {
        $customer = new Customer([
            'application' => $this->industry->code(),
            'number' => $numbers->next('customer', 'C'),
            // Nothing on account at a counter that does not sell on account.
            'credit_limit_minor' => 0,
        ]);
        $customer->fill($this->validated($request, null))->save();

        return to_route($this->industry->route('customers.show'), $customer)->with('success', "{$customer->name} registered as {$customer->number}.");
    }

    public function edit(Customer $customer): Response
    {
        $this->ensureOurs($customer);

        return Inertia::render($this->industry->page('customers/form'), [
            'customer' => [...$this->summary($customer), 'email' => $customer->email, 'address' => $customer->address],
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->ensureOurs($customer);
        $customer->fill($this->validated($request, $customer))->save();

        return to_route($this->industry->route('customers.show'), $customer)->with('success', "{$customer->name} saved.");
    }

    /**
     * The customer and everything they bought, newest first.
     */
    public function show(Request $request, Customer $customer, CurrentBranch $branch): Response
    {
        $this->ensureOurs($customer);
        $sales = Sale::query()
            ->where('customer_id', $customer->id)
            ->with(['lines.item:id,name', 'seller:id,name'])
            ->withSum('returns as returned_minor', 'total_minor')
            ->latest('sold_at')
            ->limit(200)
            ->get();
        $completed = $sales->where('status', Sale::COMPLETED);

        return Inertia::render($this->industry->page('customers/show'), [
            'customer' => [...$this->summary($customer), 'email' => $customer->email, 'address' => $customer->address],
            'totals' => [
                'purchases' => $completed->count(),
                'spentMinor' => (int) $completed->sum(fn (Sale $sale) => $sale->total_minor - (int) $sale->getAttribute('returned_minor')),
                'lastBoughtAt' => $completed->first()?->sold_at->toIso8601String(),
            ],
            'sales' => $sales->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'number' => $sale->number,
                'status' => $sale->status,
                'soldAt' => $sale->sold_at->toIso8601String(),
                'soldBy' => $sale->seller->name,
                'totalMinor' => $sale->total_minor,
                'returnedMinor' => (int) $sale->getAttribute('returned_minor'),
                'items' => $sale->lines->pluck('item.name')->unique()->values(),
                // Only this branch's sales open from here.
                'here' => $sale->branch_id === $branch->get()?->id,
            ]),
            'canManage' => $request->user()?->can($this->industry->permission('customers.manage')) ?? false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Customer $customer): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('customers', 'name')
                ->where('organization_id', app(CurrentTenant::class)->id())
                ->where('application', $this->industry->code())
                ->ignore($customer?->id)],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'active' => ['boolean'],
        ]);

        return [
            'name' => trim($validated['name']),
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'active' => (bool) ($validated['active'] ?? true),
        ];
    }

    /**
     * @return array{id: string, number: string, name: string, phone: string|null, active: bool}
     */
    private function summary(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'number' => $customer->number,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'active' => $customer->active,
        ];
    }

    /**
     * Another industry's customers look unknown here.
     */
    private function ensureOurs(Customer $customer): void
    {
        abort_unless($customer->application === $this->industry->code(), 404);
    }
}
