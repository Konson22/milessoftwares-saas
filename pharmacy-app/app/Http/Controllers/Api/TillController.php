<?php

namespace App\Http\Controllers\Api;

use App\Modules\Sales\Actions\Tills;
use App\Modules\Sales\Models\TillCashMovement;
use App\Modules\Sales\Models\TillSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The member's own cash shift (till) at the branch: started with the
 * starting cash, closed with a count. Managers close other people's shifts
 * on the web (Tills page).
 */
class TillController extends ApiController
{
    /**
     * GET api/v1/till: the open shift with what went through it, or null.
     */
    public function show(Request $request, Tills $tills): JsonResponse
    {
        $till = $tills->openFor($this->user($request), $this->branch->id());

        return response()->json(['data' => $till === null ? null : $this->present($till, $tills)]);
    }

    /**
     * POST api/v1/till: starts the member's shift.
     */
    public function store(Request $request, Tills $tills): JsonResponse
    {
        $validated = $request->validate(['opening_float_minor' => ['required', 'integer', 'min:0', 'max:999999999999']]);

        $till = $tills->open($this->user($request), $this->branch->id(), (int) $validated['opening_float_minor']);

        return response()->json(['data' => $this->present($till, $tills)], 201);
    }

    /**
     * POST api/v1/till/movements: cash put into or taken out of the
     * member's open shift (from or to the safe). The same submission_id
     * again (the app retrying) records it once; answers the shift.
     */
    public function move(Request $request, Tills $tills): JsonResponse
    {
        $validated = $request->validate([
            'direction' => ['required', 'in:'.TillCashMovement::IN.','.TillCashMovement::OUT],
            // The safe when not said (app versions before the bank and expenses).
            'counterpart' => ['nullable', 'in:'.implode(',', TillCashMovement::COUNTERPARTS)],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'reason' => ['required', 'string', 'max:255'],
            'submission_id' => ['required', 'uuid'],
        ]);

        $till = $tills->openFor($this->user($request), $this->branch->id());
        abort_if($till === null, 404, 'Your shift is not open.');

        $tills->move($till, $this->user($request), $validated['direction'], (int) $validated['amount_minor'], $validated['reason'], $validated['submission_id'], $validated['counterpart'] ?? TillCashMovement::SAFE);

        return response()->json(['data' => $this->present($till, $tills)], 201);
    }

    /**
     * POST api/v1/till/close: counts the drawer and closes the shift.
     */
    public function close(Request $request, Tills $tills): JsonResponse
    {
        $validated = $request->validate([
            'counted_cash_minor' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'closing_note' => ['nullable', 'string', 'max:255'],
        ]);

        $till = $tills->openFor($this->user($request), $this->branch->id());
        abort_if($till === null, 404, 'Your shift is not open.');

        $settings = $tills->settings($this->branch->id());
        $managing = $this->may('tills.manage');
        abort_if($settings->manager_closes_shifts && ! $managing, 403, 'A manager closes shifts at this branch.');

        $closed = $tills->close($till, $this->user($request), (int) $validated['counted_cash_minor'], $validated['closing_note'] ?? null, blind: $settings->blind_close && ! $managing);

        return response()->json(['data' => $this->present($closed, $tills)]);
    }

    /**
     * Counted blind, a cashier sees what they counted, not what the drawer
     * should hold nor the totals that add up to it.
     *
     * @return array<string, mixed>
     */
    private function present(TillSession $till, Tills $tills): array
    {
        $blind = $tills->settings($this->branch->id())->blind_close && ! $this->may('tills.manage');
        $summary = $tills->summary($till);

        return [
            'id' => $till->id,
            'number' => $till->number,
            'status' => $till->status,
            'opened_at' => $till->opened_at->toIso8601String(),
            'closed_at' => $till->closed_at?->toIso8601String(),
            'opening_float_minor' => $till->opening_float_minor,
            'counted_cash_minor' => $till->counted_cash_minor,
            'expected_cash_minor' => $blind ? null : ($till->expected_cash_minor ?? $summary['expectedCashMinor']),
            'difference_minor' => $blind ? null : $till->difference(),
            'closing_note' => $till->closing_note,
            'blind' => $blind,
            'summary' => [
                'sales_count' => $summary['salesCount'],
                'refunds_count' => $summary['refundsCount'],
                'taken_minor' => $blind ? null : (object) $summary['takenMinor'],
                'collected_minor' => $blind ? null : (object) $summary['collectedMinor'],
                'cash_refunds_minor' => $blind ? null : $summary['cashRefundsMinor'],
                'cash_in_minor' => $summary['cashInMinor'],
                'cash_out_minor' => $summary['cashOutMinor'],
            ],
            // Recorded by the member or a manager, so seen even when blind.
            'movements' => $tills->movements($till)->map(fn (TillCashMovement $movement) => [
                'id' => $movement->id,
                'direction' => $movement->direction,
                'counterpart' => $movement->counterpart,
                'label' => $movement->label(),
                'amount_minor' => $movement->amount_minor,
                'reason' => $movement->reason,
                'recorded_by' => $movement->recorder->name,
                'recorded_at' => $movement->created_at->toIso8601String(),
            ])->all(),
        ];
    }
}
