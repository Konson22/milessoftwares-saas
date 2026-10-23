<?php

namespace App\Http\Controllers\Api;

use App\Modules\Inventory\Actions\InsufficientStock;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Pharmacy\Actions\Prescriptions;
use App\Modules\Pharmacy\Enums\PrescriptionStatus;
use App\Modules\Pharmacy\Models\Prescription;
use App\Modules\Pharmacy\Models\PrescriptionItem;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Prescriptions brought to the branch: recorded at the counter, verified by
 * a pharmacist, dispensed (and paid) from the app. Prescription-only
 * medicines are only sold this way.
 */
class PrescriptionController extends ApiController
{
    /** What is still to be done: waiting for a pharmacist, or to dispense. */
    private const OPEN = [PrescriptionStatus::Recorded, PrescriptionStatus::Verified, PrescriptionStatus::PartlyDispensed];

    /**
     * GET api/v1/prescriptions?status=&search=: open ones by default, newest
     * first; search by number or patient.
     */
    public function index(Request $request): JsonResponse
    {
        $status = PrescriptionStatus::tryFrom((string) $request->query('status'));
        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';

        $prescriptions = Prescription::query()
            ->where('branch_id', $this->branch->id())
            ->when($status !== null, fn ($query) => $query->where('status', $status), fn ($query) => $query->whereIn('status', self::OPEN))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->whereRaw('LOWER(patient_name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhere('number', $search)))
            ->withCount('items')
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['data' => $prescriptions->map(fn (Prescription $prescription) => [
            'id' => $prescription->id,
            'number' => $prescription->number,
            'patient_name' => $prescription->patient_name,
            'prescriber_name' => $prescription->prescriber_name,
            'issued_on' => $prescription->issued_on->toDateString(),
            'expired' => $prescription->isExpired(),
            'status' => $prescription->status->value,
            'status_label' => $prescription->status->label(),
            'items_count' => (int) $prescription->getAttribute('items_count'),
        ])->values()]);
    }

