<?php

namespace App\Procurement;

use App\Models\User;
use App\Models\Workspace;
use App\Procurement\Models\PlanItem;
use App\Procurement\Models\PurchaseOrder;
use App\Procurement\Models\Requisition;
use App\Procurement\Models\RequisitionLine;
use App\Procurement\Models\SourcingEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase requisitions: draft → pending_approval → approved / rejected /
 * returned; cancelled; amended (approved → draft, new revision) while no
 * sourcing or order uses it; closed when its buying is done.
 */
final class Requisitions
{
    /**
     * Creates or changes a draft (or returned) requisition, lines included.
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(?Requisition $requisition, array $data, User $by): Requisition
    {
        if ($requisition !== null) {
            if (! $requisition->editable()) {
                throw ValidationException::withMessages(['requisition' => 'Only a draft or returned requisition can be changed.']);
            }
            if ($requisition->requester_id !== $by->id) {
                throw ValidationException::withMessages(['requisition' => 'Only the requester changes their requisition.']);
            }
        }

        return DB::transaction(function () use ($requisition, $data, $by) {
            $currency = strtoupper((string) ($data['currency'] ?? Money::base()));
            $rate = Money::rate($currency);
            $planItemId = self::planItem($data['plan_item_id'] ?? null);

            $model = $requisition ?? new Requisition([
                'number' => Numbers::next('requisition'),
                'requester_id' => $by->id,
                'status' => 'draft',
            ]);
            $model->fill([
                'title' => $data['title'],
                'justification' => $data['justification'],
                'department_id' => $data['department_id'] ?? null,
                'department_name' => Departments::name($data['department_id'] ?? null),
                'plan_item_id' => $planItemId,
                'needed_by' => $data['needed_by'] ?? null,
                'delivery_location' => $data['delivery_location'] ?? null,
                'budget_reference' => $data['budget_reference'] ?? null,
                'project_reference' => $data['project_reference'] ?? null,
                'funding_reference' => $data['funding_reference'] ?? null,
                'currency' => $currency,
                'rate_minor' => $rate,
                'total_minor' => 0,
                'total_base_minor' => 0,
            ])->save();

            $model->lines()->delete();
            $total = 0;
            foreach (array_values((array) $data['lines']) as $i => $line) {
                $price = Money::parse($line['unit_price'], $currency, "lines.{$i}.unit_price");
                $amount = Money::lineAmount((string) $line['quantity'], $price);
                $total += $amount;
                RequisitionLine::query()->create([
                    'requisition_id' => $model->id,
                    'line_no' => $i + 1,
                    'kind' => $line['kind'] ?? 'goods',
                    'description' => $line['description'],
                    'specification' => $line['specification'] ?? null,
                    'quantity' => Money::quantity($line['quantity']),
                    'unit' => $line['unit'] ?? null,
                    'unit_price_minor' => $price,
                    'amount_minor' => $amount,
                ]);
            }
            $model->forceFill(['total_minor' => $total, 'total_base_minor' => Money::toBase($total, $currency, $rate)])->save();

            AuditTrail::record($requisition === null ? 'requisition.created' : 'requisition.updated', $model, $by, [
                'total' => Money::format($total, $currency),
            ]);

            return $model;
        });
    }

    public static function submit(Requisition $requisition, User $by): void
    {
        DB::transaction(function () use ($requisition, $by) {
            $requisition = Requisition::query()->lockForUpdate()->findOrFail($requisition->id);
            if (! $requisition->editable()) {
                throw ValidationException::withMessages(['requisition' => 'This requisition has been submitted already.']);
            }
            if ($requisition->requester_id !== $by->id) {
                throw ValidationException::withMessages(['requisition' => 'Only the requester submits their requisition.']);
            }
            if (! $requisition->lines()->exists() || $requisition->total_minor <= 0) {
                throw ValidationException::withMessages(['lines' => 'Add what is needed, with an estimated price.']);
            }
            if (Workspace::settings()->require_plan && $requisition->plan_item_id === null) {
                throw ValidationException::withMessages(['plan_item_id' => 'Name the procurement plan item this purchase was planned under.']);
            }
            if ($requisition->plan_item_id !== null) {
                self::planItem($requisition->plan_item_id);
            }

            $needsApproval = Approvals::start('requisition', $requisition, $requisition->total_base_minor, $requisition->department_id, null, $by);
            $requisition->forceFill([
                'status' => $needsApproval ? 'pending_approval' : 'approved',
                'submitted_at' => now(),
                'approved_at' => $needsApproval ? null : now(),
                'status_reason' => null,
            ])->save();
            AuditTrail::record('requisition.submitted', $requisition, $by, ['revision' => $requisition->revision]);
        });
    }

    public static function decide(Requisition $requisition, string $decision, ?string $note, User $by): string
    {
        return DB::transaction(function () use ($requisition, $decision, $note, $by) {
            $requisition = Requisition::query()->lockForUpdate()->findOrFail($requisition->id);
            if ($requisition->status !== 'pending_approval') {
                throw ValidationException::withMessages(['decision' => 'This requisition is not waiting for approval.']);
            }

            $outcome = Approvals::decide('requisition', $requisition, $by, $decision, $note);
            match ($outcome) {
                'complete' => $requisition->forceFill(['status' => 'approved', 'approved_at' => now()])->save(),
                'rejected' => $requisition->forceFill(['status' => 'rejected', 'status_reason' => $note])->save(),
                'returned' => $requisition->forceFill(['status' => 'returned', 'status_reason' => $note])->save(),
                default => null,
            };
            AuditTrail::record('requisition.'.match ($outcome) {
                'complete' => 'approved',
                'pending' => 'step_approved',
                default => $outcome,
            }, $requisition, $by, ['note' => $note]);

            return $outcome;
        });
    }

    public static function cancel(Requisition $requisition, string $reason, User $by): void
    {
        DB::transaction(function () use ($requisition, $reason, $by) {
            $requisition = Requisition::query()->lockForUpdate()->findOrFail($requisition->id);
            if (! in_array($requisition->status, ['draft', 'returned', 'pending_approval', 'approved'], true)) {
                throw ValidationException::withMessages(['requisition' => 'This requisition can no longer be cancelled.']);
            }
            self::requireUnused($requisition);

            Approvals::withdraw('requisition', $requisition);
            $requisition->forceFill(['status' => 'cancelled', 'status_reason' => $reason])->save();
            AuditTrail::record('requisition.cancelled', $requisition, $by, ['reason' => $reason]);
        });
    }

    /**
     * A controlled amendment: an approved requisition nothing has used yet
     * goes back to draft as a new revision and needs approval again.
     */
    public static function amend(Requisition $requisition, string $reason, User $by): void
    {
        DB::transaction(function () use ($requisition, $reason, $by) {
            $requisition = Requisition::query()->lockForUpdate()->findOrFail($requisition->id);
            if ($requisition->status !== 'approved') {
                throw ValidationException::withMessages(['requisition' => 'Only an approved requisition is amended; a draft is simply changed.']);
            }
            if ($requisition->requester_id !== $by->id) {
                throw ValidationException::withMessages(['requisition' => 'Only the requester amends their requisition.']);
            }
            self::requireUnused($requisition);

            $requisition->forceFill(['status' => 'draft', 'revision' => $requisition->revision + 1, 'approved_at' => null, 'status_reason' => $reason])->save();
            AuditTrail::record('requisition.amended', $requisition, $by, ['reason' => $reason, 'revision' => $requisition->revision]);
        });
    }

