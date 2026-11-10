<?php

namespace App\Actions;

use App\Audit\Audit;
use App\Enums\CostCategory;
use App\Models\AdvanceRetirement;
use App\Models\AdvanceRetirementLine;
use App\Models\AdvanceSettlement;
use App\Models\Organization;
use App\Models\Project;
use App\Models\StaffAdvance;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\FinanceEvents;
use App\Support\Money as Amounts;
use App\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The rules of staff advances:
 * - Someone asks for an advance for themselves, up to the limit the
 *   organization set; with no limit and no deadline set, nothing is advanced.
 *   Nobody has a new one while one is not yet accounted for.
 * - Whoever holds an advance neither approves it, pays it out, decides its
 *   receipts, records cash they hand back, nor has it written off.
 * - An advance is an asset, not an expense, until receipts are approved.
 *   Finance keeps the books, and is told (finance.* events):
 *     paid out          staff_advance.issued
 *     receipts approved project_expense.paid, from the advance
 *     cash handed back  staff_advance.settled, returned
 *     written off       staff_advance.settled, written off
 * - A receipt charged to a project is that project's spending: approving it
 *   needs an approved budget with enough left in the category.
 * - What is not accounted for stays owed and shows as overdue. Nothing is
 *   taken from pay: this platform has no payroll.
 * Every step runs in a transaction on the locked advance and is audited.
 */
final class StaffAdvances
{
    public const DISK = Spending::DISK;

    public function __construct(
        private readonly ProjectCharge $projectCharge,
        private readonly ProjectBooks $books,
        private readonly FinanceEvents $events,
    ) {}

    /**
     * The organization's policy. Null leaves it unset, so nothing is advanced.
     */
    public function setPolicy(?int $maxMinor, ?int $retireDays, User $by): void
    {
        $books = $this->organization();
        $before = $books->only(['advance_max_minor', 'advance_retire_days']);
        $books->forceFill(['advance_max_minor' => $maxMinor, 'advance_retire_days' => $retireDays])->save();
        Audit::change('advance.policy_changed', $books, $before, ['advance_max_minor' => $maxMinor, 'advance_retire_days' => $retireDays], actor: $by);
    }

    /**
     * @throws ValidationException
     */
    public function request(int $amountMinor, string $purpose, User $by): StaffAdvance
    {
        $books = $this->organization();
        if ($books->advance_max_minor === null || $books->advance_retire_days === null) {
            throw ValidationException::withMessages(['amount' => 'The organization has not set its advance policy: it is set in Administration, Settings.']);
        }
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages(['amount' => 'More than zero.']);
        }
        if ($amountMinor > $books->advance_max_minor) {
            throw ValidationException::withMessages(['amount' => 'The most one advance can be is '.Amounts::format($books->advance_max_minor, $books->currency).'.']);
        }

