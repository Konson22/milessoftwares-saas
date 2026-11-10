<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\FuelReceipt;
use App\Models\Supplier;
use App\Models\Tank;
use App\Petrol\Deliveries;
use App\Petrol\Permissions;
use App\Support\Volume;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DeliveryController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        abort_unless($this->canHere(Permissions::RECEIVING_RECORD) || $this->canHere(Permissions::RECEIVING_APPROVE) || $this->canHere(Permissions::PURCHASES_VIEW), 403);
        $branchId = $this->branchId();

        return Inertia::render('deliveries/index', [
            'receipts' => FuelReceipt::query()->with(['supplier', 'tank'])->where('branch_id', $branchId)->latest()->limit(30)->get()->map(fn (FuelReceipt $row) => [
                'id' => $row->id,
                'number' => $row->number,
                'supplier' => $row->supplier->name,
                'tank' => $row->tank->code,
                'status' => $row->status,
                'declared' => Volume::toLitres($row->declared_milli),
                'accepted' => $row->accepted_milli === null ? null : Volume::toLitres($row->accepted_milli),
                'before' => $row->before_milli === null ? null : Volume::toLitres($row->before_milli),
                'after' => $row->after_milli === null ? null : Volume::toLitres($row->after_milli),
                'dispensed' => $row->dispensed_milli === null ? null : Volume::toLitres($row->dispensed_milli),
                'expected' => $row->expected_milli === null ? null : Volume::toLitres($row->expected_milli),
                'variance' => $row->receiving_variance_milli === null ? null : Volume::toLitres($row->receiving_variance_milli),
                'reconciliation' => $row->reconciliation,
                'open' => $row->status === FuelReceipt::PENDING && $row->completed_at === null,
                'ready' => $row->status === FuelReceipt::PENDING && $row->completed_at !== null,
            ]),
            'tanks' => Tank::query()->where('branch_id', $branchId)->where('status', Tank::ACTIVE)->orderBy('code')->get(['id', 'code', 'name']),
            'suppliers' => Supplier::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'canRecord' => $this->canHere(Permissions::RECEIVING_RECORD) || $this->canHere(Permissions::PURCHASES_MANAGE),
            'canApprove' => $this->canHere(Permissions::RECEIVING_APPROVE),
        ]);
    }

    public function store(Request $request, Deliveries $deliveries): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::RECEIVING_RECORD) || $this->canHere(Permissions::PURCHASES_MANAGE), 403);
        $validated = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'tank_id' => ['required', 'uuid'],
            'declared' => ['required', Volume::rule()],
            'before' => ['required', Volume::rule()],
            'unit_cost' => ['required', 'string', 'max:20'],
            'currency' => ['nullable', 'string', 'size:3'],
            'tanker' => ['nullable', 'string', 'max:64'],
            'driver' => ['nullable', 'string', 'max:255'],
            'supplier_reference' => ['nullable', 'string', 'max:64'],
        ]);
        $supplier = Supplier::query()->whereKey($validated['supplier_id'])->where('active', true)->first();
        abort_if($supplier === null, 422);
        $deliveries->start($this->branchId(), $supplier, [
            'tank_id' => $validated['tank_id'],
            'declared' => $validated['declared'],
            'before' => $validated['before'],
            'unit_cost' => $validated['unit_cost'],
            'currency' => $validated['currency'] ?? null,
            'tanker' => $this->text($validated['tanker'] ?? null),
            'driver' => $this->text($validated['driver'] ?? null),
            'supplier_reference' => $this->text($validated['supplier_reference'] ?? null),
            'purchase_line_id' => null,
            'idempotency_key' => null,
        ], $this->member($request));

        return back()->with('success', 'Unloading started. Stock is unchanged until a supervisor approves the accepted quantity.');
    }

    public function complete(Request $request, FuelReceipt $receipt, Deliveries $deliveries): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::RECEIVING_RECORD) || $this->canHere(Permissions::PURCHASES_MANAGE), 403);
        $this->ensureHere($receipt->branch_id);
        $validated = $request->validate([
            'after' => ['nullable', Volume::rule()],
            'accepted' => ['required', Volume::rule()],
        ]);
        $deliveries->complete($receipt, $validated['after'] ?? null, $validated['accepted'], $this->member($request));

        return back()->with('success', 'Delivery measurements saved. Stock is still unchanged.');
    }

    public function approve(Request $request, FuelReceipt $receipt, Deliveries $deliveries): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::RECEIVING_APPROVE), 403);
        $this->ensureHere($receipt->branch_id);
        $deliveries->approve($receipt, $this->member($request));

        return back()->with('success', 'Accepted quantity posted to the tank once.');
    }
}