    /**
     * POST api/v1/prescriptions: recorded at the counter, waiting for a pharmacist.
     */
    public function store(Request $request, Prescriptions $prescriptions, CurrentTenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'patient_name' => ['required', 'string', 'max:255'],
            'patient_age' => ['nullable', 'integer', 'between:0,130'],
            'prescriber_name' => ['required', 'string', 'max:255'],
            'prescriber_registration' => ['nullable', 'string', 'max:64'],
            'issued_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.medicine_id' => ['required', 'uuid', 'distinct', Rule::exists('medicines', 'id')->where('organization_id', $tenant->id())],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.directions' => ['required', 'string', 'max:255'],
        ]);

        $prescription = $prescriptions->record(
            by: $this->user($request),
            branchId: $this->branch->id(),
            details: [
                'patient_name' => $validated['patient_name'],
                'patient_age' => isset($validated['patient_age']) ? (int) $validated['patient_age'] : null,
                'prescriber_name' => $validated['prescriber_name'],
                'prescriber_registration' => $validated['prescriber_registration'] ?? null,
                'issued_on' => $validated['issued_on'],
                'notes' => $validated['notes'] ?? null,
            ],
            items: array_values(array_map(fn (array $item) => [
                'medicine_id' => (string) $item['medicine_id'],
                'quantity' => (int) $item['quantity'],
                'directions' => (string) $item['directions'],
            ], $validated['items'])),
        );

        return response()->json(['data' => $this->present($prescription)], 201);
    }

    /**
     * GET api/v1/prescriptions/{prescription}
     */
    public function show(Prescription $prescription): JsonResponse
    {
        $this->ensureHere($prescription);

        return response()->json(['data' => $this->present($prescription)]);
    }

    /**
     * POST api/v1/prescriptions/{prescription}/verify
     */
    public function verify(Request $request, Prescription $prescription, Prescriptions $prescriptions): JsonResponse
    {
        $this->ensureHere($prescription);

        return response()->json(['data' => $this->present($prescriptions->verify($prescription, $this->user($request)))]);
    }

    /**
     * POST api/v1/prescriptions/{prescription}/reject
     */
    public function reject(Request $request, Prescription $prescription, Prescriptions $prescriptions): JsonResponse
    {
        $this->ensureHere($prescription);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return response()->json(['data' => $this->present($prescriptions->reject($prescription, $this->user($request), $validated['reason']))]);
    }

    /**
     * POST api/v1/prescriptions/{prescription}/dispense: what is given now
     * (prescription item id => quantity; all that is left when omitted),
     * sold and paid as one sale. The same submission_id again answers that sale.
     */
    public function dispense(Request $request, Prescription $prescription, Prescriptions $prescriptions): JsonResponse
    {
        $this->ensureHere($prescription);
        $validated = $request->validate([
            'submission_id' => ['required', 'uuid'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)->except(PaymentMethod::Account)],
            'payment_reference' => ['nullable', 'string', 'max:64'],
            'amount_tendered_minor' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'quantities' => ['nullable', 'array', 'max:30'],
            'quantities.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        $duplicate = Sale::query()->where('submission_id', $validated['submission_id'])->exists();

        try {
            $sale = $prescriptions->dispense(
                $prescription,
                $this->user($request),
                PaymentMethod::from($validated['payment_method']),
                $validated['payment_reference'] ?? null,
                isset($validated['amount_tendered_minor']) ? (int) $validated['amount_tendered_minor'] : null,
                isset($validated['quantities']) ? array_map(fn ($quantity) => (int) $quantity, $validated['quantities']) : null,
                $validated['submission_id'],
            );
        } catch (InsufficientStock $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return response()->json([
            'sale' => ['id' => $sale->id, 'number' => $sale->number, 'total_minor' => $sale->total_minor, 'change_minor' => $sale->change_minor],
            'duplicate' => $duplicate,
            'data' => $this->present($prescription->refresh()),
        ], $duplicate ? 200 : 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Prescription $prescription): array
    {
        $prescription->load(['items.medicine.item', 'recorder:id,name', 'verifier:id,name', 'sales:id,number,status,sold_at,total_minor,prescription_id']);
        $status = $prescription->status;

        $sellable = Batch::query()
            ->where('branch_id', $this->branch->id())
            ->whereIn('item_id', $prescription->items->pluck('medicine_id'))
            ->where('quantity_on_hand', '>', 0)
            ->where('status', Batch::AVAILABLE)
            ->unexpired(CarbonImmutable::today())
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(quantity_on_hand) AS quantity')
            ->pluck('quantity', 'item_id');

        return [
            'id' => $prescription->id,
            'number' => $prescription->number,
            'patient_name' => $prescription->patient_name,
            'patient_age' => $prescription->patient_age,
            'prescriber_name' => $prescription->prescriber_name,
            'prescriber_registration' => $prescription->prescriber_registration,
            'issued_on' => $prescription->issued_on->toDateString(),
            'expires_on' => $prescription->expiresOn()->toDateString(),
            'expired' => $prescription->isExpired(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'notes' => $prescription->notes,
            'recorded_by' => $prescription->recorder->name,
            'verified_by' => $prescription->verifier?->name,
            'rejection_reason' => $prescription->rejection_reason,
            'items' => $prescription->items->map(fn (PrescriptionItem $item) => [
                'id' => $item->id,
                'medicine_id' => $item->medicine_id,
                'name' => $item->medicine->label(),
                'unit' => $item->medicine->unit,
                'controlled' => $item->medicine->controlled,
                'quantity' => $item->quantity,
                'dispensed' => $item->dispensed_quantity,
                'remaining' => $item->remaining(),
                'unit_price_minor' => $item->medicine->sell_price_minor,
                'directions' => $item->directions,
                'available' => (int) ($sellable[$item->medicine_id] ?? 0),
            ])->values(),
            'sales' => $prescription->sales->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'number' => $sale->number,
                'sold_at' => $sale->sold_at->toIso8601String(),
                'total_minor' => $sale->total_minor,
                'voided' => $sale->status === Sale::VOIDED,
            ])->values(),
            'can' => [
                'verify' => $status === PrescriptionStatus::Recorded && $this->may('prescriptions.verify'),
                'reject' => in_array($status, [PrescriptionStatus::Recorded, PrescriptionStatus::Verified], true) && $this->may('prescriptions.verify'),
                'dispense' => in_array($status, [PrescriptionStatus::Verified, PrescriptionStatus::PartlyDispensed], true) && ! $prescription->isExpired() && $this->may('sales.create'),
            ],
        ];
    }

    /**
     * Prescriptions of other branches look unknown.
     */
    private function ensureHere(Prescription $prescription): void
    {
        abort_unless($prescription->branch_id === $this->branch->id(), 404);
    }
}