        return DB::transaction(function () use ($amountMinor, $purpose, $by, $books) {
            // One person at a time, so two requests cannot both pass.
            User::query()->whereKey($by->id)->lockForUpdate()->first();
            $open = StaffAdvance::query()->where('holder_id', $by->id)->whereIn('status', StaffAdvance::OPEN)->first();
            if ($open !== null) {
                throw ValidationException::withMessages(['amount' => "{$open->reference()} is not yet accounted for: finish it first."]);
            }

            Organization::query()->whereKey($books->id)->lockForUpdate()->first();
            /** @var StaffAdvance $advance */
            $advance = StaffAdvance::query()->create([
                'number' => ((int) StaffAdvance::query()->max('number')) + 1,
                'holder_id' => $by->id,
                'amount_minor' => $amountMinor,
                'currency' => $books->currency,
                'purpose' => $purpose,
                'status' => StaffAdvance::REQUESTED,
            ]);
            Audit::record('advance.requested', $advance, ['reference' => $advance->reference(), 'amount_minor' => $amountMinor], actor: $by);
            $this->tell(Alerts::holders('finance.advances.approve'), UserNotification::APPROVAL, "{$by->name} asks for an advance", $advance, $by);

            return $advance;
        });
    }

    /**
     * @throws ValidationException
     */
    public function cancel(StaffAdvance $advance, User $by): void
    {
        DB::transaction(function () use ($advance, $by) {
            $locked = $this->lock($advance);
            $this->requireStatus($locked, [StaffAdvance::REQUESTED, StaffAdvance::APPROVED]);
            $locked->forceFill(['status' => StaffAdvance::CANCELLED])->save();
            Audit::record('advance.cancelled', $locked, ['reference' => $locked->reference()], actor: $by);
        });
    }

    /**
     * @throws ValidationException
     */
    public function approve(StaffAdvance $advance, ?string $note, User $by): void
    {
        DB::transaction(function () use ($advance, $note, $by) {
            $locked = $this->lock($advance);
            $this->requireStatus($locked, [StaffAdvance::REQUESTED]);
            $this->requireNotHolder($locked, $by, 'approves it');

            $locked->forceFill(['status' => StaffAdvance::APPROVED, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            Audit::record('advance.approved', $locked, ['reference' => $locked->reference(), 'amount_minor' => $locked->amount_minor], actor: $by);
            $this->tell([$locked->holder], UserNotification::DECISION, "{$by->name} approved {$locked->reference()}", $locked, $by);
            $this->tell(Alerts::holders('finance.advances.disburse'), UserNotification::APPROVAL, "{$locked->reference()} is approved and ready to pay out", $locked, $by);
        });
    }

    /**
     * @throws ValidationException
     */
    public function reject(StaffAdvance $advance, string $reason, User $by): void
    {
        DB::transaction(function () use ($advance, $reason, $by) {
            $locked = $this->lock($advance);
            $this->requireStatus($locked, [StaffAdvance::REQUESTED]);
            $this->requireNotHolder($locked, $by, 'decides it');

            $locked->forceFill(['status' => StaffAdvance::REJECTED, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $reason])->save();
            Audit::record('advance.rejected', $locked, ['reference' => $locked->reference(), 'reason' => $reason], actor: $by);
            $this->tell([$locked->holder], UserNotification::DECISION, "{$by->name} rejected {$locked->reference()}: {$reason}", $locked, $by);
        });
    }

    /**
     * Pays it out, and starts the time allowed to account for it.
     *
     * @throws ValidationException
     */
    public function disburse(StaffAdvance $advance, string $method, CarbonImmutable $on, ?string $reference, User $by): void
    {
        $this->requireMethod($method);

        DB::transaction(function () use ($advance, $method, $on, $reference, $by) {
            $locked = $this->lock($advance);
            $this->requireStatus($locked, [StaffAdvance::APPROVED]);
            $this->requireNotHolder($locked, $by, 'pays it out');

            $days = $this->organization()->advance_retire_days;
            if ($days === null) {
                throw ValidationException::withMessages(['advance' => 'The organization has not set how long staff have to account for an advance.']);
            }

            $locked->forceFill([
                'status' => StaffAdvance::DISBURSED,
                'disbursed_by' => $by->id,
                'disbursed_on' => $on->toDateString(),
                'reference' => $reference,
                'payment_method' => $method,
                'due_on' => $on->addDays($days)->toDateString(),
            ])->save();
            $this->events->send('finance.staff_advance.issued', "advance:{$locked->id}:issued", [
                'advance_reference' => self::reference($locked),
                'issued_on' => $on->toDateString(),
                'currency' => $locked->currency,
                'amount_minor' => $locked->amount_minor,
                'method' => $method,
                'held_by' => $locked->holder->name,
                'purpose' => mb_substr($locked->purpose, 0, 255),
            ], $on);
            Audit::record('advance.disbursed', $locked, ['reference' => $locked->reference(), 'amount_minor' => $locked->amount_minor, 'method' => $method, 'due_on' => $locked->due_on?->toDateString()], actor: $by);
            $this->tell([$locked->holder], UserNotification::DECISION, "{$locked->reference()} is paid: account for it by {$locked->due_on?->toDateString()}", $locked, $by);
        });
    }

    /**
     * Adds a receipt to the draft of the retirement, starting one if needed.
     *
     * @param  array{spent_on: CarbonImmutable, amount_minor: int, description: string, payee: string|null, project: Project|null, category: CostCategory|null}  $details
     *
     * @throws ValidationException
     */
    public function addLine(StaffAdvance $advance, array $details, UploadedFile $receipt, User $by): AdvanceRetirementLine
    {
        $path = $receipt->store("organizations/{$advance->organization_id}/advances/{$advance->id}", self::DISK);
        if ($path === false) {
            throw ValidationException::withMessages(['receipt' => 'The receipt could not be stored.']);
        }

        try {
            return DB::transaction(function () use ($advance, $details, $receipt, $path, $by) {
                $locked = $this->lock($advance);
                $this->requireStatus($locked, [StaffAdvance::DISBURSED]);
                $this->requireHolder($locked, $by);
                if ($details['amount_minor'] <= 0) {
                    throw ValidationException::withMessages(['amount' => 'More than zero.']);
                }
                if ($details['category'] === null) {
                    throw ValidationException::withMessages(['category' => 'Choose what it was spent on.']);
                }
                if ($details['category'] === CostCategory::Labour) {
                    throw ValidationException::withMessages(['category' => 'Labour comes from time logged.']);
                }
                if ($locked->retirements()->where('status', AdvanceRetirement::SUBMITTED)->exists()) {
                    throw ValidationException::withMessages(['amount' => 'Receipts are waiting for a decision: wait for it.']);
                }

                /** @var AdvanceRetirement $draft */
                $draft = $locked->retirements()->firstOrCreate(['status' => AdvanceRetirement::DRAFT]);
                $left = $this->outstanding($locked) - (int) $draft->lines()->sum('amount_minor') - $this->writeOffWaiting($locked);
                if ($details['amount_minor'] > $left) {
                    throw ValidationException::withMessages(['amount' => 'Only '.Amounts::format(max(0, $left), $locked->currency).' of this advance is left to account for.']);
                }

                /** @var AdvanceRetirementLine $line */
                $line = $draft->lines()->create([
                    'spent_on' => $details['spent_on']->toDateString(),
                    'amount_minor' => $details['amount_minor'],
                    'description' => $details['description'],
                    'payee' => $details['payee'],
                    'project_id' => $details['project']?->id,
                    'category' => $details['category'],
                    'receipt_name' => mb_substr($receipt->getClientOriginalName(), 0, 255),
                    'receipt_path' => $path,
                    'receipt_mime' => $receipt->getMimeType() ?? 'application/octet-stream',
                    'receipt_size' => (int) $receipt->getSize(),
                ]);

                return $line;
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($path);

            throw $e;
        }
    }

    /**
     * A receipt comes off only while its retirement is a draft.
     *
     * @throws ValidationException
     */
    public function removeLine(AdvanceRetirementLine $line, User $by): void
    {
        DB::transaction(function () use ($line, $by) {
            $locked = $this->lock($line->retirement->advance);
            $this->requireHolder($locked, $by);
            $retirement = $line->retirement->refresh();
            if ($retirement->status !== AdvanceRetirement::DRAFT) {
                throw ValidationException::withMessages(['retirement' => 'Only a draft changes.']);
            }

            $line->delete();
            Storage::disk(self::DISK)->delete($line->receipt_path);
        });
    }

    /**
     * @throws ValidationException
     */
    public function submitRetirement(StaffAdvance $advance, User $by): void
    {
        DB::transaction(function () use ($advance, $by) {
            $locked = $this->lock($advance);
            $this->requireStatus($locked, [StaffAdvance::DISBURSED]);
            $this->requireHolder($locked, $by);

            $draft = $locked->retirements()->where('status', AdvanceRetirement::DRAFT)->first();
            if ($draft === null || ! $draft->lines()->exists()) {
                throw ValidationException::withMessages(['retirement' => 'Add the receipts first.']);
            }
            $left = $this->outstanding($locked) - $this->writeOffWaiting($locked);
            if ((int) $draft->lines()->sum('amount_minor') > $left) {
                throw ValidationException::withMessages(['retirement' => 'These receipts come to more than the '.Amounts::format(max(0, $left), $locked->currency).' still to account for: remove some.']);
            }

            $draft->forceFill(['status' => AdvanceRetirement::SUBMITTED, 'submitted_at' => now(), 'returned_reason' => null])->save();
            Audit::record('advance.retirement_submitted', $draft, ['reference' => $locked->reference(), 'amount_minor' => (int) $draft->lines()->sum('amount_minor')], actor: $by);
            $this->tell(Alerts::holders('finance.advances.approve'), UserNotification::APPROVAL, "{$by->name} sent receipts for {$locked->reference()}", $locked, $by);
        });
    }

    /**
     * The receipts become expenses here, and not before.
     *
     * @throws ValidationException
     */
    public function approveRetirement(AdvanceRetirement $retirement, User $by): void
    {
        DB::transaction(function () use ($retirement, $by) {
            $locked = $this->lock($retirement->advance);
            $this->requireStatus($locked, [StaffAdvance::DISBURSED]);
            $this->requireNotHolder($locked, $by, 'decides its receipts');
            $retirement->refresh();
            if ($retirement->status !== AdvanceRetirement::SUBMITTED) {
                throw ValidationException::withMessages(['retirement' => 'These receipts have already been decided.']);
            }

            $total = 0;
            foreach ($retirement->lines()->with('project')->get() as $line) {
                $category = $line->category ?? CostCategory::Other;
                if ($line->project !== null) {
                    $this->projectCharge->charge($line->project, $category, [
                        'description' => $line->description,
                        'payee' => $line->payee,
                        'amount_minor' => $line->amount_minor,
                        'spent_on' => $line->spent_on,
                        'currency' => $locked->currency,
                        'reference' => $locked->reference(),
                        'receipt' => ['name' => $line->receipt_name, 'path' => $line->receipt_path, 'mime' => $line->receipt_mime, 'size' => $line->receipt_size],
                    ], $by, fn (string $expenseId) => $line->forceFill(['expense_id' => $expenseId])->save(), 'retirement');
                }
                $this->books->spent("advance-receipt:{$line->id}", [
                    'paid_on' => $line->spent_on,
                    'currency' => $locked->currency,
                    'amount_minor' => $line->amount_minor,
                    'description' => "{$locked->reference()}: {$line->description}",
                    'category' => $category,
                    'method' => ProjectBooks::STAFF_ADVANCE,
                    'supplier' => $line->payee,
                    'payment_reference' => self::reference($locked),
                    'project' => $line->project,
                ]);
                $total += $line->amount_minor;
            }

            $retirement->forceFill(['status' => AdvanceRetirement::APPROVED, 'decided_by' => $by->id, 'decided_at' => now()])->save();
            Audit::record('advance.retirement_approved', $retirement, ['reference' => $locked->reference(), 'amount_minor' => $total], actor: $by);
            $this->settleIfDone($locked);
            $this->tell([$locked->holder], UserNotification::DECISION, "{$by->name} approved your receipts for {$locked->reference()}", $locked, $by);
        });
    }

    /**
     * Back to a draft, so the holder can correct it.
     *
     * @throws ValidationException
     */
    public function returnRetirement(AdvanceRetirement $retirement, string $reason, User $by): void
    {
        DB::transaction(function () use ($retirement, $reason, $by) {
            $locked = $this->lock($retirement->advance);
            $this->requireNotHolder($locked, $by, 'decides its receipts');
            $retirement->refresh();
            if ($retirement->status !== AdvanceRetirement::SUBMITTED) {
                throw ValidationException::withMessages(['retirement' => 'These receipts have already been decided.']);
            }

            $retirement->forceFill(['status' => AdvanceRetirement::DRAFT, 'submitted_at' => null, 'returned_reason' => $reason])->save();
            Audit::record('advance.retirement_returned', $retirement, ['reference' => $locked->reference(), 'reason' => $reason], actor: $by);
            $this->tell([$locked->holder], UserNotification::DECISION, "{$by->name} returned your receipts for {$locked->reference()}: {$reason}", $locked, $by);
        });
    }

    /**
     * Cash handed back by the holder, received by someone else.
     *
     * @throws ValidationException
     */
    public function recordReturn(StaffAdvance $advance, int $amountMinor, string $method, CarbonImmutable $on, ?string $note, User $by): void
    {
        $this->requireMethod($method);

        DB::transaction(function () use ($advance, $amountMinor, $method, $on, $note, $by) {
            $locked = $this->lock($advance);
            $this->requireStatus($locked, [StaffAdvance::DISBURSED]);
            $this->requireNotHolder($locked, $by, 'records the cash they hand back');
            $this->requireAvailable($locked, $amountMinor);

            /** @var AdvanceSettlement $settlement */
            $settlement = $locked->settlements()->create([
                'type' => AdvanceSettlement::RETURN,
                'status' => AdvanceSettlement::APPROVED,
                'amount_minor' => $amountMinor,
                'on' => $on->toDateString(),
                'reason' => $note,
                'payment_method' => $method,
                'recorded_by' => $by->id,
            ]);
            $this->settled($locked, $settlement, 'returned', $method, $on);
            Audit::record('advance.cash_returned', $settlement, ['reference' => $locked->reference(), 'amount_minor' => $amountMinor, 'method' => $method], actor: $by);
            $this->settleIfDone($locked);
        });
    }

    /**
     * Asks to write off what will not be recovered. A second person decides.
     *
     * @throws ValidationException
     */
    public function requestWriteOff(StaffAdvance $advance, int $amountMinor, string $reason, User $by): AdvanceSettlement
    {
        return DB::transaction(function () use ($advance, $amountMinor, $reason, $by) {
            $locked = $this->lock($advance);
            $this->requireStatus($locked, [StaffAdvance::DISBURSED]);
            $this->requireNotHolder($locked, $by, 'asks to write it off');
            if ($locked->settlements()->where('status', AdvanceSettlement::SUBMITTED)->exists()) {
                throw ValidationException::withMessages(['amount' => 'A write-off is already waiting for a decision.']);
            }
            $this->requireAvailable($locked, $amountMinor);

            /** @var AdvanceSettlement $settlement */
            $settlement = $locked->settlements()->create([
                'type' => AdvanceSettlement::WRITE_OFF,
                'status' => AdvanceSettlement::SUBMITTED,
                'amount_minor' => $amountMinor,
                'on' => CarbonImmutable::today()->toDateString(),
                'reason' => $reason,
                'recorded_by' => $by->id,
            ]);
            Audit::record('advance.write_off_requested', $settlement, ['reference' => $locked->reference(), 'amount_minor' => $amountMinor, 'reason' => $reason], actor: $by);
            $this->tell(Alerts::holders('finance.advances.approve'), UserNotification::APPROVAL, "{$by->name} asks to write off part of {$locked->reference()}", $locked, $by);

            return $settlement;
        });
    }

    /**
     * @throws ValidationException
     */
    public function approveWriteOff(AdvanceSettlement $settlement, CarbonImmutable $on, User $by): void
    {
        DB::transaction(function () use ($settlement, $on, $by) {
            $locked = $this->lock(StaffAdvance::query()->findOrFail($settlement->advance_id));
            $this->requireWriteOffDecidable($locked, $settlement, $by);

            $settlement->forceFill([
                'status' => AdvanceSettlement::APPROVED,
                'on' => $on->toDateString(),
                'decided_by' => $by->id,
                'decided_at' => now(),
            ])->save();
            $this->settled($locked, $settlement, 'written_off', null, $on);
            Audit::record('advance.written_off', $settlement, ['reference' => $locked->reference(), 'amount_minor' => $settlement->amount_minor, 'requested_by' => $settlement->recorded_by], actor: $by);
            $this->settleIfDone($locked);
        });
    }

    /**
     * @throws ValidationException
     */
    public function rejectWriteOff(AdvanceSettlement $settlement, string $reason, User $by): void
    {
        DB::transaction(function () use ($settlement, $reason, $by) {
            $locked = $this->lock(StaffAdvance::query()->findOrFail($settlement->advance_id));
            $this->requireWriteOffDecidable($locked, $settlement, $by);

            $settlement->forceFill(['status' => AdvanceSettlement::REJECTED, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $reason])->save();
            Audit::record('advance.write_off_rejected', $settlement, ['reference' => $locked->reference(), 'reason' => $reason], actor: $by);
        });
    }

    /**
     * What is not yet accounted for: paid out, less receipts approved, cash
     * returned and amounts written off.
     */
    public function outstanding(StaffAdvance $advance): int
    {
        if (! in_array($advance->status, [StaffAdvance::DISBURSED, StaffAdvance::SETTLED], true)) {
            return 0;
        }

        $retired = (int) AdvanceRetirementLine::query()
            ->whereIn('retirement_id', $advance->retirements()->where('status', AdvanceRetirement::APPROVED)->select('id'))
            ->sum('amount_minor');
        $settled = (int) $advance->settlements()->where('status', AdvanceSettlement::APPROVED)->sum('amount_minor');

        return $advance->amount_minor - $retired - $settled;
    }

    /**
     * The advance as Finance knows it.
     */
    public static function reference(StaffAdvance $advance): string
    {
        return "advance:{$advance->id}";
    }

    /**
     * @throws ValidationException
     */
    private function requireWriteOffDecidable(StaffAdvance $advance, AdvanceSettlement $settlement, User $by): void
    {
        $settlement->refresh();
        if ($settlement->type !== AdvanceSettlement::WRITE_OFF || $settlement->status !== AdvanceSettlement::SUBMITTED) {
            throw ValidationException::withMessages(['settlement' => 'This has already been decided.']);
        }
        if ($settlement->recorded_by === $by->id) {
            throw ValidationException::withMessages(['settlement' => 'Someone other than whoever asked decides a write-off.']);
        }
        $this->requireNotHolder($advance, $by, 'decides its write-off');
        $this->requireStatus($advance, [StaffAdvance::DISBURSED]);
    }

    /**
     * Amount must fit what is left, after any receipts or write-off waiting.
     *
     * @throws ValidationException
     */
    private function requireAvailable(StaffAdvance $advance, int $amountMinor): void
    {
        if ($amountMinor <= 0) {
            throw ValidationException::withMessages(['amount' => 'More than zero.']);
        }

        $waiting = (int) AdvanceRetirementLine::query()
            ->whereIn('retirement_id', $advance->retirements()->where('status', AdvanceRetirement::SUBMITTED)->select('id'))
            ->sum('amount_minor')
            + $this->writeOffWaiting($advance);
        $left = $this->outstanding($advance) - $waiting;
        if ($amountMinor > $left) {
            throw ValidationException::withMessages(['amount' => 'Only '.Amounts::format(max(0, $left), $advance->currency).' is left of this advance.']);
        }
    }

    private function writeOffWaiting(StaffAdvance $advance): int
    {
        return (int) $advance->settlements()->where('status', AdvanceSettlement::SUBMITTED)->sum('amount_minor');
    }

    private function settleIfDone(StaffAdvance $advance): void
    {
        if ($this->outstanding($advance) === 0) {
            $advance->forceFill(['status' => StaffAdvance::SETTLED, 'settled_at' => now()])->save();
        }
    }

    private function settled(StaffAdvance $advance, AdvanceSettlement $settlement, string $kind, ?string $method, CarbonImmutable $on): void
    {
        $this->events->send('finance.staff_advance.settled', "advance-settlement:{$settlement->id}", [
            'reference' => "advance-settlement:{$settlement->id}",
            'advance_reference' => self::reference($advance),
            'settled_on' => $on->toDateString(),
            'currency' => $advance->currency,
            'amount_minor' => $settlement->amount_minor,
            'kind' => $kind,
            'method' => $method,
            'note' => $settlement->reason === null ? null : mb_substr($settlement->reason, 0, 255),
        ], $on);
    }

    private function organization(): Organization
    {
        return app(CurrentOrganization::class)->require();
    }

    /**
     * @param  iterable<User>  $users
     */
    private function tell(iterable $users, string $kind, string $title, StaffAdvance $advance, User $by): void
    {
        Alerts::tell($users, $kind, $title, $advance->purpose, route('advances.show', $advance, false), $by);
    }

    /**
     * @param  list<string>  $from
     *
     * @throws ValidationException
     */
    private function requireStatus(StaffAdvance $advance, array $from): void
    {
        if (! in_array($advance->status, $from, true)) {
            throw ValidationException::withMessages(['advance' => "{$advance->reference()} is {$advance->status}: that step no longer applies."]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function requireHolder(StaffAdvance $advance, User $by): void
    {
        if ($advance->holder_id !== $by->id) {
            throw ValidationException::withMessages(['advance' => 'Only whoever holds an advance accounts for it.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function requireNotHolder(StaffAdvance $advance, User $by, string $step): void
    {
        if ($advance->holder_id === $by->id) {
            throw ValidationException::withMessages(['advance' => "Someone other than whoever holds an advance {$step}."]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function requireMethod(string $method): void
    {
        if (! in_array($method, ProjectBooks::METHODS, true)) {
            throw ValidationException::withMessages(['payment_method' => 'Choose how the money moved.']);
        }
    }

    private function lock(StaffAdvance $advance): StaffAdvance
    {
        return StaffAdvance::query()->lockForUpdate()->whereKey($advance->id)->firstOrFail();
    }
}
