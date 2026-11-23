<?php

namespace App\Http\Controllers;

use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\Quotation;
use App\Procurement\Models\Supplier;
use App\Procurement\Suppliers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The supplier register. No delete: suppliers are made inactive or
 * suspended and keep their history.
 */
class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'active';
        $search = trim($request->string('q')->toString());

        return Inertia::render('suppliers/index', [
            'status' => $status,
            'search' => $search,
            'suppliers' => Supplier::query()
                ->when($status !== 'all', fn ($q) => $q->where('status', $status))
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('tax_number', 'like', "%{$search}%")))
                ->orderBy('name')->limit(200)->get()
                ->map(fn (Supplier $s) => [
                    'id' => $s->id, 'name' => $s->name, 'type' => $s->getAttribute('type'), 'status' => $s->status,
                    'contact' => $s->getAttribute('contact_person'), 'email' => $s->getAttribute('email'), 'phone' => $s->getAttribute('phone'),
                    'categories' => $s->getAttribute('categories') ?? [],
                ]),
            'types' => Supplier::TYPES,
            'canManage' => Gate::allows('procurement.suppliers.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = Suppliers::save(null, $this->validated($request), $this->user());

        return to_route('suppliers.show', $supplier)->with('success', 'Supplier added.');
    }

    public function show(Supplier $supplier): Response
    {
        $orders = PurchaseOrder::query()->where('supplier_id', $supplier->id)->latest()->limit(50)->get();

        return Inertia::render('suppliers/show', [
            'supplier' => [
                ...$supplier->only(['id', 'name', 'type', 'status', 'status_reason', 'contact_person', 'email', 'phone', 'address', 'country', 'registration_number', 'tax_number', 'notes']),
                'categories' => $supplier->getAttribute('categories') ?? [],
            ],
            'orders' => $orders->map(fn (PurchaseOrder $o) => [
                'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'currency' => $o->currency,
                'total' => self::major($o->total_minor, $o->currency), 'date' => self::date($o->created_at),
            ]),
            'quotations' => Quotation::query()->where('supplier_id', $supplier->id)->count(),
            'documents' => $this->documents('supplier', $supplier),
            'types' => Supplier::TYPES,
            'canManage' => Gate::allows('procurement.suppliers.manage'),
        ]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        Suppliers::save($supplier, $this->validated($request), $this->user());

        return back()->with('success', 'Supplier saved.');
    }

    public function status(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Supplier::STATUSES)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        Suppliers::setStatus($supplier, $data['status'], $data['reason'] ?? null, $this->user());

        return back()->with('success', 'Supplier is now '.$data['status'].'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Supplier::TYPES)],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:1000'],
            'country' => ['nullable', 'string', 'size:2'],
            'registration_number' => ['nullable', 'string', 'max:64'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'categories' => ['nullable', 'array', 'max:20'],
            'categories.*' => ['string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
