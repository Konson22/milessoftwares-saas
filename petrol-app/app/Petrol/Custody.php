<?php

namespace App\Petrol;

use App\Models\Attendant;
use App\Models\BankDeposit;
use App\Models\CashCount;
use App\Models\CashMovement;
use App\Models\CashTransfer;
use App\Models\FuelSale;
use App\Models\PaymentMethod;
use App\Models\Safe;
use App\Models\Shift;
use App\Models\User;
use App\Support\DocumentNumbers;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Physical cash moving between an attendant, the shift drawer, a safe, and a bank.
 * A confirmed handover is not a sale. Pending cash stays with the sender.
 */
class Custody
{
    public function __construct(
        private readonly Cashbook $cash,
        private readonly Rates $rates,
        private readonly Audit $audit,
        private readonly DocumentNumbers $numbers,
    ) {}

    public function submitRemittance(Shift $shift, Attendant $attendant, string $cashierId, int $amount, ?string $currency, User $by, string $key, ?string $note): CashTransfer
    {
        if (! $shift->isOpen() || $attendant->user_id !== $by->id) {
            throw ValidationException::withMessages(['attendant' => 'The attendant on this shift submits their own cash.']);
        }
        if ($cashierId === $by->id) {
            throw ValidationException::withMessages(['cashier_id' => 'Someone else has to receive this cash.']);
        }

        return $this->openTransfer($shift->branch_id, $shift->id, CashTransfer::REMITTANCE, CashTransfer::ATTENDANT, $attendant->id, CashTransfer::CASHIER_HOLDER, $cashierId, $amount, $currency, $by, $key, $note);
    }

    public function submitToSafe(Shift $shift, Safe $safe, int $amount, ?string $currency, User $by, string $key, ?string $note): CashTransfer
    {
        $this->openSafe($safe, $shift->branch_id);
        if ($safe->custodian_user_id === $by->id) {
            throw ValidationException::withMessages(['safe_id' => 'The safe custodian has to confirm this, not the person sending it.']);
        }

        return $this->openTransfer($shift->branch_id, $shift->id, CashTransfer::TO_SAFE, CashTransfer::CASHIER_HOLDER, $by->id, CashTransfer::SAFE, $safe->id, $amount, $currency, $by, $key, $note);
    }

    public function submitBetweenSafes(Safe $source, Safe $destination, int $amount, ?string $currency, User $by, string $key, ?string $note): CashTransfer
    {
        if ($source->branch_id !== $destination->branch_id || $source->id === $destination->id) {
            throw ValidationException::withMessages(['destination_safe_id' => 'Choose another safe at this station.']);
        }
        $this->openSafe($source, $source->branch_id);
        $this->openSafe($destination, $source->branch_id);

        return $this->openTransfer($source->branch_id, null, CashTransfer::BETWEEN_SAFES, CashTransfer::SAFE, $source->id, CashTransfer::SAFE, $destination->id, $amount, $currency, $by, $key, $note);
    }

