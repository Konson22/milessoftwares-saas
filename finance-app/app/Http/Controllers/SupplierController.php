<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Suppliers and what is owed to each.
 */
class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));

        $suppliers = Supplier::query()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->withSum('bills', 'total_minor')
            ->withSum('bills', 'paid_minor')
            ->withCount('bills')
            ->orderBy('name')
            ->get();

        return Inertia::render('suppliers/index', [
            'search' => $search,
            'suppliers' => $suppliers->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'email' => $supplier->email,
                'phone' => $supplier->phone,
                'source' => $supplier->source_app,
                'bills' => (int) $supplier->getAttribute('bills_count'),
                'outstandingMinor' => (int) $supplier->getAttribute('bills_sum_total_minor') - (int) $supplier->getAttribute('bills_sum_paid_minor'),
            ]),
        ]);
    }

    public function show(Supplier $supplier): Response
    {
        $bills = $supplier->bills()->orderByDesc('bill_date')->orderByDesc('number')->get();
        $payments = $supplier->payments()->with('account')->orderByDesc('paid_on')->orderByDesc('number')->get();

        return Inertia::render('suppliers/show', [
            'supplier' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'email' => $supplier->email,
                'phone' => $supplier->phone,
                'source' => $supplier->source_app,
                'outstandingMinor' => (int) $bills->sum(fn (Bill $bill) => $bill->outstanding()),
            ],
            'bills' => $bills->map(fn (Bill $bill) => BillController::row($bill)),
            'payments' => $payments->map(fn (SupplierPayment $payment) => SupplierPaymentController::row($payment)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = Supplier::query()->create($this->validated($request));

        return redirect()->route('suppliers.show', $supplier)->with('success', "Supplier {$supplier->name} added.");
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request));

        return back()->with('success', "Supplier {$supplier->name} saved.");
    }

    /**
     * @return array{name: string, email: string|null, phone: string|null}
     */
    private function validated(Request $request): array
    {
        /** @var array{name: string, email?: string|null, phone?: string|null} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        return ['name' => $data['name'], 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null];
    }
}
