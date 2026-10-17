<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\ItemPacks;
use App\Modules\Inventory\Enums\StorageCondition;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPack;
use App\Modules\Pharmacy\Enums\MedicineForm;
use App\Modules\Pharmacy\Models\Medicine;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
        $category = $request->string('category')->toString();
        $showInactive = $request->boolean('inactive');

        return Inertia::render('medicines/index', [
            'medicines' => Medicine::query()
                ->search($search)
                ->when($category !== '', fn ($query) => $query->whereHas('item', fn ($item) => $item->where('category', $category)))
                ->when(! $showInactive, fn ($query) => $query->active())
                ->orderBy('name')
                ->limit(200)
                ->get()
                ->map(fn (Medicine $medicine) => $this->summary($medicine)),
            'filters' => ['search' => $search, 'category' => $category, 'inactive' => $showInactive],
            'categories' => $this->categories(),
            'canManage' => $request->user()?->can('pharmacy.medicines.manage') ?? false,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('medicines/form', [
            'medicine' => null,
            ...$this->choices(),
        ]);
    }

    public function store(Request $request, ItemPacks $packs): RedirectResponse
    {
        $medicine = new Medicine;
        $this->save($request, $medicine, $packs);

        return to_route('medicines.index')->with('success', "{$medicine->label()} added.");
    }

    public function edit(Medicine $medicine): Response
    {
        return Inertia::render('medicines/form', [
            'medicine' => [
                ...$this->summary($medicine),
                'sellPrice' => Money::toMajor($medicine->sell_price_minor),
                'packs' => $medicine->item->packs->map(fn (ItemPack $pack) => [
                    'name' => $pack->name,
                    'units' => $pack->units,
                    'sellPrice' => $pack->sell_price_minor === null ? '' : Money::toMajor($pack->sell_price_minor),
                ])->values(),
            ],
            ...$this->choices(),
        ]);
    }

    public function update(Request $request, Medicine $medicine, ItemPacks $packs): RedirectResponse
    {
        $this->save($request, $medicine, $packs);

        return to_route('medicines.index')->with('success', "{$medicine->label()} saved.");
    }

    /**
     * The medicine and the packs its item is bought in, together.
     */
    private function save(Request $request, Medicine $medicine, ItemPacks $packs): void
    {
        [$attributes, $packList] = $this->validated($request, $medicine);

        DB::transaction(function () use ($medicine, $attributes, $packs, $packList) {
            $medicine->fill($attributes)->save();
            $packs->sync($medicine->item, $packList);
        });
    }

    /**
     * The medicine's attributes, and the packs its item is bought in.
     *
     * @return array{0: array<string, mixed>, 1: list<array{name: string, units: int, sell_price_minor: int|null}>}
     */
    private function validated(Request $request, Medicine $medicine): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'generic_name' => ['required', 'string', 'max:255'],
            'form' => ['required', Rule::enum(MedicineForm::class)],
            'strength' => ['nullable', 'string', 'max:64'],
            'unit' => ['required', 'string', 'max:32'],
            'category' => ['nullable', 'string', 'max:64'],
            'storage' => ['nullable', Rule::enum(StorageCondition::class)],
            'barcode' => [
                'nullable', 'string', 'max:64',
                Rule::unique('items', 'barcode')
                    ->where('organization_id', $medicine->organization_id ?? app(CurrentTenant::class)->id())
                    ->ignore($medicine->id),
            ],
            'prescription_only' => ['boolean'],
            'controlled' => ['boolean'],
            'sell_price' => ['required', 'string', Money::rule()],
            'reorder_level' => ['required', 'integer', 'min:0', 'max:1000000'],
            'active' => ['boolean'],
            'packs' => ['array', 'max:10'],
            'packs.*.name' => ['required', 'string', 'max:32'],
            'packs.*.units' => ['required', 'integer', 'min:2', 'max:100000'],
            'packs.*.sell_price' => ['nullable', 'string', Money::rule()],
        ]);

        // A controlled medicine is always prescription-only.
        $controlled = (bool) ($validated['controlled'] ?? false);

        return [
            [
                ...Arr::except($validated, ['sell_price', 'packs']),
                'controlled' => $controlled,
                'prescription_only' => $controlled || (bool) ($validated['prescription_only'] ?? false),
                'active' => (bool) ($validated['active'] ?? true),
                'sell_price_minor' => Money::toMinor($validated['sell_price']),
                // One spelling per category, whoever typed it first.
                'category' => $this->category($validated['category'] ?? null),
                'storage' => $validated['storage'] ?? StorageCondition::Room->value,
            ],
            array_values(array_map(fn (array $pack) => [
                'name' => (string) $pack['name'],
                'units' => (int) $pack['units'],
                'sell_price_minor' => isset($pack['sell_price']) && $pack['sell_price'] !== '' ? Money::toMinor($pack['sell_price']) : null,
            ], $validated['packs'] ?? [])),
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
            'category' => $medicine->category,
            'storage' => $medicine->storage->value,
            'storageLabel' => $medicine->storage->short(),
            'prescriptionOnly' => $medicine->prescription_only,
            'controlled' => $medicine->controlled,
            'sellPriceMinor' => $medicine->sell_price_minor,
            'reorderLevel' => $medicine->reorder_level,
            'active' => $medicine->active,
        ];
    }

    /**
     * What the form offers: forms, storage conditions, categories in use.
     *
     * @return array{forms: list<array{value: string, label: string}>, storageConditions: list<array{value: string, label: string}>, categories: list<string>}
     */
    private function choices(): array
    {
        return [
            'forms' => array_map(fn (MedicineForm $form) => ['value' => $form->value, 'label' => $form->label()], MedicineForm::cases()),
            'storageConditions' => array_map(fn (StorageCondition $storage) => ['value' => $storage->value, 'label' => $storage->label()], StorageCondition::cases()),
            'categories' => $this->categories(),
        ];
    }

    /**
     * @return list<string>
     */
    private function categories(): array
    {
        return array_values(Item::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->map(fn ($category) => (string) $category)->all());
    }

    private function category(?string $typed): ?string
    {
        $typed = trim((string) $typed);
        if ($typed === '') {
            return null;
        }

        return Item::query()->whereNotNull('category')->whereRaw('LOWER(category) = ?', [mb_strtolower($typed)])->value('category') ?? $typed;
    }
}
