<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\AuditLog;
use App\Models\FuelDraw;
use App\Models\FuelSale;
use App\Models\MeterReading;
use App\Models\Nozzle;
use App\Models\NozzleEvent;
use App\Models\Shift;
use App\Models\ShiftSchedule;
use App\Support\BusinessDate;
use App\Support\Volume;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Meter readings set against what was sold: for each shift and nozzle,
 * the litres the meter moved, the litres posted as sales and drawn for
 * tests, and what is left unexplained. The same rule as the shift
 * reconciliation, an approved meter replacement included.
 */
class ReadingController extends Controller
{
    use WorksInBranch;

    public function index(Request $request): Response
    {
        $branchId = $this->branchId();
        $today = BusinessDate::today();
        $to = $this->day($request->string('to')->toString(), $today);
        $from = $this->day($request->string('from')->toString(), CarbonImmutable::parse($to)->subDays(6)->toDateString());
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 30) {
            $from = CarbonImmutable::parse($to)->subDays(30)->toDateString();
        }
        $nozzleFilter = $request->string('nozzle')->toString();
        $nozzleFilter = Str::isUuid($nozzleFilter) ? $nozzleFilter : '';

        $nozzles = Nozzle::query()->with(['product', 'pump'])->where('branch_id', $branchId)->orderBy('code')->get()->keyBy('id');
        $shifts = Shift::query()
            ->where('branch_id', $branchId)
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->latest('opened_at')
            ->get();
        $ids = $shifts->pluck('id');

        $readings = MeterReading::query()->whereIn('shift_id', $ids)
            ->when($nozzleFilter !== '', fn ($query) => $query->where('nozzle_id', $nozzleFilter))
            ->get(['id', 'shift_id', 'nozzle_id', 'kind', 'reading_milli', 'recorded_at']);
        $sold = FuelSale::query()->whereIn('shift_id', $ids)->where('status', FuelSale::POSTED)
            ->selectRaw('shift_id, nozzle_id, sum(quantity_milli) as milli')
            ->groupBy('shift_id', 'nozzle_id')->get()
            ->mapWithKeys(fn ($row) => [$row->shift_id.'|'.$row->nozzle_id => (int) $row->getAttribute('milli')]);
        $tested = FuelDraw::query()->whereIn('shift_id', $ids)->where('purpose', FuelDraw::TEST)
            ->whereIn('status', [FuelDraw::APPROVED, FuelDraw::RETURNED])
            ->selectRaw('shift_id, nozzle_id, sum(quantity_milli) as milli')
            ->groupBy('shift_id', 'nozzle_id')->get()
            ->mapWithKeys(fn ($row) => [$row->shift_id.'|'.$row->nozzle_id => (int) $row->getAttribute('milli')]);
        $events = NozzleEvent::query()->whereIn('shift_id', $ids)->whereNotNull('approved_at')->get()
            ->keyBy(fn (NozzleEvent $event) => $event->shift_id.'|'.$event->nozzle_id);
        $corrected = AuditLog::query()->where('action', 'meter.corrected')
            ->where('subject_type', (new MeterReading)->getMorphClass())
            ->whereIn('subject_id', $readings->pluck('id'))
            ->pluck('subject_id')->flip();
        $schedules = ShiftSchedule::query()->where('branch_id', $branchId)->pluck('name', 'id');

