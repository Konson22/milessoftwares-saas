<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\FuelProduct;
use App\Models\Nozzle;
use App\Models\Pump;
use App\Models\Tank;
use App\Petrol\Equipment;
use App\Petrol\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PumpController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        $branchId = $this->branchId();

        return Inertia::render('pumps/index', [
            'pumps' => Pump::query()->with(['nozzles.product', 'nozzles.currentConnection.tank'])->where('branch_id', $branchId)->orderBy('code')->get()->map(fn (Pump $pump) => [
                'id' => $pump->id,
                'name' => $pump->name,
                'code' => $pump->code,
                'status' => $pump->status,
                'nozzles' => $pump->nozzles->map(fn (Nozzle $nozzle) => [
                    'id' => $nozzle->id,
                    'name' => $nozzle->name,
                    'code' => $nozzle->code,
                    'status' => $nozzle->status,
                    'product' => $nozzle->product->name,
                    'productId' => $nozzle->fuel_product_id,
                    'tank' => $nozzle->currentConnection?->tank->code,
                ]),
            ]),
            'products' => FuelProduct::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'tanks' => Tank::query()->where('branch_id', $branchId)->where('status', Tank::ACTIVE)->orderBy('code')->get(['id', 'code', 'name', 'fuel_product_id']),
            'canManage' => $this->canHere(Permissions::PUMPS_MANAGE),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
            'status' => ['required', Rule::in([Pump::ACTIVE, Pump::MAINTENANCE, Pump::OUT])],
        ]);

        Pump::query()->create([
            'branch_id' => $this->branchId(),
            'name' => trim($validated['name']),
            'code' => strtoupper(trim($validated['code'])),
            'status' => $validated['status'],
        ]);

        return back()->with('success', 'Pump added.');
    }

    public function update(Request $request, Pump $pump, Equipment $equipment): RedirectResponse
    {
        $this->ensureHere($pump->branch_id);
        $validated = $request->validate([
            'status' => ['required', Rule::in([Pump::ACTIVE, Pump::MAINTENANCE, Pump::OUT])],
        ]);
        $equipment->setPumpStatus($pump, $validated['status'], $this->member($request));

        return back()->with('success', 'Pump updated.');
    }

    public function nozzle(Request $request, Pump $pump): RedirectResponse
    {
        $this->ensureHere($pump->branch_id);
        $validated = $request->validate([
            'fuel_product_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32'],
        ]);
        $product = FuelProduct::query()->whereKey($validated['fuel_product_id'])->where('active', true)->first();
        abort_if($product === null, 422);

        Nozzle::query()->create([
            'branch_id' => $pump->branch_id,
            'pump_id' => $pump->id,
            'fuel_product_id' => $product->id,
            'name' => trim($validated['name']),
            'code' => strtoupper(trim($validated['code'])),
            'status' => Nozzle::ACTIVE,
        ]);

        return back()->with('success', 'Nozzle added.');
    }

    public function connect(Request $request, Nozzle $nozzle, Equipment $equipment): RedirectResponse
    {
        $this->ensureHere($nozzle->branch_id);
        $validated = $request->validate(['tank_id' => ['required', 'uuid']]);
        $tank = Tank::query()->whereKey($validated['tank_id'])->first();
        abort_if($tank === null, 404);
        $this->ensureHere($tank->branch_id);

        $equipment->connect($nozzle, $tank, $this->member($request));

        return back()->with('success', 'Nozzle connected.');
    }
}