    public function confirm(CashTransfer $transfer, int $counted, User $by): CashTransfer
    {
        return DB::transaction(function () use ($transfer, $counted, $by) {
            $locked = CashTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, [CashTransfer::CONFIRMED, CashTransfer::DISPUTED], true)) {
                return $locked;
            }
            if ($locked->status !== CashTransfer::PENDING) {
                throw ValidationException::withMessages(['transfer' => 'This handover is '.$locked->status.'.']);
            }
            if ($locked->initiated_by === $by->id) {
                throw ValidationException::withMessages(['transfer' => 'The person who sent the cash cannot confirm it.']);
            }
            if ($locked->kind !== CashTransfer::ADJUSTMENT && $counted < 1) {
                throw ValidationException::withMessages(['counted' => 'Enter the amount you counted, or reject the handover.']);
            }
            if ($locked->kind === CashTransfer::ADJUSTMENT && $counted === 0) {
                throw ValidationException::withMessages(['counted' => 'An adjustment needs an amount.']);
            }
            if ($locked->kind === CashTransfer::ADJUSTMENT) {
                $counted = (int) $locked->declared_minor;
            }
            if (in_array($locked->destination_type, [CashTransfer::CASHIER_HOLDER, CashTransfer::PAYOUT, 'adjustment'], true) && $locked->destination_id !== $by->id) {
                throw ValidationException::withMessages(['transfer' => 'The named person confirms this handover.']);
            }
            if ($locked->destination_type === CashTransfer::SAFE) {
                $safe = Safe::query()->whereKey($locked->destination_id)->firstOrFail();
                if ($safe->custodian_user_id !== null && $safe->custodian_user_id !== $by->id) {
                    throw ValidationException::withMessages(['transfer' => 'The safe custodian confirms this handover.']);
                }
            }

            $this->guardSource($locked, $counted);
            $snapshot = $this->rates->optionalSnapshot($locked->branch_id, $this->currency($locked->currency), abs($counted));
            if ($counted < 0 && $snapshot['reporting_minor'] !== null) {
                $snapshot['reporting_minor'] = -$snapshot['reporting_minor'];
            }
            $status = $counted === (int) $locked->declared_minor ? CashTransfer::CONFIRMED : CashTransfer::DISPUTED;
            $locked->forceFill([
                'status' => $status,
                'counted_minor' => $counted,
                'fx_base' => $snapshot['fx_base'],
                'fx_quote' => $snapshot['fx_quote'],
                'fx_rate_minor' => $snapshot['fx_rate_minor'],
                'reporting_minor' => $snapshot['reporting_minor'],
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
            ])->save();
            $this->postDrawer($locked, $counted, $by);
            $this->audit->record($by, 'cash.confirmed', $locked, ['status' => CashTransfer::PENDING], [
                'status' => $status,
                'declared_minor' => $locked->declared_minor,
                'counted_minor' => $counted,
            ], $locked->branch_id);

            return $locked;
        });
    }

    public function reject(CashTransfer $transfer, User $by, string $note): CashTransfer
    {
        return DB::transaction(function () use ($transfer, $by, $note) {
            $locked = CashTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === CashTransfer::REJECTED) {
                return $locked;
            }
            if ($locked->status !== CashTransfer::PENDING) {
                throw ValidationException::withMessages(['transfer' => 'This handover is already '.$locked->status.'.']);
            }
            if ($locked->initiated_by === $by->id) {
                throw ValidationException::withMessages(['transfer' => 'The person who sent the cash cannot reject it.']);
            }
            $locked->forceFill([
                'status' => CashTransfer::REJECTED,
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
                'notes' => trim($note) === '' ? $locked->notes : trim($note),
            ])->save();
            $this->audit->record($by, 'cash.rejected', $locked, ['status' => CashTransfer::PENDING], ['status' => CashTransfer::REJECTED], $locked->branch_id);

            return $locked;
        });
    }

    public function prepareDeposit(Safe $safe, string $bankReference, int $amount, ?string $currency, string $depositOn, ?string $slip, User $by, string $key, ?string $bankName = null): BankDeposit
    {
        $this->openSafe($safe, $safe->branch_id);

        return DB::transaction(function () use ($safe, $bankReference, $amount, $currency, $depositOn, $slip, $by, $key, $bankName) {
            $existing = BankDeposit::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($amount < 1) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount.']);
            }

            $deposit = BankDeposit::query()->create([
                'branch_id' => $safe->branch_id,
                'safe_id' => $safe->id,
                'number' => $this->numbers->next('bank_deposit', 'BD'),
                'bank_reference' => trim($bankReference),
                'bank_name' => $bankName !== null && trim($bankName) !== '' ? trim($bankName) : null,
                'currency' => $this->storedCurrency($currency),
                'amount_minor' => $amount,
                'deposit_on' => $depositOn,
                'status' => BankDeposit::PREPARED,
                'slip_reference' => $slip,
                'idempotency_key' => $key,
                'prepared_by' => $by->id,
            ]);
            $this->audit->record($by, 'cash.deposit_prepared', $deposit, null, ['amount_minor' => $amount], $safe->branch_id);

            return $deposit;
        });
    }

    public function submitDeposit(BankDeposit $deposit, User $by): BankDeposit
    {
        return DB::transaction(function () use ($deposit, $by) {
            $locked = BankDeposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === BankDeposit::SUBMITTED) {
                return $locked;
            }
            if ($locked->status !== BankDeposit::PREPARED) {
                throw ValidationException::withMessages(['deposit' => 'Only a prepared deposit can leave the safe.']);
            }
            $safe = Safe::query()->whereKey($locked->safe_id)->lockForUpdate()->firstOrFail();
            if ($this->safeBalance($safe, $locked->currency) < (int) $locked->amount_minor) {
                throw ValidationException::withMessages(['amount' => 'The safe does not hold that much in this currency.']);
            }
            $locked->forceFill(['status' => BankDeposit::SUBMITTED, 'submitted_at' => now()])->save();
            $this->audit->record($by, 'cash.deposit_submitted', $locked, ['status' => BankDeposit::PREPARED], ['status' => BankDeposit::SUBMITTED], $locked->branch_id);

            return $locked;
        });
    }

    public function confirmDeposit(BankDeposit $deposit, User $by, ?int $confirmedAmount = null): BankDeposit
    {
        return DB::transaction(function () use ($deposit, $by, $confirmedAmount) {
            $locked = BankDeposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === BankDeposit::CONFIRMED) {
                return $locked;
            }
            if ($locked->status !== BankDeposit::SUBMITTED) {
                throw ValidationException::withMessages(['deposit' => 'The deposit has not left the safe.']);
            }
            if ($locked->prepared_by === $by->id) {
                throw ValidationException::withMessages(['deposit' => 'Someone else confirms the bank took the cash.']);
            }
            $confirmedAmount ??= (int) $locked->amount_minor;
            if ($confirmedAmount < 1 || $confirmedAmount > (int) $locked->amount_minor) {
                throw ValidationException::withMessages(['confirmed' => 'The bank amount cannot exceed the cash that left the safe.']);
            }
            $snapshot = $this->rates->optionalSnapshot($locked->branch_id, $this->currency($locked->currency), $confirmedAmount);
            $locked->forceFill([
                'status' => BankDeposit::CONFIRMED,
                'confirmed_amount_minor' => $confirmedAmount,
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
                'fx_base' => $snapshot['fx_base'],
                'fx_quote' => $snapshot['fx_quote'],
                'fx_rate_minor' => $snapshot['fx_rate_minor'],
                'reporting_minor' => $snapshot['reporting_minor'],
            ])->save();
            $this->audit->record($by, 'cash.deposit_confirmed', $locked, ['status' => BankDeposit::SUBMITTED, 'amount_minor' => $locked->amount_minor], [
                'status' => BankDeposit::CONFIRMED,
                'confirmed_amount_minor' => $confirmedAmount,
            ], $locked->branch_id);

            return $locked;
        });
    }

    public function rejectDeposit(BankDeposit $deposit, User $by, string $note): BankDeposit
    {
        return DB::transaction(function () use ($deposit, $by, $note) {
            $locked = BankDeposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === BankDeposit::REJECTED) {
                return $locked;
            }
            if (! in_array($locked->status, [BankDeposit::PREPARED, BankDeposit::SUBMITTED], true)) {
                throw ValidationException::withMessages(['deposit' => 'This deposit is '.$locked->status.'.']);
            }
            $locked->forceFill([
                'status' => BankDeposit::REJECTED,
                'confirmed_by' => $by->id,
                'notes' => trim($note),
            ])->save();
            $this->audit->record($by, 'cash.deposit_rejected', $locked, null, ['status' => BankDeposit::REJECTED], $locked->branch_id);

            return $locked;
        });
    }

    public function submitPayout(Shift $shift, string $approverId, int $amount, ?string $currency, User $by, string $key, ?string $note, ?Safe $safe = null): CashTransfer
    {
        if (! $shift->isOpen() || $approverId === $by->id) {
            throw ValidationException::withMessages(['approver_id' => 'Another person authorises cash leaving the station.']);
        }
        if ($safe !== null) {
            $this->openSafe($safe, $shift->branch_id);

            return $this->openTransfer($shift->branch_id, null, CashTransfer::PAYOUT, CashTransfer::SAFE, $safe->id, CashTransfer::PAYOUT, $approverId, $amount, $currency, $by, $key, $note);
        }

        return $this->openTransfer($shift->branch_id, $shift->id, CashTransfer::PAYOUT, CashTransfer::CASHIER_HOLDER, $by->id, CashTransfer::PAYOUT, $approverId, $amount, $currency, $by, $key, $note);
    }

    public function submitAdjustment(Shift $shift, string $approverId, int $signedAmount, ?string $currency, User $by, string $key, ?string $note): CashTransfer
    {
        if (! $shift->isOpen() || $approverId === $by->id || $signedAmount === 0) {
            throw ValidationException::withMessages(['approver_id' => 'Another person approves a drawer adjustment.']);
        }

        return $this->openTransfer($shift->branch_id, $shift->id, CashTransfer::ADJUSTMENT, CashTransfer::CASHIER_HOLDER, $by->id, 'adjustment', $approverId, $signedAmount, $currency, $by, $key, $note);
    }

    public function count(string $branchId, string $holderType, string $holderId, ?string $shiftId, ?string $currency, int $counted, User $by): CashCount
    {
        $expected = match ($holderType) {
            CashTransfer::ATTENDANT => $this->attendantHeld((string) $shiftId, $holderId, $currency),
            CashTransfer::SAFE => $this->safeBalance(Safe::query()->whereKey($holderId)->where('branch_id', $branchId)->firstOrFail(), $currency),
            default => $shiftId === null ? 0 : $this->cash->drawer($shiftId, $currency),
        };
        $row = CashCount::query()->create([
            'branch_id' => $branchId,
            'holder_type' => $holderType,
            'holder_id' => $holderId,
            'shift_id' => $shiftId,
            'currency' => $this->storedCurrency($currency),
            'expected_minor' => $expected,
            'counted_minor' => $counted,
            'difference_minor' => $counted - $expected,
            'counted_by' => $by->id,
            'counted_at' => now(),
        ]);
        $this->audit->record($by, 'cash.counted', $row, null, [
            'expected_minor' => $expected,
            'counted_minor' => $counted,
        ], $branchId);

        return $row;
    }

    public function attendantHeld(string $shiftId, string $attendantId, ?string $currency): int
    {
        $wanted = $this->currency($currency);
        $sales = 0;
        $posted = FuelSale::query()
            ->with('payments')
            ->where('shift_id', $shiftId)
            ->where('attendant_id', $attendantId)
            ->where('collected_by', 'attendant')
            ->where('status', FuelSale::POSTED)
            ->get();
        foreach ($posted as $sale) {
            $saleCurrency = $this->currency($sale->currency);
            foreach ($sale->payments as $payment) {
                $paymentCurrency = $this->currency($payment->currency ?: $saleCurrency);
                if ($payment->kind === PaymentMethod::CASH && $paymentCurrency === $wanted) {
                    $sales += (int) $payment->amount_minor;
                }
            }
        }

        $out = (int) $this->settled($shiftId, CashTransfer::ATTENDANT, $attendantId, $wanted)->sum('counted_minor');

        return $sales - $out;
    }

    public function attendantPending(string $shiftId, string $attendantId, ?string $currency): int
    {
        $wanted = $this->currency($currency);

        return (int) CashTransfer::query()
            ->where('shift_id', $shiftId)
            ->where('source_type', CashTransfer::ATTENDANT)
            ->where('source_id', $attendantId)
            ->where('status', CashTransfer::PENDING)
            ->get()
            ->filter(fn (CashTransfer $row) => $this->currency($row->currency) === $wanted)
            ->sum('declared_minor');
    }

    public function safeBalance(Safe $safe, ?string $currency): int
    {
        $wanted = $this->currency($currency);
        $in = $this->safeSide($safe->id, 'destination', $wanted);
        $out = $this->safeSide($safe->id, 'source', $wanted);
        $left = (int) BankDeposit::query()
            ->where('safe_id', $safe->id)
            ->whereIn('status', [BankDeposit::SUBMITTED, BankDeposit::CONFIRMED])
            ->get()
            ->filter(fn (BankDeposit $row) => $this->currency($row->currency) === $wanted)
            ->sum('amount_minor');

        return $in - $out - $left;
    }

    private function openTransfer(string $branchId, ?string $shiftId, string $kind, string $sourceType, string $sourceId, string $destinationType, ?string $destinationId, int $amount, ?string $currency, User $by, string $key, ?string $note): CashTransfer
    {
        return DB::transaction(function () use ($branchId, $shiftId, $kind, $sourceType, $sourceId, $destinationType, $destinationId, $amount, $currency, $by, $key, $note) {
            $existing = CashTransfer::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($amount === 0 || ($kind !== CashTransfer::ADJUSTMENT && $amount < 1)) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount.']);
            }
            app(Currencies::class)->assertAccepted($branchId, $currency);

            $transfer = CashTransfer::query()->create([
                'branch_id' => $branchId,
                'number' => $this->numbers->next('cash_transfer', 'CT'),
                'kind' => $kind,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'destination_type' => $destinationType,
                'destination_id' => $destinationId,
                'shift_id' => $shiftId,
                'currency' => $this->storedCurrency($currency),
                'declared_minor' => $amount,
                'status' => CashTransfer::PENDING,
                'idempotency_key' => $key,
                'initiated_by' => $by->id,
                'notes' => $note,
            ]);
            $this->audit->record($by, 'cash.submitted', $transfer, null, ['declared_minor' => $amount, 'kind' => $kind], $branchId);

            return $transfer;
        });
    }

    private function guardSource(CashTransfer $transfer, int $counted): void
    {
        if ($transfer->source_type === 'adjustment') {
            return;
        }
        $held = match ($transfer->source_type) {
            CashTransfer::ATTENDANT => $this->attendantHeld((string) $transfer->shift_id, $transfer->source_id, $transfer->currency),
            CashTransfer::SAFE => $this->safeBalance(Safe::query()->whereKey($transfer->source_id)->firstOrFail(), $transfer->currency),
            default => $transfer->shift_id === null ? 0 : $this->cash->drawer($transfer->shift_id, $transfer->currency),
        };
        $needed = $transfer->kind === CashTransfer::ADJUSTMENT ? max(0, -$counted) : $counted;
        if ($needed > $held) {
            throw ValidationException::withMessages(['counted' => 'That is more than this custodian holds in that currency.']);
        }
    }

    private function postDrawer(CashTransfer $transfer, int $counted, User $by): void
    {
        if ($transfer->shift_id === null) {
            return;
        }
        $currency = $transfer->currency;
        if ($transfer->kind === CashTransfer::REMITTANCE) {
            $this->cash->move($transfer->branch_id, $transfer->shift_id, CashMovement::REMITTANCE, $counted, $by, 'transfer:'.$transfer->id.':in', $transfer->number, [], $currency);
        }
        if ($transfer->kind === CashTransfer::TO_SAFE || ($transfer->kind === CashTransfer::PAYOUT && $transfer->source_type === CashTransfer::CASHIER_HOLDER)) {
            $kind = $transfer->kind === CashTransfer::PAYOUT ? CashMovement::PAYOUT : CashMovement::TO_SAFE;
            $this->cash->move($transfer->branch_id, $transfer->shift_id, $kind, -$counted, $by, 'transfer:'.$transfer->id.':out', $transfer->number, [], $currency);
        }
        if ($transfer->kind === CashTransfer::ADJUSTMENT) {
            $this->cash->move($transfer->branch_id, $transfer->shift_id, CashMovement::ADJUSTMENT, $counted, $by, 'transfer:'.$transfer->id.':adjustment', $transfer->number, [], $currency);
        }
    }

    /**
     * @return EloquentCollection<int, CashTransfer>
     */
    private function settled(string $shiftId, string $sourceType, string $sourceId, string $wanted): EloquentCollection
    {
        return CashTransfer::query()
            ->where('shift_id', $shiftId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereIn('status', [CashTransfer::CONFIRMED, CashTransfer::DISPUTED])
            ->get()
            ->filter(fn (CashTransfer $row) => $this->currency($row->currency) === $wanted);
    }

    private function safeSide(string $safeId, string $side, string $wanted): int
    {
        $column = $side === 'destination' ? 'destination_id' : 'source_id';
        $type = $side === 'destination' ? 'destination_type' : 'source_type';

        return (int) CashTransfer::query()
            ->where($type, CashTransfer::SAFE)
            ->where($column, $safeId)
            ->whereIn('status', [CashTransfer::CONFIRMED, CashTransfer::DISPUTED])
            ->get()
            ->filter(fn (CashTransfer $row) => $this->currency($row->currency) === $wanted)
            ->sum('counted_minor');
    }

    private function openSafe(Safe $safe, string $branchId): void
    {
        if ($safe->branch_id !== $branchId || $safe->status !== Safe::OPEN) {
            throw ValidationException::withMessages(['safe_id' => 'Choose an open safe at this station.']);
        }
    }

    private function currency(?string $currency): string
    {
        $currency = strtoupper((string) $currency);

        return $currency === '' ? Money::currency() : $currency;
    }

    private function storedCurrency(?string $currency): ?string
    {
        $currency = $this->currency($currency);

        return $currency === Money::currency() ? null : $currency;
    }
}
