<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Models\Supplier;
use App\Petrol\Currencies;
use App\Petrol\Permissions;
use App\Petrol\Purchasing;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    use WorksInBranch;

    public function index(Purchasing $purchasing): Response
    {
        $branchId = $this->branchId();

        return Inertia::render('suppliers/index', [
            'suppliers' => Supplier::query()->orderBy('name')->get()->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'phone' => $supplier->phone,
                'active' => $supplier->active,
                'outstandingMinor' => $purchasing->outstanding($supplier->id, $branchId),
            ]),
            'methods' => PaymentMethod::query()->where('branch_id', $branchId)->where('active', true)->where('kind', '!=', PaymentMethod::CREDIT)->orderBy('name')->get(['id', 'name', 'kind']),
            'canManage' => $this->canHere(Permissions::PURCHASES_MANAGE),
            'canPay' => $this->canHere(Permissions::PAYABLES_PAY),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        Supplier::query()->create([
            'name' => trim($validated['name']),
            'phone' => $this->text($validated['phone'] ?? null),
            'active' => true,
        ]);

        return back()->with('success', 'Supplier added.');
    }

    public function pay(Request $request, Supplier $supplier, Purchasing $purchasing): RedirectResponse
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
        $purchasing->pay(
            $this->branchId(),
            $supplier,
            $shift,
            Money::input($validated['amount'], $validated['currency'] ?? null),
            $method->kind === PaymentMethod::CASH ? Expense::CASH : Expense::BANK,
            null,
            $this->text($validated['reference'] ?? null),
            $this->member($request),
            null,
            $validated['currency'] ?? null,
        );

        return back()->with('success', 'Supplier payment recorded. Finance still shows the bill until it is paid there.');
    }
}
