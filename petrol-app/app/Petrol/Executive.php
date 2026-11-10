<?php

namespace App\Petrol;

use App\Models\AttendantHandover;
use App\Models\BankDeposit;
use App\Models\CashCount;
use App\Models\CashTransfer;
use App\Models\CreditEntry;
use App\Models\Expense;
use App\Models\FuelReceipt;
use App\Models\FuelSale;
use App\Models\FuelTransfer;
use App\Models\Nozzle;
use App\Models\PumpCalibration;
use App\Models\Safe;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftException;
use App\Models\Station;
use App\Models\Store;
use App\Models\StoreBalance;
use App\Models\StoreProduct;
use App\Models\StoreTransfer;
use App\Models\Tank;
use App\Models\TankMeasurement;
use App\Models\WorkOrder;
use App\Models\WorkPayment;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Volume;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * Company figures for the branches a member may report on. Totals are
 * summed in the workspace currency. Cash taken to the office is not
 * added to sales. An open shift that has not been approved is not a shortage.
 */
class Executive
{
    /**
     * @param  list<string>  $branchIds
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    public function overview(array $branchIds, array $names, string $from, string $to): array
    {
        $previous = $this->previous($from, $to);
        $sales = $this->sales($branchIds, $from, $to);
        $earlier = $this->sales($branchIds, $previous['from'], $previous['to']);
        $stock = $this->stock($branchIds);
        $cash = $this->approvedCash($branchIds, $from, $to);
        $variance = $this->variance($branchIds);

        return [
            'kpis' => [
                'salesMinor' => $sales['totalMinor'],
                'previousSalesMinor' => $earlier['totalMinor'],
                'salesChange' => $this->change($sales['totalMinor'], $earlier['totalMinor']),
                'litres' => Volume::toLitres($sales['milli']),
                'count' => $sales['count'],
                'profitMinor' => $sales['profitMinor'],
                'previousProfitMinor' => $earlier['profitMinor'],
                'stockLitres' => Volume::toLitres($stock['milli']),
                'stockValueMinor' => $stock['valueMinor'],
                'cashMinor' => $sales['cashMinor'],
                'creditMinor' => $this->credit($branchIds),
                'shortageMinor' => $cash['shortageMinor'],
                'overageMinor' => $cash['overageMinor'],
                'varianceMilli' => $variance['approved'],
                'unverifiedVarianceMilli' => $variance['unverified'],
                'openShifts' => $this->shifts($branchIds, [Shift::OPEN]),
                'pendingApprovals' => $this->shifts($branchIds, [Shift::CLOSING_SUBMITTED, Shift::UNDER_REVIEW]),
                'branchCount' => count($branchIds),
                'activeStations' => $stock['activeStations'],
            ],
            'series' => $this->series($branchIds, $from, $to),
            'byBranch' => $this->byBranch($branchIds, $names, $sales['byBranch'], $stock['byBranch'], $cash['byBranch']),
            'stations' => $this->stations($branchIds, $names, $sales['byBranch'], $stock['tanks'], $cash['byBranch'], $from, $to),
            'byProduct' => $sales['byProduct'],
            'payments' => $sales['payments'],
            'tanks' => $stock['tanks'],
            'onDuty' => $this->onDuty($branchIds, $names),
            'alerts' => $this->alerts($names, $stock['tanks'], $variance, $cash, $this->shifts($branchIds, [Shift::CLOSING_SUBMITTED, Shift::UNDER_REVIEW])),
            'operations' => $this->operations($branchIds, $from, $to),
            'accountability' => $this->accountability($branchIds),
            'fuel' => $this->fuelWatch($branchIds),
        ];
    }

    /**
     * @return array{from: string, to: string}
     */
    private function previous(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from);
        $days = $start->diffInDays(CarbonImmutable::parse($to)) + 1;
        $previousTo = $start->subDay();