        $byShift = $readings->groupBy('shift_id');
        $totals = ['metered' => 0, 'sold' => 0, 'tested' => 0, 'variance' => 0, 'missing' => 0, 'corrected' => 0];
        $days = [];
        $rows = [];
        foreach ($shifts as $shift) {
            $lines = [];
            foreach (($byShift[$shift->id] ?? collect())->where('kind', MeterReading::OPENING) as $opening) {
                $closing = $byShift[$shift->id]->first(fn (MeterReading $reading) => $reading->nozzle_id === $opening->nozzle_id && $reading->kind === MeterReading::CLOSING);
                $key = $shift->id.'|'.$opening->nozzle_id;
                $metered = $this->metered($opening, $closing, $events->get($key));
                $soldMilli = $sold[$key] ?? 0;
                $testMilli = $tested[$key] ?? 0;
                $variance = $metered === null ? null : $metered - $soldMilli - $testMilli;
                $wasCorrected = $corrected->has($opening->id) || ($closing !== null && $corrected->has($closing->id));
                $nozzle = $nozzles->get($opening->nozzle_id);

                $lines[] = [
                    'nozzleId' => $opening->nozzle_id,
                    'code' => $nozzle?->code,
                    'nozzle' => $nozzle?->name,
                    'product' => $nozzle?->product?->name,
                    'opening' => Volume::toLitres($opening->reading_milli),
                    'closing' => $closing === null ? null : Volume::toLitres($closing->reading_milli),
                    'metered' => $metered === null ? null : Volume::toLitres($metered),
                    'sold' => Volume::toLitres($soldMilli),
                    'tested' => Volume::toLitres($testMilli),
                    'varianceMilli' => $variance,
                    'corrected' => $wasCorrected,
                    'meterChanged' => $events->has($key),
                ];

                $date = $shift->business_date->toDateString();
                $days[$date] ??= ['date' => $date, 'meteredMilli' => 0, 'soldMilli' => 0, 'varianceMilli' => 0];
                $totals['sold'] += $soldMilli;
                $totals['tested'] += $testMilli;
                $totals['corrected'] += $wasCorrected ? 1 : 0;
                $days[$date]['soldMilli'] += $soldMilli;
                if ($metered === null) {
                    $totals['missing']++;
                } else {
                    $totals['metered'] += $metered;
                    $totals['variance'] += (int) $variance;
                    $days[$date]['meteredMilli'] += $metered;
                    $days[$date]['varianceMilli'] += (int) $variance;
                }
            }
            if ($lines === []) {
                continue;
            }
            usort($lines, fn (array $a, array $b) => strcmp((string) $a['code'], (string) $b['code']));
            $rows[] = [
                'id' => $shift->id,
                'number' => $shift->number,
                'status' => $shift->status,
                'businessDate' => $shift->business_date->toDateString(),
                'schedule' => $shift->schedule_id === null ? null : $schedules->get($shift->schedule_id),
                'openedAt' => $shift->opened_at->toIso8601String(),
                'lines' => $lines,
            ];
        }

        $perPage = 8;
        $current = max(1, (int) $request->integer('page', 1));
        $page = new LengthAwarePaginator(array_slice($rows, ($current - 1) * $perPage, $perPage), count($rows), $perPage, $current, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        // Every nozzle's meter as it stands: its last reading on any shift
        // (a closing beats the opening it ties with: "closing" sorts first).
        $last = MeterReading::query()->whereIn('nozzle_id', $nozzles->keys())
            ->orderByDesc('recorded_at')->orderBy('kind')->get(['nozzle_id', 'reading_milli', 'recorded_at', 'kind'])
            ->unique('nozzle_id')->keyBy('nozzle_id');
        $openShift = Shift::query()->where('branch_id', $branchId)->where('status', Shift::OPEN)->first();
        $sinceOpening = $openShift === null ? collect() : FuelSale::query()->where('shift_id', $openShift->id)->where('status', FuelSale::POSTED)
            ->selectRaw('nozzle_id, sum(quantity_milli) as milli')->groupBy('nozzle_id')->pluck('milli', 'nozzle_id');

        ksort($days);

        return Inertia::render('readings/index', [
            'from' => $from,
            'to' => $to,
            'today' => $today,
            'nozzle' => $nozzleFilter,
            'meters' => $nozzles->values()->map(fn (Nozzle $nozzle) => [
                'id' => $nozzle->id,
                'code' => $nozzle->code,
                'name' => $nozzle->name,
                'product' => $nozzle->product->name,
                'pump' => $nozzle->pump->name,
                'status' => $nozzle->status,
                'reading' => isset($last[$nozzle->id]) ? Volume::toLitres($last[$nozzle->id]->reading_milli) : null,
                'readAt' => isset($last[$nozzle->id]) ? $last[$nozzle->id]->recorded_at->toIso8601String() : null,
                'kind' => $last[$nozzle->id]->kind ?? null,
                'soldThisShift' => $openShift === null ? null : Volume::toLitres((int) ($sinceOpening[$nozzle->id] ?? 0)),
            ]),
            'openShift' => $openShift === null ? null : ['id' => $openShift->id, 'number' => $openShift->number],
            'summary' => [
                'metered' => Volume::toLitres($totals['metered']),
                'sold' => Volume::toLitres($totals['sold']),
                'tested' => Volume::toLitres($totals['tested']),
                'varianceMilli' => $totals['variance'],
                'missing' => $totals['missing'],
                'corrected' => $totals['corrected'],
                'shifts' => count($rows),
            ],
            'days' => array_values($days),
            'shifts' => $page,
        ]);
    }

    /**
     * Litres the meter moved; an approved replacement or reset splits the run.
     */
    private function metered(MeterReading $opening, ?MeterReading $closing, ?NozzleEvent $event): ?int
    {
        if ($closing === null) {
            return null;
        }
        if ($event === null) {
            return $closing->reading_milli - $opening->reading_milli;
        }

        return max(0, $event->previous_milli - $opening->reading_milli) + max(0, $closing->reading_milli - $event->next_milli);
    }

    private function day(string $value, string $fallback): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : $fallback;
    }
}
