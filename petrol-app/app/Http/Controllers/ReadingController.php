<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\MeterReading;
use App\Support\Volume;
use Inertia\Inertia;
use Inertia\Response;

class ReadingController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        return Inertia::render('readings/index', [
            'readings' => MeterReading::query()->with(['nozzle', 'shift'])->whereHas('shift', fn ($query) => $query->where('branch_id', $this->branchId()))->latest('recorded_at')->limit(80)->get()->map(fn (MeterReading $reading) => [
                'id' => $reading->id,
                'shift' => $reading->shift->number,
                'nozzle' => $reading->nozzle->code,
                'kind' => $reading->kind,
                'litres' => Volume::toLitres($reading->reading_milli),
                'at' => $reading->recorded_at->toIso8601String(),
            ]),
        ]);
    }
}