        return [
            'from' => $previousTo->subDays($days - 1)->toDateString(),
            'to' => $previousTo->toDateString(),
        ];
    }

    private function change(int $current, int $previous): ?int
    {
        if ($previous === 0) {
            return null;
        }

        return (int) round(($current - $previous) * 100 / $previous);
    }

    /**
     * @param  list<string>  $branchIds
     * @return array{totalMinor: int, milli: int, count: int, profitMinor: int, cashMinor: int, byBranch: array<string, array{totalMinor: int, milli: int, count: int}>, byProduct: list<array{name: string, litres: string, totalMinor: int, share: int}>, payments: list<array{kind: string, totalMinor: int}>}
     */
    private function sales(array $branchIds, string $from, string $to): array
    {
        $empty = [
            'totalMinor' => 0,
            'milli' => 0,
            'count' => 0,
            'profitMinor' => 0,
            'cashMinor' => 0,
            'byBranch' => [],
            'byProduct' => [],
            'payments' => [],
        ];

        if ($branchIds === []) {
            return $empty;
        }

        [$start, $end] = BusinessDate::range($from, $to);
        $posted = FuelSale::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', FuelSale::POSTED)
            ->whereBetween('sold_at', [$start, $end]);

        $rows = (clone $posted)
            ->selectRaw('branch_id, count(*) as sales_count, sum(coalesce(reporting_total_minor, total_minor)) as total_minor, sum(quantity_milli) as milli, sum(cost_minor) as cost_minor')
            ->groupBy('branch_id')
            ->get();

        $byBranch = [];
        $total = 0;
        $milli = 0;
        $count = 0;
        $cost = 0;
        foreach ($rows as $row) {
            $branchTotal = (int) $row->getAttribute('total_minor');
            $byBranch[(string) $row->branch_id] = [
                'totalMinor' => $branchTotal,
                'milli' => (int) $row->getAttribute('milli'),
                'count' => (int) $row->getAttribute('sales_count'),
            ];
            $total += $branchTotal;
            $milli += (int) $row->getAttribute('milli');
            $count += (int) $row->getAttribute('sales_count');
            $cost += (int) $row->getAttribute('cost_minor');
        }

        $expenses = (int) Expense::query()
            ->whereIn('branch_id', $branchIds)
            ->whereDate('incurred_on', '>=', $from)
            ->whereDate('incurred_on', '<=', $to)
            ->sum(DB::raw('coalesce(reporting_amount_minor, amount_minor)'));

        $products = (clone $posted)
            ->join('fuel_products', 'fuel_products.id', '=', 'fuel_sales.fuel_product_id')
            ->selectRaw('fuel_products.id as product_id, fuel_products.name as product_name, sum(fuel_sales.quantity_milli) as milli, sum(coalesce(fuel_sales.reporting_total_minor, fuel_sales.total_minor)) as total_minor')
            ->groupBy('fuel_products.id', 'fuel_products.name')
            ->orderByDesc('total_minor')
            ->get();

        $byProduct = [];
        foreach ($products as $product) {
            $productTotal = (int) $product->getAttribute('total_minor');
            $byProduct[] = [
                'name' => (string) $product->getAttribute('product_name'),
                'litres' => Volume::toLitres((int) $product->getAttribute('milli')),
                'totalMinor' => $productTotal,
                'share' => $total > 0 ? (int) round($productTotal * 100 / $total) : 0,
            ];
        }

        $payments = SalePayment::query()
            ->join('fuel_sales', 'fuel_sales.id', '=', 'sale_payments.fuel_sale_id')
            ->where('fuel_sales.organization_id', $this->organization())
            ->whereIn('fuel_sales.branch_id', $branchIds)
            ->where('fuel_sales.status', FuelSale::POSTED)
            ->whereBetween('fuel_sales.sold_at', [$start, $end])
            ->get(['sale_payments.kind', 'sale_payments.amount_minor', 'fuel_sales.total_minor', 'fuel_sales.reporting_total_minor']);

        $grouped = [];
        foreach ($payments as $payment) {
            $amount = (int) $payment->amount_minor;
            $saleTotal = (int) $payment->getAttribute('total_minor');
            $reported = $payment->getAttribute('reporting_total_minor');
            if ($reported !== null && $saleTotal > 0 && (int) $reported !== $saleTotal) {
                $amount = intdiv($amount * (int) $reported + intdiv($saleTotal, 2), $saleTotal);
            }
            $kind = (string) $payment->kind;
            $grouped[$kind] = ($grouped[$kind] ?? 0) + $amount;
        }

        $paymentRows = [];
        $cash = 0;
        foreach ($grouped as $kind => $amount) {
            $paymentRows[] = ['kind' => $kind, 'totalMinor' => $amount];
            if ($kind === 'cash') {
                $cash = $amount;
            }
        }

        return [
            'totalMinor' => $total,
            'milli' => $milli,
            'count' => $count,
            'profitMinor' => $total - $cost - $expenses,
            'cashMinor' => $cash,
            'byBranch' => $byBranch,
            'byProduct' => $byProduct,
            'payments' => $paymentRows,
        ];
    }

    /**
     * @param  list<string>  $branchIds
     * @return array{milli: int, valueMinor: int, activeStations: int, byBranch: array<string, int>, tanks: list<array<string, mixed>>}
     */
    private function stock(array $branchIds): array
    {
        if ($branchIds === []) {
            return ['milli' => 0, 'valueMinor' => 0, 'activeStations' => 0, 'byBranch' => [], 'tanks' => []];
        }

        $percents = Station::query()->whereIn('branch_id', $branchIds)->pluck('low_stock_percent', 'branch_id');
        $tanks = Tank::query()->with('product')->whereIn('branch_id', $branchIds)->where('status', Tank::ACTIVE)->orderBy('code')->get();
        $milli = 0;
        $value = 0;
        $byBranch = [];
        $rows = [];

        foreach ($tanks as $tank) {
            $milli += $tank->quantity_milli;
            $worth = Volume::amount($tank->quantity_milli, $tank->average_cost_minor);
            $value += $worth;
            $byBranch[$tank->branch_id] = ($byBranch[$tank->branch_id] ?? 0) + $tank->quantity_milli;
            $rows[] = [
                'branchId' => $tank->branch_id,
                'code' => $tank->code,
                'product' => $tank->product->name,
                'capacity' => Volume::toLitres($tank->capacity_milli),
                'quantity' => Volume::toLitres($tank->quantity_milli),
                'fill' => $tank->capacity_milli > 0 ? (int) round($tank->quantity_milli * 100 / $tank->capacity_milli) : 0,
                'valueMinor' => $worth,
                'low' => $tank->isLow((int) ($percents[$tank->branch_id] ?? 15)),
            ];
        }

        return [
            'milli' => $milli,
            'valueMinor' => $value,
            'activeStations' => Station::query()->whereIn('branch_id', $branchIds)->where('active', true)->count(),
            'byBranch' => $byBranch,
            'tanks' => $rows,
        ];
    }

    /**
     * Approved closings only. A negative difference is a confirmed shortage.
     *
     * @param  list<string>  $branchIds
     * @return array{shortageMinor: int, overageMinor: int, byBranch: array<string, int>}
     */
    private function approvedCash(array $branchIds, string $from, string $to): array
    {
        if ($branchIds === []) {
            return ['shortageMinor' => 0, 'overageMinor' => 0, 'byBranch' => []];
        }

        $rows = Shift::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', Shift::APPROVED)
            ->whereDate('business_date', '>=', $from)
            ->whereDate('business_date', '<=', $to)
            ->whereNotNull('cash_difference_minor')
            ->get(['branch_id', 'cash_difference_minor']);

        $shortage = 0;
        $overage = 0;
        $byBranch = [];
        foreach ($rows as $row) {
            $difference = (int) $row->cash_difference_minor;
            if ($difference < 0) {
                $shortage += abs($difference);
                $byBranch[$row->branch_id] = ($byBranch[$row->branch_id] ?? 0) + abs($difference);
            } elseif ($difference > 0) {
                $overage += $difference;
            }
        }

        return ['shortageMinor' => $shortage, 'overageMinor' => $overage, 'byBranch' => $byBranch];
    }

    /**
     * @param  list<string>  $branchIds
     * @return array{approved: int, unverified: int}
     */
    private function variance(array $branchIds): array
    {
        if ($branchIds === []) {
            return ['approved' => 0, 'unverified' => 0];
        }

        $latest = [];
        $measurements = TankMeasurement::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('status', [TankMeasurement::RECORDED, TankMeasurement::VERIFIED, TankMeasurement::APPROVED])
            ->orderByDesc('measured_at')
            ->get(['tank_id', 'status', 'variance_milli']);

        foreach ($measurements as $measurement) {
            if (isset($latest[$measurement->tank_id])) {
                continue;
            }
            $latest[$measurement->tank_id] = $measurement;
        }

        $approved = 0;
        $unverified = 0;
        foreach ($latest as $measurement) {
            if ($measurement->status === TankMeasurement::APPROVED) {
                $approved += (int) $measurement->variance_milli;
            } else {
                $unverified += (int) $measurement->variance_milli;
            }
        }

        return ['approved' => $approved, 'unverified' => $unverified];
    }

    /**
     * @param  list<string>  $branchIds
     * @return list<array{date: string, totalMinor: int}>
     */
    private function series(array $branchIds, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        if ($start->diffInDays($end) > 62) {
            $start = $end->subDays(62);
        }

        $totals = [];
        if ($branchIds !== []) {
            [$rangeStart, $rangeEnd] = BusinessDate::range($start->toDateString(), $end->toDateString());
            [$expression, $bindings] = $this->dayExpression();
            $rows = FuelSale::query()
                ->whereIn('branch_id', $branchIds)
                ->where('status', FuelSale::POSTED)
                ->whereBetween('sold_at', [$rangeStart, $rangeEnd])
                ->selectRaw($expression.' as sold_on, sum(coalesce(reporting_total_minor, total_minor)) as total_minor', $bindings)
                ->groupBy('sold_on')
                ->get();

            foreach ($rows as $row) {
                $totals[(string) $row->getAttribute('sold_on')] = (int) $row->total_minor;
            }
        }

        $series = [];
        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $series[] = ['date' => $date, 'totalMinor' => $totals[$date] ?? 0];
        }

        return $series;
    }

    /**
     * The local business day of sold_at, with its bindings.
     *
     * @return array{literal-string, list<string>}
     */
    private function dayExpression(): array
    {
        $timezone = BusinessDate::timezone();
        if (preg_match('#^[A-Za-z0-9_+\-/]{1,64}$#', $timezone) !== 1) {
            $timezone = 'UTC';
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            return ["to_char((sold_at at time zone 'UTC') at time zone ?, 'YYYY-MM-DD')", [$timezone]];
        }

        return ["strftime('%Y-%m-%d', sold_at)", []];
    }

    /**
     * @param  list<string>  $branchIds
     * @param  array<string, string>  $names
     * @param  array<string, array{totalMinor: int, milli: int, count: int}>  $sales
     * @param  array<string, int>  $stock
     * @param  array<string, int>  $shortages
     * @return list<array<string, mixed>>
     */
    private function byBranch(array $branchIds, array $names, array $sales, array $stock, array $shortages): array
    {
        $mains = Station::query()->whereIn('branch_id', $branchIds)->where('is_main', true)->pluck('branch_id')->flip();
        $rows = [];
        foreach ($branchIds as $branchId) {
            $row = $sales[$branchId] ?? ['totalMinor' => 0, 'milli' => 0, 'count' => 0];
            $rows[] = [
                'id' => $branchId,
                'name' => $names[$branchId] ?? 'Station',
                'main' => isset($mains[$branchId]),
                'salesMinor' => $row['totalMinor'],
                'litres' => Volume::toLitres($row['milli']),
                'count' => $row['count'],
                'stockLitres' => Volume::toLitres($stock[$branchId] ?? 0),
                'shortageMinor' => $shortages[$branchId] ?? 0,
            ];
        }

        usort($rows, fn (array $left, array $right) => $right['salesMinor'] <=> $left['salesMinor']);

        return $rows;
    }

    /**
     * The operations board alone (the stations page): each station's row, as
     * on the company dashboard.
     *
     * @param  list<string>  $branchIds
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    public function board(array $branchIds, array $names, string $from, string $to): array
    {
        return $this->stations(
            $branchIds,
            $names,
            $this->sales($branchIds, $from, $to)['byBranch'],
            $this->stock($branchIds)['tanks'],
            $this->approvedCash($branchIds, $from, $to)['byBranch'],
            $from,
            $to,
        );
    }

    /**
     * Each station as it stands now, for the operations board: its shift,
     * its sales in the range, its tanks, who is on its pumps, and what needs
     * someone. Worst first, then by sales.
     *
     * @param  list<string>  $branchIds
     * @param  array<string, string>  $names
     * @param  array<string, array{totalMinor: int, milli: int, count: int}>  $sales
     * @param  list<array<string, mixed>>  $tanks
     * @param  array<string, int>  $shortages
     * @return list<array<string, mixed>>
     */
    private function stations(array $branchIds, array $names, array $sales, array $tanks, array $shortages, string $from, string $to): array
    {
        if ($branchIds === []) {
            return [];
        }

        $stations = Station::query()->whereIn('branch_id', $branchIds)->get()->keyBy('branch_id');
        $open = Shift::query()->whereIn('branch_id', $branchIds)->where('status', Shift::OPEN)->get(['id', 'branch_id', 'number', 'opened_at'])->keyBy('branch_id');
        $review = $this->countBy(Shift::query()->whereIn('branch_id', $branchIds)->whereIn('status', [Shift::CLOSING_SUBMITTED, Shift::UNDER_REVIEW]));
        $nozzles = $this->countBy(Nozzle::query()->whereIn('branch_id', $branchIds)->where('status', Nozzle::ACTIVE));
        $exceptions = $this->countBy(ShiftException::query()->whereIn('branch_id', $branchIds)->whereIn('status', [ShiftException::OPEN, ShiftException::UNDER_REVIEW]));
        $handovers = $this->countBy(AttendantHandover::query()->whereIn('branch_id', $branchIds)->whereIn('status', [AttendantHandover::REQUESTED, AttendantHandover::VERIFIED]));
        $deliveries = $this->countBy(FuelReceipt::query()->whereIn('branch_id', $branchIds)->where('status', FuelReceipt::PENDING));
        $water = $this->countBy(Tank::query()->whereIn('branch_id', $branchIds)->where('status', Tank::ACTIVE)->where('water_alert', true));
        $calibrations = $this->countBy(PumpCalibration::query()->whereIn('branch_id', $branchIds)->whereNotNull('next_due')->whereDate('next_due', '<=', now()->toDateString()));

        [$start, $end] = BusinessDate::range($from, $to);
        $lastSale = FuelSale::query()->whereIn('branch_id', $branchIds)->where('status', FuelSale::POSTED)->whereBetween('sold_at', [$start, $end])
            ->groupBy('branch_id')->selectRaw('branch_id, max(sold_at) as last_sold_at')->pluck('last_sold_at', 'branch_id');

        // Who is on which pump of each open shift.
        $assignments = $open->isEmpty() ? collect() : ShiftAssignment::query()
            ->whereIn('shift_id', $open->pluck('id'))->whereNull('ended_at')->where('status', ShiftAssignment::ACTIVE)
            ->get(['shift_id', 'attendant_id', 'nozzle_id', 'opening_milli']);
        $branchOfShift = $open->mapWithKeys(fn (Shift $shift) => [$shift->id => $shift->branch_id]);
        $duty = $assignments->groupBy(fn (ShiftAssignment $row) => $branchOfShift[$row->shift_id] ?? '');

        $tankRows = collect($tanks)->groupBy('branchId');

        $rows = [];
        foreach ($branchIds as $branchId) {
            $station = $stations->get($branchId);
            $shift = $open->get($branchId);
            $sold = $sales[$branchId] ?? ['totalMinor' => 0, 'milli' => 0, 'count' => 0];
            $onDuty = $duty->get($branchId, collect());
            $mine = $tankRows->get($branchId, collect());
            $capacity = 0;
            $held = 0;
            foreach ($mine as $tank) {
                $capacity += Volume::toMilli((string) $tank['capacity']);
                $held += Volume::toMilli((string) $tank['quantity']);
            }
            $low = $mine->where('low', true)->count();
            $staffed = $onDuty->pluck('nozzle_id')->unique()->count();
            $activeNozzles = $nozzles[$branchId] ?? 0;
            $unread = $onDuty->whereNull('opening_milli')->count();

            // What someone has to act on, worst first.
            $issues = [];
            $flag = function (string $tone, string $text) use (&$issues) {
                $issues[] = ['tone' => $tone, 'text' => $text];
            };
            if ($station === null) {
                $flag('warning', 'No station set up.');
            } elseif (! $station->active) {
                $flag('neutral', 'Station closed.');
            } else {
                if (($water[$branchId] ?? 0) > 0) {
                    $flag('critical', $this->plural($water[$branchId], 'tank').' flagged for water.');
                }
                if (($shortages[$branchId] ?? 0) > 0) {
                    $flag('critical', 'Approved closings are short.');
                }
                foreach ($mine as $tank) {
                    if ((int) $tank['fill'] <= 5) {
                        $flag('critical', $tank['code'].' is nearly empty.');
                    }
                }
                if ($shift === null) {
                    $flag('warning', 'No shift open.');
                } elseif ($activeNozzles > 0 && $staffed < $activeNozzles) {
                    $flag('warning', ($activeNozzles - $staffed).' of '.$activeNozzles.' pumps unstaffed.');
                }
                if ($low > 0) {
                    $flag('warning', $this->plural($low, 'tank').' low.');
                }
                if ($unread > 0) {
                    $flag('warning', $this->plural($unread, 'opening reading').' not captured.');
                }
                if (($exceptions[$branchId] ?? 0) > 0) {
                    $flag('warning', $this->plural($exceptions[$branchId], 'exception').' open.');
                }
                if (($review[$branchId] ?? 0) > 0) {
                    $flag('info', $this->plural($review[$branchId], 'closing').' to review.');
                }
                if (($handovers[$branchId] ?? 0) > 0) {
                    $flag('info', $this->plural($handovers[$branchId], 'handover').' waiting.');
                }
                if (($deliveries[$branchId] ?? 0) > 0) {
                    $flag('info', $this->plural($deliveries[$branchId], 'delivery', 'deliveries').' to receive.');
                }
                if (($calibrations[$branchId] ?? 0) > 0) {
                    $flag('info', $this->plural($calibrations[$branchId], 'calibration').' due.');
                }
            }
            $rank = ['critical' => 0, 'warning' => 1, 'info' => 2, 'neutral' => 3];
            usort($issues, fn (array $a, array $b) => $rank[$a['tone']] <=> $rank[$b['tone']]);
            $tones = array_column($issues, 'tone');
            $state = match (true) {
                $station === null || ! $station->active => 'closed',
                in_array('critical', $tones, true) => 'critical',
                in_array('warning', $tones, true) => 'attention',
                default => 'ok',
            };

            $rows[] = [
                'id' => $branchId,
                'name' => $names[$branchId] ?? 'Station',
                'code' => $station?->code,
                'main' => (bool) $station?->is_main,
                'state' => $state,
                'shift' => $shift === null ? null : ['number' => $shift->number, 'openedAt' => $shift->opened_at->toIso8601String()],
                'salesMinor' => $sold['totalMinor'],
                'litres' => Volume::toLitres($sold['milli']),
                'count' => $sold['count'],
                'lastSaleAt' => isset($lastSale[$branchId]) ? CarbonImmutable::parse((string) $lastSale[$branchId])->toIso8601String() : null,
                'stockLitres' => Volume::toLitres($held),
                'fill' => $capacity > 0 ? (int) round($held * 100 / $capacity) : 0,
                'tanks' => $mine->count(),
                'lowTanks' => $low,
                'attendants' => $onDuty->pluck('attendant_id')->unique()->count(),
                'staffedPumps' => $staffed,
                'pumps' => $activeNozzles,
                'shortageMinor' => $shortages[$branchId] ?? 0,
                'issues' => $issues,
            ];
        }

        $order = ['critical' => 0, 'attention' => 1, 'ok' => 2, 'closed' => 3];
        usort($rows, fn (array $a, array $b) => [$order[$a['state']], $b['salesMinor']] <=> [$order[$b['state']], $a['salesMinor']]);

        return $rows;
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, int>
     */
    private function countBy($query): array
    {
        return $query->toBase()->groupBy('branch_id')->selectRaw('branch_id, count(*) as n')->pluck('n', 'branch_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    private function plural(int $count, string $one, ?string $many = null): string
    {
        return $count.' '.($count === 1 ? $one : ($many ?? $one.'s'));
    }

    /**
     * @param  list<string>  $branchIds
     */
    private function credit(array $branchIds): int
    {
        if ($branchIds === []) {
            return 0;
        }

        return (int) CreditEntry::query()->whereIn('branch_id', $branchIds)->sum(DB::raw('coalesce(reporting_amount_minor, amount_minor)'));
    }

    /**
     * @param  list<string>  $branchIds
     * @param  list<string>  $statuses
     */
    private function shifts(array $branchIds, array $statuses): int
    {
        if ($branchIds === []) {
            return 0;
        }

        return Shift::query()->whereIn('branch_id', $branchIds)->whereIn('status', $statuses)->count();
    }

    /**
     * @param  list<string>  $branchIds
     * @param  array<string, string>  $names
     * @return list<array{attendant: string, branch: string, nozzle: string, shift: string}>
     */
    /**
     * @param  list<string>  $branchIds
     * @return array{activeAttendants: int, activeAssignments: int, pendingHandovers: int, openExceptions: int, unverifiedReadings: int}
     */
    /**
     * @param  list<string>  $branchIds
     * @return array{pendingDeliveries: int, waterAlerts: int, calibrationsDue: int, tanksUnmeasured: int}
     */
    private function fuelWatch(array $branchIds): array
    {
        if ($branchIds === []) {
            return ['pendingDeliveries' => 0, 'waterAlerts' => 0, 'calibrationsDue' => 0, 'tanksUnmeasured' => 0];
        }
        $measured = TankMeasurement::query()->whereIn('branch_id', $branchIds)->where('measured_at', '>=', now()->subDay())->distinct()->pluck('tank_id');
        $unmeasured = Tank::query()->whereIn('branch_id', $branchIds)->where('status', Tank::ACTIVE);
        if ($measured->isNotEmpty()) {
            $unmeasured->whereNotIn('id', $measured);
        }

        return [
            'pendingDeliveries' => FuelReceipt::query()->whereIn('branch_id', $branchIds)->where('status', FuelReceipt::PENDING)->count(),
            'waterAlerts' => Tank::query()->whereIn('branch_id', $branchIds)->where('water_alert', true)->count(),
            'calibrationsDue' => PumpCalibration::query()->whereIn('branch_id', $branchIds)->whereNotNull('next_due')->whereDate('next_due', '<=', now()->toDateString())->count(),
            'tanksUnmeasured' => $unmeasured->count(),
        ];
    }

    /**
     * @param  list<string>  $branchIds
     * @return array<string, int>
     */
    private function accountability(array $branchIds): array
    {
        $empty = ['activeAttendants' => 0, 'activeAssignments' => 0, 'pendingHandovers' => 0, 'openExceptions' => 0, 'unverifiedReadings' => 0];
        if ($branchIds === []) {
            return $empty;
        }
        $open = Shift::query()->whereIn('branch_id', $branchIds)->where('status', Shift::OPEN)->pluck('id');
        $active = ShiftAssignment::query()->whereIn('shift_id', $open)->whereNull('ended_at')->where('status', ShiftAssignment::ACTIVE)->get(['attendant_id', 'opening_milli']);

        return [
            'activeAttendants' => $active->pluck('attendant_id')->unique()->count(),
            'activeAssignments' => $active->count(),
            'pendingHandovers' => AttendantHandover::query()->whereIn('branch_id', $branchIds)->whereIn('status', [AttendantHandover::REQUESTED, AttendantHandover::VERIFIED])->count(),
            'openExceptions' => ShiftException::query()->whereIn('branch_id', $branchIds)->whereIn('status', [ShiftException::OPEN, ShiftException::UNDER_REVIEW])->count(),
            'unverifiedReadings' => $active->whereNull('opening_milli')->count(),
        ];
    }

    /**
     * @param  list<string>  $branchIds
     * @param  array<string, string>  $names
     * @return list<array<string, mixed>>
     */
    private function onDuty(array $branchIds, array $names): array
    {
        if ($branchIds === []) {
            return [];
        }

        $open = Shift::query()->whereIn('branch_id', $branchIds)->where('status', Shift::OPEN)->get(['id', 'branch_id', 'number']);
        if ($open->isEmpty()) {
            return [];
        }

        $shifts = $open->keyBy('id');
        $assignments = ShiftAssignment::query()
            ->with(['attendant', 'nozzle'])
            ->whereIn('shift_id', $open->pluck('id'))
            ->whereNull('ended_at')
            ->where('status', ShiftAssignment::ACTIVE)
            ->get();

        $rows = [];
        foreach ($assignments as $assignment) {
            $shift = $shifts->get($assignment->shift_id);
            $rows[] = [
                'attendant' => $assignment->attendant->name,
                'branch' => $names[$shift->branch_id] ?? 'Station',
                'nozzle' => $assignment->nozzle->code,
                'shift' => $shift->number,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $names
     * @param  list<array<string, mixed>>  $tanks
     * @param  array{approved: int, unverified: int}  $variance
     * @param  array{shortageMinor: int, overageMinor: int, byBranch: array<string, int>}  $cash
     * @return list<array{severity: string, branch: string, text: string}>
     */
    private function alerts(array $names, array $tanks, array $variance, array $cash, int $pending): array
    {
        $alerts = [];
        foreach ($tanks as $tank) {
            if ($tank['low'] === true) {
                $alerts[] = [
                    'severity' => 'warning',
                    'branch' => $names[$tank['branchId']] ?? 'Station',
                    'text' => $tank['code'].' ('.$tank['product'].') is low: '.$tank['quantity'].' L of '.$tank['capacity'].' L.',
                ];
            }
        }

        if ($variance['approved'] !== 0) {
            $alerts[] = [
                'severity' => 'warning',
                'branch' => 'Company',
                'text' => 'Approved dips differ from the book by '.Volume::toLitres($variance['approved']).' L. This is a stock difference, not a finding about a person.',
            ];
        }

        if ($variance['unverified'] !== 0) {
            $alerts[] = [
                'severity' => 'info',
                'branch' => 'Company',
                'text' => 'Dips still awaiting approval differ from the book by '.Volume::toLitres($variance['unverified']).' L.',
            ];
        }

        if ($cash['shortageMinor'] > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'branch' => 'Company',
                'text' => 'Approved shift closings are short. Open shifts are not counted.',
            ];
        }

        if ($pending > 0) {
            $alerts[] = [
                'severity' => 'info',
                'branch' => 'Company',
                'text' => $pending.' shift closing'.($pending === 1 ? '' : 's').' waiting for review.',
            ];
        }

        return $alerts;
    }

    /**
     * @param  list<string>  $branchIds
     * @return array<string, int|string|list<array<string, int|string>>>
     */
    private function operations(array $branchIds, string $from, string $to): array
    {
        $custody = $this->custody($branchIds, $from, $to);
        if ($branchIds === []) {
            return [
                'expensesMinor' => 0,
                'pendingExpenses' => 0,
                'serviceOpen' => 0,
                'serviceCompleted' => 0,
                'serviceRevenueMinor' => 0,
                'storeValueMinor' => 0,
                'storeLow' => 0,
                'fuelInTransit' => '0.000',
                'storeInTransit' => 0,
                ...$custody,
            ];
        }

        $orders = WorkOrder::query()->whereIn('branch_id', $branchIds);
        $storeIds = Store::query()->whereIn('branch_id', $branchIds)->pluck('id');
        $balances = StoreBalance::query()->whereIn('store_id', $storeIds)->get();
        $reorders = StoreProduct::query()->whereIn('id', $balances->pluck('product_id'))->pluck('reorder_milli', 'id');
        $value = 0;
        $low = 0;
        foreach ($balances as $balance) {
            $value += Volume::amount($balance->quantity_milli, $balance->average_cost_minor);
            $reorder = $reorders[$balance->product_id] ?? null;
            if ($reorder !== null && $balance->quantity_milli <= $reorder) {
                $low++;
            }
        }

        $inTransit = (int) FuelTransfer::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', FuelTransfer::DISPATCHED)
            ->sum('dispatched_milli');

        return [
            'expensesMinor' => (int) Expense::query()
                ->whereIn('branch_id', $branchIds)
                ->whereDate('incurred_on', '>=', $from)
                ->whereDate('incurred_on', '<=', $to)
                ->sum(DB::raw('coalesce(reporting_amount_minor, amount_minor)')),
            'pendingExpenses' => Expense::query()->whereIn('branch_id', $branchIds)->where('approval_status', 'recorded')->count(),
            'serviceOpen' => (clone $orders)->whereIn('status', [WorkOrder::OPEN, WorkOrder::IN_PROGRESS])->count(),
            'serviceCompleted' => (clone $orders)->where('status', WorkOrder::COMPLETED)->whereDate('completed_at', '>=', $from)->whereDate('completed_at', '<=', $to)->count(),
            'serviceRevenueMinor' => (int) WorkPayment::query()
                ->whereIn('work_order_id', WorkOrder::query()->whereIn('branch_id', $branchIds)->select('id'))
                ->whereDate('created_at', '>=', $from)
                ->whereDate('created_at', '<=', $to)
                ->sum('amount_minor'),
            'storeValueMinor' => $value,
            'storeLow' => $low,
            'fuelInTransit' => Volume::toLitres($inTransit),
            'storeInTransit' => StoreTransfer::query()->whereIn('source_store_id', $storeIds)->where('status', StoreTransfer::DISPATCHED)->count(),
            ...$custody,
        ];
    }

    /**
     * Book-currency cash still in someone's hands. Foreign notes stay in their own count.
     *
     * @param  list<string>  $branchIds
     * @return array<string, int|list<array<string, int|string>>>
     */
    private function custody(array $branchIds, string $from, string $to): array
    {
        $empty = [
            'attendantHeldMinor' => 0,
            'cashierHeldMinor' => 0,
            'safeHeldMinor' => 0,
            'depositsInTransitMinor' => 0,
            'depositsConfirmedMinor' => 0,
            'remittedMinor' => 0,
            'pendingTransfers' => 0,
            'shortageMinor' => 0,
            'overageMinor' => 0,
            'custodyCurrencies' => array_map(fn (string $currency) => $this->emptyCustody($currency), app(Currencies::class)->reportCodes()),
        ];
        if ($branchIds === []) {
            return $empty;
        }

        $books = Money::currency();
        $custody = app(Custody::class);
        $openShifts = Shift::query()->whereIn('branch_id', $branchIds)->where('status', Shift::OPEN)->pluck('id');
        $seen = [];
        $attendantHeld = 0;
        $assignments = ShiftAssignment::query()->whereIn('shift_id', $openShifts)->get();
        foreach ($assignments as $assignment) {
            $key = $assignment->shift_id.'|'.$assignment->attendant_id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $attendantHeld += max(0, $custody->attendantHeld($assignment->shift_id, $assignment->attendant_id, $books));
        }

        $cashierHeld = 0;
        $cashbook = app(Cashbook::class);
        foreach ($openShifts as $shiftId) {
            $cashierHeld += $cashbook->drawer((string) $shiftId, $books);
        }

        $safeHeld = 0;
        foreach (Safe::query()->whereIn('branch_id', $branchIds)->where('status', Safe::OPEN)->get() as $safe) {
            $safeHeld += $custody->safeBalance($safe, $books);
        }

        $inTransit = 0;
        $confirmedDeposits = 0;
        $deposits = BankDeposit::query()->whereIn('branch_id', $branchIds)->get();
        foreach ($deposits as $deposit) {
            if (($deposit->currency ?: $books) !== $books) {
                continue;
            }
            if ($deposit->status === BankDeposit::SUBMITTED) {
                $inTransit += (int) $deposit->amount_minor;
            }
            if ($deposit->status === BankDeposit::CONFIRMED) {
                $confirmedDeposits += (int) ($deposit->reporting_minor ?? $deposit->amount_minor);
            }
        }

        $remitted = 0;
        $handovers = CashTransfer::query()
            ->whereIn('branch_id', $branchIds)
            ->where('kind', CashTransfer::REMITTANCE)
            ->whereIn('status', [CashTransfer::CONFIRMED, CashTransfer::DISPUTED])
            ->whereDate('confirmed_at', '>=', $from)
            ->whereDate('confirmed_at', '<=', $to)
            ->get();
        foreach ($handovers as $handover) {
            if (($handover->currency ?: $books) !== $books) {
                continue;
            }
            $remitted += (int) ($handover->reporting_minor ?? $handover->counted_minor);
        }

        $shortage = 0;
        $overage = 0;
        foreach (CashCount::query()->whereIn('branch_id', $branchIds)->get() as $count) {
            if (($count->currency ?: $books) !== $books) {
                continue;
            }
            if ($count->difference_minor < 0) {
                $shortage += abs((int) $count->difference_minor);
            }
            if ($count->difference_minor > 0) {
                $overage += (int) $count->difference_minor;
            }
        }
        foreach (CashTransfer::query()->whereIn('branch_id', $branchIds)->where('status', CashTransfer::DISPUTED)->get() as $row) {
            if (($row->currency ?: $books) !== $books) {
                continue;
            }
            $gap = (int) $row->declared_minor - (int) $row->counted_minor;
            if ($gap > 0) {
                $shortage += $gap;
            }
            if ($gap < 0) {
                $overage += abs($gap);
            }
        }

        return [
            'attendantHeldMinor' => $attendantHeld,
            'cashierHeldMinor' => $cashierHeld,
            'safeHeldMinor' => $safeHeld,
            'depositsInTransitMinor' => $inTransit,
            'depositsConfirmedMinor' => $confirmedDeposits,
            'remittedMinor' => $remitted,
            'pendingTransfers' => CashTransfer::query()->whereIn('branch_id', $branchIds)->where('status', CashTransfer::PENDING)->count(),
            'shortageMinor' => $shortage,
            'overageMinor' => $overage,
            'custodyCurrencies' => array_map(fn (string $currency) => $this->custodyCurrency($branchIds, $currency, $from, $to), app(Currencies::class)->reportCodes()),
        ];
    }

    /**
     * Physical cash in one currency. These figures are not converted.
     *
     * @param  list<string>  $branchIds
     * @return array<string, int|string>
     */
    private function custodyCurrency(array $branchIds, string $currency, string $from, string $to): array
    {
        $books = Money::currency();
        $matches = fn (?string $stored): bool => ($stored ?: $books) === $currency;
        $custody = app(Custody::class);
        $openShifts = Shift::query()->whereIn('branch_id', $branchIds)->where('status', Shift::OPEN)->pluck('id');
        $attendant = 0;
        $seen = [];
        foreach (ShiftAssignment::query()->whereIn('shift_id', $openShifts)->get() as $assignment) {
            $key = $assignment->shift_id.'|'.$assignment->attendant_id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $attendant += max(0, $custody->attendantHeld($assignment->shift_id, $assignment->attendant_id, $currency));
        }
        $cashier = 0;
        $cashbook = app(Cashbook::class);
        foreach ($openShifts as $shiftId) {
            $cashier += $cashbook->drawer((string) $shiftId, $currency);
        }
        $safe = 0;
        foreach (Safe::query()->whereIn('branch_id', $branchIds)->where('status', Safe::OPEN)->get() as $row) {
            $safe += $custody->safeBalance($row, $currency);
        }
        $transit = 0;
        $confirmed = 0;
        $gap = 0;
        foreach (BankDeposit::query()->whereIn('branch_id', $branchIds)->get() as $deposit) {
            if (! $matches($deposit->currency)) {
                continue;
            }
            if ($deposit->status === BankDeposit::SUBMITTED) {
                $transit += (int) $deposit->amount_minor;
            }
            if ($deposit->status === BankDeposit::CONFIRMED) {
                $taken = (int) ($deposit->confirmed_amount_minor ?? $deposit->amount_minor);
                $confirmed += $taken;
                $gap += (int) $deposit->amount_minor - $taken;
            }
        }
        $pending = 0;
        foreach (CashTransfer::query()->whereIn('branch_id', $branchIds)->where('kind', CashTransfer::REMITTANCE)->where('status', CashTransfer::PENDING)->get() as $row) {
            if ($matches($row->currency)) {
                $pending += (int) $row->declared_minor;
            }
        }
        $shortage = 0;
        $overage = 0;
        foreach (CashCount::query()->whereIn('branch_id', $branchIds)->get() as $count) {
            if (! $matches($count->currency)) {
                continue;
            }
            if ($count->difference_minor < 0) {
                $shortage += abs((int) $count->difference_minor);
            }
            if ($count->difference_minor > 0) {
                $overage += (int) $count->difference_minor;
            }
        }
        foreach (CashTransfer::query()->whereIn('branch_id', $branchIds)->where('status', CashTransfer::DISPUTED)->get() as $row) {
            if (! $matches($row->currency)) {
                continue;
            }
            $difference = (int) $row->declared_minor - (int) $row->counted_minor;
            if ($difference > 0) {
                $shortage += $difference;
            }
            if ($difference < 0) {
                $overage += abs($difference);
            }
        }

        $remitted = 0;
        foreach (CashTransfer::query()->whereIn('branch_id', $branchIds)->where('kind', CashTransfer::REMITTANCE)->whereIn('status', [CashTransfer::CONFIRMED, CashTransfer::DISPUTED])->whereDate('confirmed_at', '>=', $from)->whereDate('confirmed_at', '<=', $to)->get() as $row) {
            if ($matches($row->currency)) {
                $remitted += (int) ($row->counted_minor ?? 0);
            }
        }

        return [
            'currency' => $currency,
            'attendant' => $attendant,
            'remitted' => $remitted,
            'cashier' => $cashier,
            'safe' => $safe,
            'inTransit' => $transit,
            'confirmed' => $confirmed,
            'depositGap' => $gap,
            'pending' => $pending,
            'shortage' => $shortage,
            'overage' => $overage,
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function emptyCustody(string $currency): array
    {
        return [
            'currency' => $currency,
            'attendant' => 0,
            'remitted' => 0,
            'cashier' => 0,
            'safe' => 0,
            'inTransit' => 0,
            'confirmed' => 0,
            'depositGap' => 0,
            'pending' => 0,
            'shortage' => 0,
            'overage' => 0,
        ];
    }

    private function organization(): string
    {
        return app(CurrentTenant::class)->require()->id;
    }
}
