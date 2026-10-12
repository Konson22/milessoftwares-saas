<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The organization's suppliers (shared by its branches).
 */
class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('suppliers/index', [
            'suppliers' => Supplier::query()->orderBy('name')->get()->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'address' => $supplier->address,
                'active' => $supplier->active,
            ]),
            'canManage' => $request->user()?->can('pharmacy.purchasing.manage') ?? false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = Supplier::query()->create($this->validated($request, null));

        return back()->with('success', "{$supplier->name} added.");
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return back()->with('success', "{$supplier->name} saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Supplier $supplier): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers', 'name')
                ->where('organization_id', app(CurrentTenant::class)->id())
                ->ignore($supplier?->id)],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'active' => ['boolean'],
        ]);

        return [...$validated, 'active' => (bool) ($validated['active'] ?? true)];
    }
}
