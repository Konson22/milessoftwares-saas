<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\Shift;
use App\Petrol\Reconciliation;
use App\Support\Volume;
use Inertia\Inertia;
use Inertia\Response;

class ReconciliationController extends Controller
{
    use WorksInBranch;

    public function index(Reconciliation $reconciliation): Response
    {
        $shifts = Shift::query()->where('branch_id', $this->branchId())->whereIn('status', [Shift::CLOSING_SUBMITTED, Shift::UNDER_REVIEW, Shift::APPROVED])->latest('opened_at')->limit(20)->get();

        return Inertia::render('reconciliation/index', [
            'shifts' => $shifts->map(function (Shift $shift) use ($reconciliation) {
                $detail = $reconciliation->detail($shift);

                return [
                    'id' => $shift->id,
                    'number' => $shift->number,
                    'status' => $shift->status,
                    'soldLitres' => Volume::toLitres($detail['sold_milli']),
                    'salesMinor' => $detail['sales_minor'],
                    'expectedMinor' => $detail['expected_minor'],
                    'differenceMinor' => $detail['difference_minor'],
                ];
            }),
        ]);
    }
}