    /**
     * Closes the requisition once every order placed for it is closed or
     * cancelled and at least one was closed. Called when an order closes.
     */
    public static function closeIfDone(?string $requisitionId, User $by): void
    {
        if ($requisitionId === null) {
            return;
        }
        $requisition = Requisition::query()->lockForUpdate()->find($requisitionId);
        if ($requisition === null || $requisition->status !== 'approved') {
            return;
        }

        $statuses = $requisition->purchaseOrders()->pluck('status');
        if ($statuses->contains('closed') && $statuses->every(fn ($s) => in_array($s, ['closed', 'cancelled'], true))) {
            $requisition->forceFill(['status' => 'closed'])->save();
            AuditTrail::record('requisition.closed', $requisition, $by);
        }
    }

    /**
     * An approved requisition free for sourcing or a direct order.
     */
    public static function requireBuyable(Requisition $requisition): void
    {
        if ($requisition->status !== 'approved') {
            throw ValidationException::withMessages(['requisition_id' => 'Only an approved requisition is bought against.']);
        }
        self::requireUnused($requisition, 'requisition_id');
    }

    private static function requireUnused(Requisition $requisition, string $field = 'requisition'): void
    {
        $sourcing = SourcingEvent::query()->where('requisition_id', $requisition->id)->where('status', '!=', 'cancelled')->value('number');
        $order = PurchaseOrder::query()->where('requisition_id', $requisition->id)->where('status', '!=', 'cancelled')->value('number');
        if ($sourcing !== null || $order !== null) {
            throw ValidationException::withMessages([$field => 'Already being bought under '.($sourcing ?? $order).'. Cancel that first.']);
        }
    }

    private static function planItem(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        $item = PlanItem::query()->with('plan')->find($id);
        if ($item === null || $item->plan->status !== 'approved') {
            throw ValidationException::withMessages(['plan_item_id' => 'Choose an item of an approved procurement plan.']);
        }

        return $item->id;
    }
}
