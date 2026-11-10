<?php

namespace App\Petrol;

use App\Models\Attendant;
use App\Models\AttendantStation;
use App\Models\FuelPrice;
use App\Models\FuelProduct;
use App\Models\PaymentMethod;
use App\Models\ShiftSchedule;
use App\Models\Station;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * Station settings, prices, schedules, fuel grades and attendants.
 * A plan feature caps stations or attendants only when the plan includes it.
 */
class StationSetup
{
    public function __construct(
        private readonly Audit $audit,
        private readonly CurrentAccess $access,
        private readonly MainBranch $main,
    ) {}

    /**
     * @param  array{code: string, collection_mode: string, low_stock_percent: int, hours: ?string, notes: ?string, active: bool, operations?: array{fuel: bool, service: bool, store: bool}, shared_pumps?: bool, dispense_during_receiving?: bool, water_alert_mm?: ?int, calibration_interval_days?: ?int}  $input
     */
    public function saveStation(string $branchId, array $input, User $by): Station
    {
        return DB::transaction(function () use ($branchId, $input, $by) {
            $station = Station::query()->where('branch_id', $branchId)->first();
            $creating = $station === null;

            if ($station === null || (! $station->active && $input['active'])) {
                $this->guardLimit(Permissions::STATIONS_FEATURE, Station::query()->where('active', true)->count(), 'stations');
            }

            if ($station === null) {
                $station = new Station(['branch_id' => $branchId, 'is_main' => false]);
            }

            if ($station->is_main && ! $input['active']) {
                throw ValidationException::withMessages([
                    'active' => 'Transfer the main branch before closing this station.',
                ]);
            }

            $station->fill([
                'code' => $input['code'],
                'collection_mode' => $input['collection_mode'],
                'low_stock_percent' => $input['low_stock_percent'],
                'hours' => $input['hours'],
                'notes' => $input['notes'],
                'active' => $input['active'],
            ]);
            if (isset($input['operations'])) {
                $station->operations = [
                    'fuel' => $input['operations']['fuel'],
                    'service' => $input['operations']['service'],
                    'store' => $input['operations']['store'],
                ];
            }
            if (array_key_exists('shared_pumps', $input)) {
                $station->shared_pumps = (bool) $input['shared_pumps'];
            }
            if (array_key_exists('dispense_during_receiving', $input)) {
                $station->dispense_during_receiving = (bool) $input['dispense_during_receiving'];
            }
            if (array_key_exists('water_alert_mm', $input)) {
                $station->water_alert_mm = $input['water_alert_mm'] === null ? null : max(1, $input['water_alert_mm']);
            }
            if (array_key_exists('calibration_interval_days', $input)) {
                $station->calibration_interval_days = $input['calibration_interval_days'] === null ? null : max(1, $input['calibration_interval_days']);
            }
            $station->save();

            $this->main->claimIfNone($station);

            if ($creating) {
                foreach ([
                    ['CASH', 'Cash', PaymentMethod::CASH],
                    ['MOMO', 'Mobile money', PaymentMethod::MOBILE],
                    ['BANK', 'Bank transfer', PaymentMethod::BANK],
                    ['CREDIT', 'Credit', PaymentMethod::CREDIT],
                ] as [$code, $name, $kind]) {
                    PaymentMethod::query()->create([
                        'branch_id' => $branchId,
                        'code' => $code,
                        'name' => $name,
                        'kind' => $kind,
                        'active' => true,
                    ]);
                }
            }

            $this->audit->record($by, $creating ? 'station.created' : 'station.updated', $station, null, [
                'code' => $station->code,
                'active' => $station->active,
            ], $branchId);

            return $station;
        });
    }

    public function saveProduct(string $name, string $code, bool $active, ?FuelProduct $product, User $by): FuelProduct
    {
        $product ??= new FuelProduct;
        $product->fill(['name' => $name, 'code' => strtoupper($code), 'active' => $active])->save();
        $this->audit->record($by, 'product.saved', $product, null, ['code' => $product->code], null);

        return $product;
    }

