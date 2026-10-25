<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\DiningTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The tables of the branch being worked in, by area.
 */
class DiningTableController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        $tables = DiningTable::query()
            ->where('branch_id', $this->branchId())
            ->withExists('openOrder')
            ->orderBy('area')
            ->orderBy('name')
            ->get();

        return Inertia::render('tables/index', [
            'tables' => $tables->map(fn (DiningTable $table) => [
                'id' => $table->id,
                'name' => $table->name,
                'area' => $table->area,
                'seats' => $table->seats,
                'active' => $table->active,
                'inUse' => (bool) $table->getAttribute('open_order_exists'),
            ]),
            'areas' => $tables->pluck('area')->filter()->unique()->sort()->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $table = new DiningTable(['branch_id' => $this->branchId()]);
        $this->save($request, $table);

        return back()->with('success', "Table {$table->name} added.");
    }

    public function update(Request $request, DiningTable $table): RedirectResponse
    {
        $this->ensureHere($table->branch_id);
        $this->save($request, $table);

        return back()->with('success', "Table {$table->name} saved.");
    }

    private function save(Request $request, DiningTable $table): void
    {
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:32',
                Rule::unique('dining_tables', 'name')
                    ->where('organization_id', $table->organization_id ?? app(CurrentTenant::class)->id())
                    ->where('branch_id', $this->branchId())
                    ->ignore($table->id),
            ],
            'area' => ['nullable', 'string', 'max:32'],
            'seats' => ['required', 'integer', 'min:1', 'max:100'],
            'active' => ['boolean'],
        ]);

        $active = (bool) ($validated['active'] ?? true);
        if (! $active && $table->exists && $table->openOrder()->exists()) {
            throw ValidationException::withMessages(['active' => "Table {$table->name} is being served: close its order first."]);
        }

        $table->fill([
            'name' => trim($validated['name']),
            'area' => ($area = trim((string) ($validated['area'] ?? ''))) === '' ? null : $area,
            'seats' => (int) $validated['seats'],
            'active' => $active,
        ])->save();
    }
}
