<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Pharmacy\Enums\MedicineForm;
use App\Modules\Pharmacy\Models\Medicine;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The organization's medicine catalogue: shared by every branch.
 */
class MedicineController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $showInactive = $request->boolean('inactive');

        return Inertia::render('medicines/index', [
            'medicines' => Medicine::query()
                ->search($search)
                ->when(! $showInactive, fn ($query) => $query->where('active', true))
                ->orderBy('name')
                ->limit(200)
                ->get()
                ->map(fn (Medicine $medicine) => $this->summary($medicine)),
            'filters' => ['search' => $search, 'inactive' => $showInactive],
            'canManage' => $request->user()?->can('pharmacy.medicines.manage') ?? false,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('medicines/form', [
            'medicine' => null,
            'forms' => $this->forms(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $medicine = new Medicine;
        $medicine->fill($this->validated($request, $medicine))->save();

        return to_route('medicines.index')->with('success', "{$medicine->label()} added.");
    }

    public function edit(Medicine $medicine): Response
    {
        return Inertia::render('medicines/form', [
            'medicine' => [
                ...$this->summary($medicine),
                'sellPrice' => Money::toMajor($medicine->sell_price_minor),
            ],
            'forms' => $this->forms(),
        ]);
    }

    public function update(Request $request, Medicine $medicine): RedirectResponse
    {
        $medicine->fill($this->validated($request, $medicine))->save();

        return to_route('medicines.index')->with('success', "{$medicine->label()} saved.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Medicine $medicine): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'generic_name' => ['required', 'string', 'max:255'],
            'form' => ['required', Rule::enum(MedicineForm::class)],
            'strength' => ['nullable', 'string', 'max:64'],
            'unit' => ['required', 'string', 'max:32'],
            'barcode' => [
                'nullable', 'string', 'max:64',
                Rule::unique('medicines', 'barcode')
                    ->where('organization_id', $medicine->organization_id ?? app(CurrentTenant::class)->id())
                    ->ignore($medicine->id),
            ],
            'prescription_only' => ['boolean'],
            'controlled' => ['boolean'],
            'sell_price' => ['required', 'string', Money::rule()],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:1000000'],
            'active' => ['boolean'],
        ]);

        // A controlled medicine is always prescription-only.
        $controlled = (bool) ($validated['controlled'] ?? false);

        return [
            ...Arr::except($validated, ['sell_price']),
            'controlled' => $controlled,
            'prescription_only' => $controlled || (bool) ($validated['prescription_only'] ?? false),
            'active' => (bool) ($validated['active'] ?? true),
            'sell_price_minor' => Money::toMinor($validated['sell_price']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Medicine $medicine): array
    {
        return [
            'id' => $medicine->id,
            'name' => $medicine->name,
            'genericName' => $medicine->generic_name,
            'form' => $medicine->form->value,
            'strength' => $medicine->strength,
            'unit' => $medicine->unit,
            'barcode' => $medicine->barcode,
            'prescriptionOnly' => $medicine->prescription_only,
            'controlled' => $medicine->controlled,
            'sellPriceMinor' => $medicine->sell_price_minor,
            'reorderLevel' => $medicine->reorder_level,
            'active' => $medicine->active,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function forms(): array
    {
        return array_map(fn (MedicineForm $form) => ['value' => $form->value, 'label' => $form->label()], MedicineForm::cases());
    }
}
