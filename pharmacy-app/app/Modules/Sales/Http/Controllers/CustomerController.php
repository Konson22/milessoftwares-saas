<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Actions\CustomerAccounts;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerPayment;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\Sale;
use App\Support\CurrentBranch;
use App\Support\DocumentNumbers;
use App\Support\Industry;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Account customers of the industry being served: who they are, what they
 * may owe, their statement, and the payments they bring. Customers are the
 * organization's (one account across branches); payments are taken in the
 * branch being worked in, into its till.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly Industry $industry,
        private readonly CustomerAccounts $accounts,
    ) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $showInactive = $request->boolean('inactive');

        $customers = Customer::query()
            ->where('application', $this->industry->code())
            ->search($search)
            ->when(! $showInactive, fn ($query) => $query->where('active', true))
            ->orderBy('name')
            ->limit(500)
            ->get();
        $owed = $this->accounts->balances($customers);
        $overdue = $this->overdueCustomers($customers);

        return Inertia::render($this->industry->page('customers/index'), [
            'customers' => $customers->map(fn (Customer $customer) => [
                ...$this->summary($customer),
                'owedMinor' => $owed[$customer->id] ?? 0,
                'overdue' => in_array($customer->id, $overdue, true),
            ]),
            'filters' => ['search' => $search, 'inactive' => $showInactive],
            'canManage' => $this->can('customers.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render($this->industry->page('customers/form'), ['customer' => null, 'priceLists' => $this->priceListOptions()]);
    }

    public function store(Request $request, DocumentNumbers $numbers): RedirectResponse
    {
        $customer = new Customer([
            'application' => $this->industry->code(),
            'number' => $numbers->next('customer', 'C'),
        ]);
        $customer->fill($this->validated($request, null))->save();

        return to_route($this->industry->route('customers.show'), $customer)->with('success', "{$customer->name} added as {$customer->number}.");
    }

    public function edit(Customer $customer): Response
    {
        $this->ensureOurs($customer);

        return Inertia::render($this->industry->page('customers/form'), [
            'customer' => [
                ...$this->summary($customer),
                'email' => $customer->email,
                'address' => $customer->address,
                'creditLimit' => Money::toMajor($customer->credit_limit_minor),
                'priceListId' => $customer->price_list_id,
            ],
            'priceLists' => $this->priceListOptions(),
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->ensureOurs($customer);
        $customer->fill($this->validated($request, $customer))->save();

        return to_route($this->industry->route('customers.show'), $customer)->with('success', "{$customer->name} saved.");
    }

    /**
     * The statement: every sale on account with what was returned, paid and
     * is still owed, the payments, and what is owed and overdue.
     */
    public function show(Request $request, Customer $customer, CurrentBranch $branch): Response
    {
        $this->ensureOurs($customer);
        $today = CarbonImmutable::today();
        $sales = $this->accounts->accountSales($customer);
        $owed = (int) $sales->sum('owed_minor');
        $overdue = (int) $sales->filter(fn (Sale $sale) => $sale->due_on !== null && $sale->due_on->lessThan($today))->sum('owed_minor');

        return Inertia::render($this->industry->page('customers/show'), [
            'customer' => [
                ...$this->summary($customer),
                'email' => $customer->email,
                'address' => $customer->address,
            ],
            'owedMinor' => $owed,
            'overdueMinor' => $overdue,
            'availableMinor' => max(0, $customer->credit_limit_minor - $owed),
            'sales' => $sales->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'number' => $sale->number,
                'soldOn' => $sale->sold_at->toDateString(),
                'dueOn' => $sale->due_on?->toDateString(),
                'overdue' => $sale->due_on !== null && $sale->due_on->lessThan($today) && (int) $sale->getAttribute('owed_minor') > 0,
                'totalMinor' => $sale->total_minor,
                'returnedMinor' => (int) $sale->getAttribute('returned_minor'),
                'paidMinor' => (int) $sale->getAttribute('paid_minor'),
                'owedMinor' => (int) $sale->getAttribute('owed_minor'),
                // Only this branch's sales open from here.
                'here' => $sale->branch_id === $branch->id(),
            ])->values(),
            'payments' => $customer->payments()->with('receiver:id,name', 'allocations.sale:id,number')->limit(200)->get()->map(fn (CustomerPayment $payment) => [
                'id' => $payment->id,
                'number' => $payment->number,
                'receivedAt' => $payment->received_at->toIso8601String(),
                'method' => $payment->method->label(),
                'reference' => $payment->reference,
                'amountMinor' => $payment->amount_minor,
                'receivedBy' => $payment->receiver->name,
                'settled' => $payment->allocations->map(fn ($allocation) => $allocation->sale->number)->values(),
            ]),
            'paymentMethods' => array_map(fn (PaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()], PaymentMethod::payments()),
            'canManage' => $this->can('customers.manage'),
            'canTakePayment' => $this->canHere('sales.create', $branch) && $owed > 0,
            'today' => $today->toDateString(),
        ]);
    }

    public function storePayment(Request $request, Customer $customer, CurrentBranch $branch): RedirectResponse
    {
        $this->ensureOurs($customer);

        $validated = $request->validate([
            'amount' => ['required', 'string', Money::rule()],
            'method' => ['required', Rule::enum(PaymentMethod::class)->except(PaymentMethod::Account)],
            'reference' => ['nullable', 'string', 'max:128'],
            'note' => ['nullable', 'string', 'max:255'],
            'submission_id' => ['nullable', 'uuid'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $payment = $this->accounts->receive(
            customerId: $customer->id,
            amountMinor: Money::toMinor($validated['amount']),
            method: PaymentMethod::from($validated['method']),
            by: $user,
            branchId: $branch->id(),
            reference: $validated['reference'] ?? null,
            note: $validated['note'] ?? null,
            submissionId: $validated['submission_id'] ?? (string) Str::uuid(),
        );

        return back()->with('success', "Payment {$payment->number} of ".Money::format($payment->amount_minor).' received.');
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
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'credit_limit' => ['required', 'string', Money::rule()],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'price_list_id' => ['nullable', 'uuid', Rule::exists('price_lists', 'id')
                ->where('organization_id', app(CurrentTenant::class)->id())
                ->where('application', $this->industry->code())],
            'active' => ['boolean'],
        ]);

        return [
            'name' => trim($validated['name']),
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address' => $validated['address'] ?? null,
            'credit_limit_minor' => Money::toMinor($validated['credit_limit']),
            'payment_terms_days' => (int) $validated['payment_terms_days'],
            'price_list_id' => $validated['price_list_id'] ?? null,
            'active' => (bool) ($validated['active'] ?? true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'number' => $customer->number,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'creditLimitMinor' => $customer->credit_limit_minor,
            'paymentTermsDays' => $customer->payment_terms_days,
            'active' => $customer->active,
        ];
    }

    /**
     * @return list<array{id: string, name: string, active: bool}>
     */
    private function priceListOptions(): array
    {
        return array_values(PriceList::query()
            ->where('application', $this->industry->code())
            ->orderBy('name')
            ->get()
            ->map(fn (PriceList $list) => ['id' => $list->id, 'name' => $list->name, 'active' => $list->active])
            ->all());
    }

    /**
     * Customers among these with something owed past its due date.
     *
     * @param  EloquentCollection<int, Customer>  $customers
     * @return list<string>
     */
    private function overdueCustomers(EloquentCollection $customers): array
    {
        if ($customers->isEmpty()) {
            return [];
        }

        $late = Sale::query()
            ->whereIn('customer_id', $customers->modelKeys())
            ->where('payment_method', PaymentMethod::Account->value)
            ->where('status', Sale::COMPLETED)
            ->whereDate('due_on', '<', CarbonImmutable::today())
            ->withSum('returns as returned_minor', 'total_minor')
            ->withSum('allocations as paid_minor', 'amount_minor')
            ->get(['id', 'customer_id', 'total_minor']);

        return array_values(array_unique($late
            ->filter(fn (Sale $sale) => $sale->total_minor - (int) $sale->getAttribute('returned_minor') - (int) $sale->getAttribute('paid_minor') > 0)
            ->map(fn (Sale $sale) => (string) $sale->customer_id)
            ->all()));
    }

    /**
     * Another industry's customers look unknown here.
     */
    private function ensureOurs(Customer $customer): void
    {
        abort_unless($customer->application === $this->industry->code(), 404);
    }

    private function can(string $name): bool
    {
        return request()->user()?->can($this->industry->permission($name)) ?? false;
    }

    private function canHere(string $name, CurrentBranch $branch): bool
    {
        return app(CurrentAccess::class)->get()?->allowsIn($this->industry->permission($name), branchId: $branch->id()) ?? false;
    }
}
