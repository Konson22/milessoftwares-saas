<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\CreditEntry;
use App\Models\Customer;
use App\Models\FuelSale;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Petrol\Credit;
use App\Petrol\Currencies;
use App\Petrol\Permissions;
use App\Support\Money;
use App\Support\Volume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        return Inertia::render('customers/index', [
            'customers' => Customer::query()->orderBy('name')->get()->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'kind' => $customer->kind,
                'phone' => $customer->phone,
                'creditLimitMinor' => $customer->credit_limit_minor,
                'balanceMinor' => (int) $customer->entries()->sum('amount_minor'),
                'active' => $customer->active,
            ]),
            'canManage' => $this->canHere(Permissions::CUSTOMERS_MANAGE),
        ]);
    }

    public function show(Customer $customer): Response
    {
        $branchId = $this->branchId();

        return Inertia::render('customers/show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'kind' => $customer->kind,
                'phone' => $customer->phone,
                'creditLimitMinor' => $customer->credit_limit_minor,
                'balanceMinor' => (int) $customer->entries()->sum('amount_minor'),
                'active' => $customer->active,
            ],
            'entries' => $customer->entries()->latest('occurred_at')->limit(40)->get()->map(fn (CreditEntry $entry) => [
                'id' => $entry->id,
                'kind' => $entry->kind,
                'amountMinor' => $entry->amount_minor,
                'at' => $entry->occurred_at->toIso8601String(),
            ]),
            'sales' => FuelSale::query()->where('customer_id', $customer->id)->where('branch_id', $branchId)->latest('sold_at')->limit(20)->get()->map(fn (FuelSale $sale) => [
                'id' => $sale->id,
                'number' => $sale->number,
                'litres' => Volume::toLitres($sale->quantity_milli),
                'totalMinor' => $sale->total_minor,
                'status' => $sale->status,
            ]),
            'methods' => PaymentMethod::query()->where('branch_id', $branchId)->where('active', true)->where('kind', '!=', PaymentMethod::CREDIT)->orderBy('name')->get(['id', 'name', 'kind']),
            'openShift' => Shift::query()->where('branch_id', $branchId)->where('status', Shift::OPEN)->exists(),
            'canCollect' => $this->canHere(Permissions::CUSTOMERS_MANAGE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in(Customer::KINDS)],
            'phone' => ['nullable', 'string', 'max:32'],
            'credit_limit' => ['required', Money::rule()],
        ]);

        Customer::query()->create([
            'name' => trim($validated['name']),
            'kind' => $validated['kind'],
            'phone' => $this->text($validated['phone'] ?? null),
            'credit_limit_minor' => Money::toMinor($validated['credit_limit']),
            'active' => true,
        ]);

        return back()->with('success', 'Customer added.');
    }

    public function pay(Request $request, Customer $customer, Credit $credit): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', Money::anyRule()],
            'currency' => ['nullable', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
            'method_id' => ['required', 'uuid'],
            'reference' => ['nullable', 'string', 'max:128'],
        ]);
        $method = PaymentMethod::query()->whereKey($validated['method_id'])->where('branch_id', $this->branchId())->where('active', true)->first();
        abort_if($method === null || $method->financeMethod() === null, 422);

        $shift = Shift::query()->where('branch_id', $this->branchId())->where('status', Shift::OPEN)->first();
        $credit->receive(
            $customer,
            $this->branchId(),
            $shift,
            Money::input($validated['amount'], $validated['currency'] ?? null),
            (string) $method->financeMethod(),
            $this->member($request),
            null,
            $this->text($validated['reference'] ?? null),
            $validated['currency'] ?? null,
        );

        return back()->with('success', 'Payment recorded.');
    }
}
