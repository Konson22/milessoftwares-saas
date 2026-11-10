<?php

namespace App\Petrol;

use App\Models\Tank;
use App\Models\TankChart;
use App\Models\TankChartPoint;
use App\Models\User;
use App\Support\Volume;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Depth-to-volume charts. Conversion is piecewise linear between the points
 * the station supplied. A new version leaves older measurements unchanged.
 */
class Calibration
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  list<array{depth_mm: int, litres: string}>  $points
     */
    public function publish(Tank $tank, string $reference, string $effectiveFrom, ?string $provider, ?string $document, array $points, User $by): TankChart
    {
        $parsed = $this->validated($points);

        return DB::transaction(function () use ($tank, $reference, $effectiveFrom, $provider, $document, $parsed, $by) {
            $version = (int) TankChart::query()->where('tank_id', $tank->id)->max('version') + 1;
            TankChart::query()->where('tank_id', $tank->id)->where('status', TankChart::ACTIVE)->update(['status' => TankChart::RETIRED]);
            $chart = TankChart::query()->create([
                'branch_id' => $tank->branch_id,
                'tank_id' => $tank->id,
                'reference' => $reference,
                'version' => $version,
                'depth_unit' => 'mm',
                'volume_unit' => 'L',
                'effective_from' => $effectiveFrom,
                'provider' => $provider,
                'document_note' => $document,
                'status' => TankChart::ACTIVE,
                'created_by' => $by->id,
            ]);
            foreach ($parsed as $point) {
                TankChartPoint::query()->create([
                    'tank_id' => $tank->id,
                    'chart_id' => $chart->id,
                    'depth_mm' => $point['depth_mm'],
                    'volume_milli' => $point['volume_milli'],
                ]);
            }
            $this->audit->record($by, 'tank.chart', $chart, null, ['version' => $version, 'points' => count($parsed)], $tank->branch_id);

            return $chart;
        });
    }

    public function volumeAt(TankChart $chart, int $depthMm): int
    {
        $points = $chart->points()->orderBy('depth_mm')->get();
        if ($points->count() < 2) {
            throw ValidationException::withMessages(['depth' => 'This chart does not have enough points.']);
        }
        $lower = null;
        foreach ($points as $point) {
            if ($point->depth_mm === $depthMm) {
                return (int) $point->volume_milli;
            }
            if ($point->depth_mm < $depthMm) {
                $lower = $point;

                continue;
            }
            if ($lower === null) {
                break;
            }
            $span = (int) $point->depth_mm - (int) $lower->depth_mm;
            $rise = (int) $point->volume_milli - (int) $lower->volume_milli;

            return (int) $lower->volume_milli + (int) intdiv($rise * ($depthMm - (int) $lower->depth_mm) + intdiv($span, 2), $span);
        }

        throw ValidationException::withMessages(['depth' => 'That depth is outside this calibration chart.']);
    }

    /**
     * @return list<array{depth_mm: int, litres: string}>
     */
    public function pointsFromCsv(string $csv): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\n|\r/', trim($csv)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with(strtolower($line), 'depth')) {
                continue;
            }
            $parts = str_getcsv($line);
            if (count($parts) < 2 || ! ctype_digit(trim($parts[0]))) {
                throw ValidationException::withMessages(['csv' => 'Each row needs a depth in millimetres and a volume in litres.']);
            }
            $rows[] = ['depth_mm' => (int) $parts[0], 'litres' => trim($parts[1])];
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['csv' => 'The chart has no points.']);
        }

        return $rows;
    }

    /**
     * @param  list<array{depth_mm: int, litres: string}>  $points
     * @return list<array{depth_mm: int, volume_milli: int}>
     */
    private function validated(array $points): array
    {
        if (count($points) < 2) {
            throw ValidationException::withMessages(['points' => 'A chart needs at least two points.']);
        }
        $parsed = [];
        $lastDepth = null;
        $lastVolume = null;
        foreach ($points as $point) {
            $depth = (int) $point['depth_mm'];
            try {
                $volume = Volume::toMilli((string) $point['litres']);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['points' => $e->getMessage()]);
            }
            if ($depth < 0 || ($lastDepth !== null && $depth <= $lastDepth)) {
                throw ValidationException::withMessages(['points' => 'Depths must increase from one point to the next.']);
            }
            if ($lastVolume !== null && $volume < $lastVolume) {
                throw ValidationException::withMessages(['points' => 'Volume cannot fall as the depth increases.']);
            }
            $parsed[] = ['depth_mm' => $depth, 'volume_milli' => $volume];
            $lastDepth = $depth;
            $lastVolume = $volume;
        }

        return $parsed;
    }
}
