<?php

namespace App\Http\Controllers;

use App\Models\MenuItem;
use App\Models\StockMovement;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The restaurant's menu: its dishes and drinks, shared by every branch.
 */
class MenuController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $section = $request->string('section')->toString();
        $showInactive = $request->boolean('inactive');

        $items = MenuItem::query()
            ->search($search)
            ->when($section !== '', fn ($query) => $query->where('section', $section))
            ->when(! $showInactive, fn ($query) => $query->where('active', true))
            ->orderBy('section')
            ->orderBy('name')
            ->limit(300)
            ->get();

        return Inertia::render('menu/index', [
            'items' => $items->map(fn (MenuItem $item) => $this->summary($item)),
            'filters' => ['search' => $search, 'section' => $section, 'inactive' => $showInactive],
            'sections' => $this->sections(),
            'canManage' => $request->user()?->can('restaurant.menu.manage') ?? false,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('menu/form', [
            'item' => null,
            'sections' => $this->sections(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $item = new MenuItem;
        $this->save($request, $item);

        return to_route('menu.index')->with('success', "{$item->name} added to the menu.");
    }

    public function edit(MenuItem $item): Response
    {
        return Inertia::render('menu/form', [
            'item' => [
                ...$this->summary($item),
                'price' => Money::toMajor($item->price_minor),
                // A drink that has been stocked stays a drink.
                'hasStock' => $this->hasStock($item),
            ],
            'sections' => $this->sections(),
        ]);
    }

    public function update(Request $request, MenuItem $item): RedirectResponse
    {
        $this->save($request, $item);

        return to_route('menu.index')->with('success', "{$item->name} saved.");
    }

    private function save(Request $request, MenuItem $item): void
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(MenuItem::KINDS)],
            'name' => ['required', 'string', 'max:255'],
            'section' => ['nullable', 'string', 'max:64'],
            'unit' => ['nullable', 'string', 'max:32'],
            'barcode' => [
                'nullable', 'string', 'max:64',
                Rule::unique('menu_items', 'barcode')
                    ->where('organization_id', $item->organization_id ?? app(CurrentTenant::class)->id())
                    ->ignore($item->id),
            ],
            'price' => ['required', 'string', Money::rule()],
            'reorder_level' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'active' => ['boolean'],
        ]);

        if ($item->exists && $validated['kind'] !== $item->kind && $this->hasStock($item)) {
            throw ValidationException::withMessages(['kind' => "{$item->name} has been stocked: it stays a drink."]);
        }

        $drink = $validated['kind'] === MenuItem::DRINK;
        $item->fill([
            'kind' => $validated['kind'],
            'name' => trim($validated['name']),
            'section' => $this->section($validated['section'] ?? null),
            'unit' => ($unit = trim((string) ($validated['unit'] ?? ''))) === '' ? ($drink ? 'bottle' : 'plate') : $unit,
            'barcode' => $drink && ($barcode = trim((string) ($validated['barcode'] ?? ''))) !== '' ? $barcode : null,
            'price_minor' => Money::toMinor($validated['price']),
            // Only drinks are reordered.
            'reorder_level' => $drink ? (int) ($validated['reorder_level'] ?? 0) : 0,
            'active' => (bool) ($validated['active'] ?? true),
        ])->save();
    }

    private function hasStock(MenuItem $item): bool
    {
        return StockMovement::query()->where('menu_item_id', $item->id)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(MenuItem $item): array
    {
        return [
            'id' => $item->id,
            'kind' => $item->kind,
            'name' => $item->name,
            'section' => $item->section,
            'unit' => $item->unit,
            'barcode' => $item->barcode,
            'priceMinor' => $item->price_minor,
            'reorderLevel' => $item->reorder_level,
            'active' => $item->active,
        ];
    }

    /**
     * Sections of the menu in use.
     *
     * @return list<string>
     */
    private function sections(): array
    {
        return array_values(MenuItem::query()->whereNotNull('section')->distinct()->orderBy('section')->pluck('section')->map(fn ($section) => (string) $section)->all());
    }

    /**
     * A typed section, spelt as the one already in use.
     */
    private function section(?string $typed): ?string
    {
        $typed = trim((string) $typed);
        if ($typed === '') {
            return null;
        }

        return MenuItem::query()->whereNotNull('section')->whereRaw('LOWER(section) = ?', [mb_strtolower($typed)])->value('section') ?? $typed;
    }
}