    public function setPrice(string $branchId, FuelProduct $product, int $minor, User $by, ?string $currency = null): FuelPrice
    {
        if ($minor < 1) {
            throw ValidationException::withMessages(['selling_price' => 'Enter a price.']);
        }

        $currency = strtoupper($currency ?? Money::currency());
        app(Currencies::class)->assertAccepted($branchId, $currency);

        return DB::transaction(function () use ($branchId, $product, $minor, $by, $currency) {
            FuelPrice::query()
                ->where('branch_id', $branchId)
                ->where('fuel_product_id', $product->id)
                ->whereNull('effective_to')
                ->update(['effective_to' => now()]);

            $price = FuelPrice::query()->create([
                'branch_id' => $branchId,
                'fuel_product_id' => $product->id,
                'selling_price_minor' => $minor,
                'currency' => $currency,
                'effective_from' => now(),
                'created_by' => $by->id,
            ]);

            $this->audit->record($by, 'price.set', $price, null, ['selling_price_minor' => $minor], $branchId);

            return $price;
        });
    }

    public function saveSchedule(string $branchId, string $name, string $starts, string $ends, bool $active, ?ShiftSchedule $schedule): ShiftSchedule
    {
        $this->clock($starts);
        $this->clock($ends);

        $schedule ??= new ShiftSchedule(['branch_id' => $branchId]);
        $schedule->fill([
            'name' => $name,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'active' => $active,
        ])->save();

        return $schedule;
    }

    /**
     * @param  array{name: string, phone: ?string, employee_code: string, user_id: ?string, active: bool}  $input
     */
    public function saveAttendant(array $input, ?Attendant $attendant, string $branchId): Attendant
    {
        return DB::transaction(function () use ($input, $attendant, $branchId) {
            $creating = $attendant === null;
            if ($attendant === null || (! $attendant->active && $input['active'])) {
                $this->guardLimit(Permissions::ATTENDANTS_FEATURE, Attendant::query()->where('active', true)->count(), 'attendants');
            }

            $attendant ??= new Attendant;
            $attendant->fill([
                'name' => $input['name'],
                'phone' => $input['phone'],
                'employee_code' => strtoupper($input['employee_code']),
                'user_id' => $input['user_id'],
                'active' => $input['active'],
            ])->save();

            AttendantStation::query()->updateOrCreate(
                ['attendant_id' => $attendant->id, 'branch_id' => $branchId],
                ['active' => true],
            );

            return $attendant;
        });
    }

    /**
     * An attendant from the company staff page: who they are, and which of
     * the stations this member manages they work at. Stations the member
     * does not manage keep what they had.
     *
     * @param  array{name: string, employee_code: string, phone: ?string, user_id: ?string, active: bool}  $input
     * @param  list<string>  $stations  where they work, among $managed
     * @param  list<string>  $managed  the stations this member may change
     */
    public function saveAttendantAcross(array $input, ?Attendant $attendant, array $stations, array $managed): Attendant
    {
        return DB::transaction(function () use ($input, $attendant, $stations, $managed) {
            if ($attendant === null || (! $attendant->active && $input['active'])) {
                $this->guardLimit(Permissions::ATTENDANTS_FEATURE, Attendant::query()->where('active', true)->count(), 'attendants');
            }

            $attendant ??= new Attendant;
            $attendant->fill([
                'name' => $input['name'],
                'phone' => $input['phone'],
                'employee_code' => strtoupper($input['employee_code']),
                'user_id' => $input['user_id'],
                'active' => $input['active'],
            ])->save();

            foreach ($managed as $branchId) {
                $works = in_array($branchId, $stations, true);
                $row = AttendantStation::query()->where('attendant_id', $attendant->id)->where('branch_id', $branchId)->first();
                if ($row === null && ! $works) {
                    continue;
                }
                AttendantStation::query()->updateOrCreate(
                    ['attendant_id' => $attendant->id, 'branch_id' => $branchId],
                    ['active' => $works],
                );
            }

            return $attendant;
        });
    }

    private function guardLimit(string $feature, int $current, string $label): void
    {
        $limit = $this->access->get()?->feature($feature);
        if ($limit === null) {
            return;
        }

        if ($current >= $limit) {
            throw ValidationException::withMessages(['code' => "The plan allows {$limit} {$label}."]);
        }
    }

    private function clock(string $value): void
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw ValidationException::withMessages(['starts_at' => 'Use a time like 06:00.']);
        }
    }
}
