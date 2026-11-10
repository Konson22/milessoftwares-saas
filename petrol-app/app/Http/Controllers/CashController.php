<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\Attendant;
use App\Models\BankDeposit;
use App\Models\CashMovement;
use App\Models\CashTransfer;
use App\Models\Expense;
use App\Models\Safe;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Petrol\Cashbook;
use App\Petrol\Currencies;
use App\Petrol\Custody;
use App\Petrol\Permissions;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CashController extends Controller
{
    use WorksInBranch;

    public function index(Cashbook $cashbook, Custody $custody): Response
    {
        $branchId = $this->branchId();
        $shift = Shift::query()->where('branch_id', $branchId)->where('status', Shift::OPEN)->with('assignments.attendant')->first();
        $books = Money::currency();
        $accepted = app(Currencies::class)->accepted($branchId);
        $lines = function (callable $amount) use ($accepted): array {
            return array_map(fn (array $row) => [
                'currency' => $row['code'],
                'minor' => $amount($row['code']),
            ], $accepted);
        };
        $attendants = [];
        if ($shift !== null) {
            $seen = [];
            foreach ($shift->assignments as $assignment) {
                if (isset($seen[$assignment->attendant_id])) {
                    continue;
                }
                $seen[$assignment->attendant_id] = true;
                $attendants[] = [
                    'id' => $assignment->attendant_id,
                    'name' => $assignment->attendant->name,
                    'heldMinor' => $custody->attendantHeld($shift->id, $assignment->attendant_id, $books),
                    'pendingMinor' => $custody->attendantPending($shift->id, $assignment->attendant_id, $books),
                    'holdings' => array_map(fn (array $row) => [
                        'currency' => $row['code'],
                        'heldMinor' => $custody->attendantHeld($shift->id, $assignment->attendant_id, $row['code']),
                        'pendingMinor' => $custody->attendantPending($shift->id, $assignment->attendant_id, $row['code']),
                    ], $accepted),
                ];
            }
        }

        $safes = Safe::query()->where('branch_id', $branchId)->orderBy('code')->get()->map(fn (Safe $safe) => [
            'id' => $safe->id,
            'name' => $safe->name,
            'code' => $safe->code,
            'status' => $safe->status,
            'balanceMinor' => $custody->safeBalance($safe, $books),
            'balances' => $lines(fn (string $code) => $custody->safeBalance($safe, $code)),
        ]);

        return Inertia::render('cash/index', [
            'shift' => $shift === null ? null : [
                'id' => $shift->id,
                'number' => $shift->number,
                'drawerMinor' => $cashbook->drawer($shift->id),
                'openedBy' => $shift->opened_by,
                'drawers' => $lines(fn (string $code) => $cashbook->drawer($shift->id, $code)),
            ],
            'currency' => $books,
            'pettyMinor' => $cashbook->petty($branchId),
            'attendants' => $attendants,
            'safes' => $safes,
            'transfers' => CashTransfer::query()->where('branch_id', $branchId)->latest()->limit(30)->get()->map(fn (CashTransfer $row) => [
                'id' => $row->id,
                'number' => $row->number,
                'kind' => $row->kind,
                'status' => $row->status,
                'currency' => $row->currency ?: $books,
                'declaredMinor' => $row->declared_minor,
                'countedMinor' => $row->counted_minor,
                'source' => $row->source_type,
                'destination' => $row->destination_type,
                'notes' => $row->notes,
            ]),
            'deposits' => BankDeposit::query()->where('branch_id', $branchId)->latest()->limit(20)->get()->map(fn (BankDeposit $row) => [
                'id' => $row->id,
                'number' => $row->number,
                'status' => $row->status,
                'currency' => $row->currency ?: $books,
                'amountMinor' => $row->amount_minor,
                'bank' => $row->bank_reference,
                'bankName' => $row->bank_name,
                'confirmedMinor' => $row->confirmed_amount_minor,
                'slip' => $row->slip_reference,
            ]),
            'movements' => CashMovement::query()->where('branch_id', $branchId)->latest('occurred_at')->limit(40)->get()->map(fn (CashMovement $movement) => [
                'id' => $movement->id,
                'kind' => $movement->kind,
                'amountMinor' => $movement->amount_minor,
                'currency' => $movement->currency ?: $books,
                'at' => $movement->occurred_at->toIso8601String(),
                'notes' => $movement->note,
            ]),
            'canManage' => $this->canHere(Permissions::CASH_MANAGE),
            'canSubmit' => $this->canHere(Permissions::CASH_SUBMIT),
            'canConfirm' => $this->canHere(Permissions::CASH_CONFIRM),
            'canReject' => $this->canHere(Permissions::CASH_REJECT),
            'canTransfer' => $this->canHere(Permissions::CASH_TRANSFER),
            'canSafes' => $this->canHere(Permissions::CASH_SAFES),
            'canDeposit' => $this->canHere(Permissions::CASH_DEPOSIT),
            'canDepositConfirm' => $this->canHere(Permissions::CASH_DEPOSIT_CONFIRM),
            'canReconcile' => $this->canHere(Permissions::CASH_RECONCILE),
            'canAdjust' => $this->canHere(Permissions::CASH_ADJUST),
            'approvers' => $this->approvers($shift, array_values($safes->pluck('id')->map(fn ($id) => (string) $id)->all())),
            'currencies' => $accepted,
        ]);
    }

    public function fund(Request $request, Cashbook $cashbook): RedirectResponse
    {
        $validated = $request->validate(['amount' => ['required', Money::rule()]]);
        $shift = Shift::query()->where('branch_id', $this->branchId())->where('status', Shift::OPEN)->first();
        abort_if($shift === null, 422, 'Open a shift before moving cash to petty.');

        $cashbook->fundPetty($shift, Money::toMinor($validated['amount']), $this->member($request), null);

        return back()->with('success', 'Cash moved to petty. This stays inside the station; Finance is not told.');
    }

    public function remit(Request $request, Custody $custody): RedirectResponse
    {
        $validated = $request->validate([
            'attendant_id' => ['required', 'uuid'],
            'cashier_id' => ['required', 'uuid'],
            'amount' => ['required', Money::anyRule()],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
            'idempotency_key' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $shift = $this->openShift();
        $attendant = Attendant::query()->whereKey($validated['attendant_id'])->firstOrFail();
        abort_unless(
            ShiftAssignment::query()->where('shift_id', $shift->id)->where('attendant_id', $attendant->id)->exists(),
            422,
            'That attendant is not on this shift.',
        );
        $custody->submitRemittance(
            $shift,
            $attendant,
            $validated['cashier_id'],
            Money::input($validated['amount'], $validated['currency'] ?? null),
            $validated['currency'] ?? null,
            $this->member($request),
            $validated['idempotency_key'] ?? (string) Str::uuid(),
            $validated['notes'] ?? null,
        );

        return back()->with('success', 'Cash submitted. It stays with the attendant until the cashier counts it.');
    }

    public function confirm(Request $request, CashTransfer $transfer, Custody $custody): RedirectResponse
    {
        $this->sameBranch($transfer->branch_id);
        $validated = $request->validate(['counted' => ['required', Money::anyRule()]]);
        $custody->confirm($transfer, Money::input($validated['counted'], $transfer->currency, 'counted'), $this->member($request));

        return back()->with('success', 'Cash counted. The sale was not recorded again.');
    }

    public function reject(Request $request, CashTransfer $transfer, Custody $custody): RedirectResponse
    {
        $this->sameBranch($transfer->branch_id);
        $validated = $request->validate(['notes' => ['required', 'string', 'max:500']]);
        $custody->reject($transfer, $this->member($request), $validated['notes']);

        return back()->with('success', 'Handover rejected. The cash stays with the sender.');
    }

    public function storeSafe(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:32'],
            'custodian_user_id' => ['nullable', 'uuid'],
        ]);
        Safe::query()->create([
            'branch_id' => $this->branchId(),
            'name' => $validated['name'],
            'code' => $validated['code'],
            'custodian_user_id' => $validated['custodian_user_id'] ?? null,
            'status' => Safe::OPEN,
        ]);

        return back()->with('success', 'Safe registered for this station.');
    }

    public function toSafe(Request $request, Custody $custody): RedirectResponse
    {
        $validated = $request->validate([
            'safe_id' => ['required', 'uuid'],
            'amount' => ['required', Money::anyRule()],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
            'idempotency_key' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $safe = $this->safe($validated['safe_id']);
        $custody->submitToSafe(
            $this->openShift(),
            $safe,
            Money::input($validated['amount'], $validated['currency'] ?? null),
            $validated['currency'] ?? null,
            $this->member($request),
            $validated['idempotency_key'] ?? (string) Str::uuid(),
            $validated['notes'] ?? null,
        );

        return back()->with('success', 'Safe handover submitted. The drawer changes when the custodian counts it.');
    }

    public function betweenSafes(Request $request, Custody $custody): RedirectResponse
    {
        $validated = $request->validate([
            'source_safe_id' => ['required', 'uuid'],
            'destination_safe_id' => ['required', 'uuid'],
            'amount' => ['required', Money::anyRule()],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
            'idempotency_key' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $custody->submitBetweenSafes(
            $this->safe($validated['source_safe_id']),
            $this->safe($validated['destination_safe_id']),
            Money::input($validated['amount'], $validated['currency'] ?? null),
            $validated['currency'] ?? null,
            $this->member($request),
            $validated['idempotency_key'] ?? (string) Str::uuid(),
            $validated['notes'] ?? null,
        );

        return back()->with('success', 'Safe-to-safe handover submitted.');
    }

    public function payout(Request $request, Custody $custody): RedirectResponse
    {
        $validated = $request->validate([
            'approver_id' => ['required', 'uuid'],
            'safe_id' => ['nullable', 'uuid'],
            'amount' => ['required', Money::anyRule()],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
            'category' => ['required', 'string', 'max:80'],
            'payee' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:240'],
            'receipt' => ['nullable', 'string', 'max:120'],
            'expense_id' => ['nullable', 'uuid'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        $expenseNote = null;
        if (($validated['expense_id'] ?? null) !== null) {
            $expense = Expense::query()->where('branch_id', $this->branchId())->whereKey($validated['expense_id'])->first();
            if ($expense === null) {
                return back()->withErrors(['expense_id' => 'That expenditure is not on this station.']);
            }
            $expenseNote = 'Expense '.$expense->id;
        }
        $note = collect([
            $validated['category'],
            'Payee: '.$validated['payee'],
            $validated['description'] ?? null,
            isset($validated['receipt']) && $validated['receipt'] !== '' ? 'Receipt: '.$validated['receipt'] : null,
            $expenseNote,
        ])->filter(fn (?string $part) => $part !== null && $part !== '')->implode('. ');
        $custody->submitPayout(
            $this->openShift(),
            $validated['approver_id'],
            Money::input($validated['amount'], $validated['currency'] ?? null),
            $validated['currency'] ?? null,
            $this->member($request),
            $validated['idempotency_key'] ?? (string) Str::uuid(),
            mb_substr($note, 0, 250),
            isset($validated['safe_id']) ? $this->safe($validated['safe_id']) : null,
        );

        return back()->with('success', 'Payout submitted for approval.');
    }

    public function adjust(Request $request, Custody $custody): RedirectResponse
    {
        $validated = $request->validate([
            'approver_id' => ['required', 'uuid'],
            'amount' => ['required', Money::anyRule()],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'type' => ['required', Rule::in(['opening float correction', 'counting correction', 'verified shortage', 'verified overage', 'other'])],
            'reason' => ['required', 'string', 'max:240'],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        $shift = $this->openShift();
        $before = app(Cashbook::class)->drawer($shift->id, $validated['currency'] ?? null);
        $amount = Money::input($validated['amount'], $validated['currency'] ?? null);
        $custody->submitAdjustment(
            $shift,
            $validated['approver_id'],
            $validated['direction'] === 'out' ? -$amount : $amount,
            $validated['currency'] ?? null,
            $this->member($request),
            $validated['idempotency_key'] ?? (string) Str::uuid(),
            mb_substr($validated['type'].'. '.$validated['reason'].'. Balance before '.Money::format($before, $validated['currency'] ?? null).'.', 0, 250),
        );

        return back()->with('success', 'Adjustment submitted. It changes the drawer only after approval.');
    }

    public function deposit(Request $request, Custody $custody): RedirectResponse
    {
        $validated = $request->validate([
            'safe_id' => ['required', 'uuid'],
            'bank_reference' => ['required', 'string', 'max:120'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', Money::anyRule()],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
            'deposit_on' => ['required', 'date'],
            'slip_reference' => ['nullable', 'string', 'max:120'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        $custody->prepareDeposit(
            $this->safe($validated['safe_id']),
            $validated['bank_reference'],
            Money::input($validated['amount'], $validated['currency'] ?? null),
            $validated['currency'] ?? null,
            $validated['deposit_on'],
            $validated['slip_reference'] ?? null,
            $this->member($request),
            $validated['idempotency_key'] ?? (string) Str::uuid(),
            $validated['bank_name'] ?? null,
        );

        return back()->with('success', 'Deposit prepared. The cash is still in the safe.');
    }

    public function submitDeposit(BankDeposit $deposit, Request $request, Custody $custody): RedirectResponse
    {
        $this->sameBranch($deposit->branch_id);
        $custody->submitDeposit($deposit, $this->member($request));

        return back()->with('success', 'Deposit is in transit. The bank has not confirmed it.');
    }

    public function confirmDeposit(BankDeposit $deposit, Request $request, Custody $custody): RedirectResponse
    {
        $this->sameBranch($deposit->branch_id);
        $validated = $request->validate(['confirmed' => ['nullable', Money::anyRule()]]);
        $custody->confirmDeposit($deposit, $this->member($request), isset($validated['confirmed']) ? Money::input($validated['confirmed'], $deposit->currency, 'confirmed') : null);

        return back()->with('success', 'Bank confirmed the deposit.');
    }

    public function rejectDeposit(BankDeposit $deposit, Request $request, Custody $custody): RedirectResponse
    {
        $this->sameBranch($deposit->branch_id);
        $validated = $request->validate(['notes' => ['required', 'string', 'max:500']]);
        $custody->rejectDeposit($deposit, $this->member($request), $validated['notes']);

        return back()->with('success', 'Deposit rejected. The cash is back in the safe.');
    }

    public function count(Request $request, Custody $custody): RedirectResponse
    {
        $validated = $request->validate([
            'holder' => ['nullable', 'string'],
            'holder_type' => ['required_without:holder', Rule::in([CashTransfer::ATTENDANT, CashTransfer::CASHIER_HOLDER, CashTransfer::SAFE])],
            'holder_id' => ['required_without:holder', 'uuid'],
            'counted' => ['required', Money::anyRule()],
            'currency' => ['nullable', 'string', 'size:3', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
        ]);
        if (isset($validated['holder'])) {
            [$holderType, $holderId] = array_pad(explode(':', $validated['holder'], 2), 2, '');
            if (! in_array($holderType, [CashTransfer::ATTENDANT, CashTransfer::CASHIER_HOLDER, CashTransfer::SAFE], true) || ! Str::isUuid($holderId)) {
                abort(422, 'Choose who holds the cash.');
            }
        } else {
            $holderType = $validated['holder_type'];
            $holderId = $validated['holder_id'];
        }
        $shift = Shift::query()->where('branch_id', $this->branchId())->where('status', Shift::OPEN)->first();
        $custody->count(
            $this->branchId(),
            $holderType,
            $holderId,
            $shift?->id,
            $validated['currency'] ?? null,
            Money::input($validated['counted'], $validated['currency'] ?? null, 'counted'),
            $this->member($request),
        );

        return back()->with('success', 'Count recorded. A difference is not taken from another currency.');
    }

    /**
     * @param  list<string>  $safeIds
     * @return list<array{id: string, name: string}>
     */
    private function approvers(?Shift $shift, array $safeIds): array
    {
        $ids = Safe::query()->whereIn('id', $safeIds)->whereNotNull('custodian_user_id')->pluck('custodian_user_id')->all();
        if ($shift !== null) {
            $ids[] = $shift->opened_by;
        }

        return array_values(User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
        ])->all());
    }

    private function openShift(): Shift
    {
        $shift = Shift::query()->where('branch_id', $this->branchId())->where('status', Shift::OPEN)->first();
        abort_if($shift === null, 422, 'Open a shift first.');

        return $shift;
    }

    private function safe(string $id): Safe
    {
        $safe = Safe::query()->where('branch_id', $this->branchId())->whereKey($id)->first();
        abort_if($safe === null, 404);

        return $safe;
    }

    private function sameBranch(string $branchId): void
    {
        abort_unless($branchId === $this->branchId(), 404);
    }
}
