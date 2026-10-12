<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\PrescriptionStatus;
use App\Models\Batch;
use App\Models\Medicine;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use App\Pharmacy\CurrentBranch;
use App\Pharmacy\InsufficientStock;
use App\Pharmacy\Money;
use App\Pharmacy\Prescriptions;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Prescriptions brought to the branch being worked in.
 */
class PrescriptionController extends Controller
{
    public function __construct(private readonly CurrentBranch $branch) {}

    public function index(Request $request): Response
    {
        $status = PrescriptionStatus::tryFrom((string) $request->query('status'));

        return Inertia::render('prescriptions/index', [
            'prescriptions' => Prescription::query()
                ->where('branch_id', $this->branch->id())
                ->when($status !== null, fn ($query) => $query->where('status', $status))
                ->withCount('items')
                ->latest()
                ->limit(200)
                ->get()
                ->map(fn (Prescription $prescription) => [
                    'id' => $prescription->id,
                    'number' => $prescription->number,
                    'patientName' => $prescription->patient_name,
                    'prescriberName' => $prescription->prescriber_name,
                    'issuedOn' => $prescription->issued_on->toDateString(),
                    'status' => $prescription->status->value,
                    'statusLabel' => $prescription->status->label(),
                    'items' => $prescription->getAttribute('items_count'),
                ]),
            'status' => $status?->value,
            'statuses' => array_map(fn (PrescriptionStatus $s) => ['value' => $s->value, 'label' => $s->label()], PrescriptionStatus::cases()),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('prescriptions/create', [
            'medicines' => Medicine::query()->where('active', true)->orderBy('name')->get()
                ->map(fn (Medicine $medicine) => [
                    'id' => $medicine->id,
                    'label' => $medicine->label(),
                    'unit' => $medicine->unit,
                    'controlled' => $medicine->controlled,
                ]),
            'today' => CarbonImmutable::today()->toDateString(),
        ]);
    }

    public function store(Request $request, Prescriptions $prescriptions): RedirectResponse
    {
        $validated = $request->validate([
            'patient_name' => ['required', 'string', 'max:255'],
            'patient_age' => ['nullable', 'integer', 'between:0,130'],
            'prescriber_name' => ['required', 'string', 'max:255'],
            'prescriber_registration' => ['nullable', 'string', 'max:64'],
            'issued_on' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.medicine_id' => ['required', 'uuid', 'distinct', Rule::exists('medicines', 'id')->where('organization_id', app(CurrentTenant::class)->id())],
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

        return to_route('prescriptions.show', $prescription)->with('success', "Prescription {$prescription->number} recorded.");
    }

    public function show(Prescription $prescription): Response
    {
        $this->ensureHere($prescription);
        $prescription->load(['items.medicine', 'recorder:id,name', 'verifier:id,name', 'sale:id,number']);

        $sellable = Batch::query()
            ->where('branch_id', $this->branch->id())
            ->whereIn('medicine_id', $prescription->items->pluck('medicine_id'))
            ->whereDate('expires_on', '>', CarbonImmutable::today())
            ->groupBy('medicine_id')
            ->selectRaw('medicine_id, SUM(quantity_on_hand) AS quantity')
            ->pluck('quantity', 'medicine_id');

        $access = app(CurrentAccess::class)->get();
        $can = fn (string $permission) => $access?->allowsIn($permission, branchId: $this->branch->id()) ?? false;
        $status = $prescription->status;

        return Inertia::render('prescriptions/show', [
            'prescription' => [
                'id' => $prescription->id,
                'number' => $prescription->number,
                'patientName' => $prescription->patient_name,
                'patientAge' => $prescription->patient_age,
                'prescriberName' => $prescription->prescriber_name,
                'prescriberRegistration' => $prescription->prescriber_registration,
                'issuedOn' => $prescription->issued_on->toDateString(),
                'expiresOn' => $prescription->expiresOn()->toDateString(),
                'expired' => $prescription->isExpired(),
                'status' => $status->value,
                'statusLabel' => $status->label(),
                'notes' => $prescription->notes,
                'recordedBy' => $prescription->recorder->name,
                'verifiedBy' => $prescription->verifier?->name,
                'verifiedAt' => $prescription->verified_at?->toIso8601String(),
                'rejectionReason' => $prescription->rejection_reason,
                'sale' => $prescription->sale === null ? null : ['id' => $prescription->sale->id, 'number' => $prescription->sale->number],
                'totalMinor' => (int) $prescription->items->sum(fn (PrescriptionItem $item) => $item->quantity * $item->medicine->sell_price_minor),
                'items' => $prescription->items->map(fn (PrescriptionItem $item) => [
                    'id' => $item->id,
                    'medicine' => $item->medicine->label(),
                    'controlled' => $item->medicine->controlled,
                    'unit' => $item->medicine->unit,
                    'quantity' => $item->quantity,
                    'directions' => $item->directions,
                    'available' => (int) ($sellable[$item->medicine_id] ?? 0),
                ]),
            ],
            'can' => [
                'verify' => $status === PrescriptionStatus::Recorded && $can('pharmacy.prescriptions.verify'),
                'reject' => in_array($status, [PrescriptionStatus::Recorded, PrescriptionStatus::Verified], true) && $can('pharmacy.prescriptions.verify'),
                'cancel' => in_array($status, [PrescriptionStatus::Recorded, PrescriptionStatus::Verified], true) && $can('pharmacy.prescriptions.record'),
                'dispense' => $status === PrescriptionStatus::Verified && ! $prescription->isExpired() && $can('pharmacy.sales.create'),
            ],
            'paymentMethods' => array_map(fn (PaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()], PaymentMethod::cases()),
        ]);
    }

    public function verify(Request $request, Prescription $prescription, Prescriptions $prescriptions): RedirectResponse
    {
        $this->ensureHere($prescription);
        $prescriptions->verify($prescription, $this->user($request));

        return back()->with('success', "Prescription {$prescription->number} verified.");
    }

    public function reject(Request $request, Prescription $prescription, Prescriptions $prescriptions): RedirectResponse
    {
        $this->ensureHere($prescription);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $prescriptions->reject($prescription, $this->user($request), $validated['reason']);

        return back()->with('success', "Prescription {$prescription->number} rejected.");
    }

    public function cancel(Prescription $prescription, Prescriptions $prescriptions): RedirectResponse
    {
        $this->ensureHere($prescription);
        $prescriptions->cancel($prescription);

        return back()->with('success', "Prescription {$prescription->number} cancelled.");
    }

    public function dispense(Request $request, Prescription $prescription, Prescriptions $prescriptions): RedirectResponse
    {
        $this->ensureHere($prescription);
        $validated = $request->validate([
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['nullable', 'string', 'max:64'],
            'amount_tendered' => ['nullable', 'string', Money::rule()],
        ]);

        try {
            $sale = $prescriptions->dispense(
                $prescription,
                $this->user($request),
                PaymentMethod::from($validated['payment_method']),
                $validated['payment_reference'] ?? null,
                isset($validated['amount_tendered']) ? Money::toMinor($validated['amount_tendered']) : null,
            );
        } catch (InsufficientStock $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return to_route('sales.show', $sale)->with('success', "Prescription {$prescription->number} dispensed as {$sale->number}.");
    }

    /**
     * Prescriptions of other branches look unknown.
     */
    private function ensureHere(Prescription $prescription): void
    {
        abort_unless($prescription->branch_id === $this->branch->id(), 404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
