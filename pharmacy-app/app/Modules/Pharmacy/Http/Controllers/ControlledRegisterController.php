<?php

namespace App\Modules\Pharmacy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Pharmacy\Models\Medicine;
use App\Modules\Pharmacy\Models\Prescription;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Sales\Models\Sale;
use App\Support\CurrentBranch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The controlled drugs register of the branch: every movement of each
 * controlled medicine with a running balance, and who, why and for whom.
 * Read straight from the stock ledger, so it always matches the stock.
 */
class ControlledRegisterController extends Controller
{
    public function __invoke(Request $request, CurrentBranch $branch): Response
    {
        $medicines = Medicine::query()->whereRelation('item', 'controlled', true)->orderBy('name')->get();
        $selected = $medicines->firstWhere('id', $request->query('medicine')) ?? $medicines->first();

        $entries = [];

        if ($selected !== null) {
            $movements = StockMovement::query()
                ->where('branch_id', $branch->id())
                ->where('item_id', $selected->id)
                ->with(['batch:id,batch_number', 'user:id,name'])
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            // What each movement points at, fetched once per kind.
            $saleIds = $movements->where('reference_type', 'sale')->pluck('reference_id')->unique();
            $sales = Sale::query()->whereIn('id', $saleIds)->get(['id', 'number', 'prescription_id'])->keyBy('id');
            $byPrescription = Prescription::query()->whereIn('id', $sales->pluck('prescription_id')->filter()->unique())
                ->get(['id', 'number', 'patient_name', 'prescriber_name', 'prescriber_registration'])
                ->keyBy('id');
            // Each sale's prescription (a prescription may be dispensed over several sales).
            $prescriptions = $sales->map(fn (Sale $sale) => $sale->prescription_id === null ? null : $byPrescription->get($sale->prescription_id))->filter();
            $receipts = GoodsReceipt::query()
                ->whereIn('id', $movements->where('reference_type', 'goods_receipt')->pluck('reference_id')->unique())
                ->with('supplier:id,name')
                ->get(['id', 'number', 'supplier_id'])
                ->keyBy('id');

            $balance = 0;
            foreach ($movements as $movement) {
                $balance += $movement->quantity;
                $prescription = $movement->reference_type === 'sale' ? $prescriptions->get($movement->reference_id) : null;
                $receipt = $movement->reference_type === 'goods_receipt' ? $receipts->get($movement->reference_id) : null;

                $entries[] = [
                    'id' => $movement->id,
                    'at' => $movement->created_at->toIso8601String(),
                    'type' => $movement->type->value,
                    'quantity' => $movement->quantity,
                    'balance' => $balance,
                    'batchNumber' => $movement->batch->batch_number,
                    'by' => $movement->user->name,
                    'document' => match ($movement->reference_type) {
                        'sale' => $sales->get($movement->reference_id)?->number,
                        'goods_receipt' => $receipt?->number,
                        default => null,
                    },
                    'supplier' => $receipt?->supplier->name,
                    'prescription' => $prescription?->number,
                    'patient' => $prescription?->patient_name,
                    'prescriber' => $prescription === null ? null : trim($prescription->prescriber_name.' '.($prescription->prescriber_registration ?? '')),
                    'reason' => $movement->reason,
                ];
            }
        }

        return Inertia::render('controlled/index', [
            'medicines' => $medicines->map(fn (Medicine $medicine) => ['id' => $medicine->id, 'label' => $medicine->label()]),
            'selected' => $selected?->id,
            // Newest first on screen; the balance is computed oldest first.
            'entries' => array_reverse($entries),
        ]);
    }
}
