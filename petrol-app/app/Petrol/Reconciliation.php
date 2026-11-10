<?php

namespace App\Petrol;

use App\Models\CashTransfer;
use App\Models\FuelDraw;
use App\Models\FuelSale;
use App\Models\MeterReading;
use App\Models\NozzleEvent;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Support\Money;
use App\Support\Volume;
use Illuminate\Support\Collection;

/**
 * Metered litres against recorded sales, and cash the shift should hold.
 * Figures are derived from the rows, then copied onto the shift at approval.
 */
class Reconciliation
{
    public function __construct(private readonly Cashbook $cash) {}

    /**
     * @return array{
     *     nozzles: list<array{nozzle_id: string, name: string, code: string, opening: int, closing: int|null, dispensed: int|null, sold: int, variance: int|null}>,
     *     methods: list<array{kind: string, amount_minor: int}>,
     *     sales_minor: int,
     *     sold_milli: int,
     *     drawer_minor: int,
     *     attendant_expected_minor: int,
     *     expected_minor: int,
     *     counted_minor: int|null,
     *     difference_minor: int|null,
     *     assignments: list<array{id: string, attendant: string, nozzle: string, expected_minor: int, counted_minor: int|null}>,
     *     cashByCurrency: list<array{currency: string, expected_minor: int, counted_minor: int|null}>
     * }
     */
    public function detail(Shift $shift): array
    {
        $shift->loadMissing(['readings.nozzle', 'assignments.attendant', 'assignments.nozzle', 'sales.payments']);
        $posted = $shift->sales->filter(fn (FuelSale $sale) => $sale->isPosted());

        $nozzles = [];
        foreach ($shift->readings->where('kind', MeterReading::OPENING) as $opening) {
            $closing = $shift->readings->first(fn (MeterReading $reading) => $reading->nozzle_id === $opening->nozzle_id && $reading->kind === MeterReading::CLOSING);
            $sold = (int) $posted->where('nozzle_id', $opening->nozzle_id)->sum('quantity_milli');
            $tested = (int) FuelDraw::query()
                ->where('shift_id', $shift->id)
                ->where('nozzle_id', $opening->nozzle_id)
                ->where('purpose', FuelDraw::TEST)
                ->whereIn('status', [FuelDraw::APPROVED, FuelDraw::RETURNED])
                ->sum('quantity_milli');
            $dispensed = $this->metered($shift, $opening, $closing);
            $nozzles[] = [
                'nozzle_id' => $opening->nozzle_id,
                'name' => $opening->nozzle->name,
                'code' => $opening->nozzle->code,
                'opening' => $opening->reading_milli,
                'closing' => $closing?->reading_milli,
                'dispensed' => $dispensed,
                'sold' => $sold,
                'tested' => $tested,
                'variance' => $dispensed === null ? null : $dispensed - $sold - $tested,
            ];
        }

        $books = Money::currency();
        $methods = [];
        foreach ($posted as $sale) {
            $saleCurrency = $sale->currency ?: $books;
            foreach ($sale->payments as $payment) {
                $paymentCurrency = $payment->currency ?: $saleCurrency;
                $key = $payment->kind.'|'.$paymentCurrency;
                $methods[$key] = [
                    'kind' => (string) $payment->kind,
                    'currency' => $paymentCurrency,
                    'amount_minor' => ($methods[$key]['amount_minor'] ?? 0) + (int) $payment->amount_minor,
                ];
            }
        }

        $assignments = [];
        $attendantExpected = 0;
        $countedKnown = $shift->counted_drawer_minor !== null;
        $attendantCounted = 0;

        $expectedByAssignment = $this->expectedByAssignment($posted, $shift);
        foreach ($shift->assignments as $assignment) {
            $expected = $expectedByAssignment[$assignment->id] ?? 0;
            $attendantExpected += $expected;
            if ($assignment->counted_cash_minor === null && $expected > 0) {
                $countedKnown = false;
            }
            $attendantCounted += $assignment->counted_cash_minor ?? 0;
            $assignments[] = [
                'id' => $assignment->id,
                'attendant' => $assignment->attendant->name,
                'nozzle' => $assignment->nozzle->code,
                'expected_minor' => $expected,
                'counted_minor' => $assignment->counted_cash_minor,
            ];
        }

        $drawer = $this->cash->drawer($shift->id);
        $expected = $drawer + $attendantExpected;
        $counted = $countedKnown ? ($shift->counted_drawer_minor ?? 0) + $attendantCounted : null;

        return [
            'nozzles' => $nozzles,
            'methods' => array_values($methods),
            'cashByCurrency' => $this->cashByCurrency($shift, $posted),
            'sales_minor' => (int) $posted->sum(fn (FuelSale $sale) => $sale->reporting_total_minor ?? $sale->total_minor),
            'sold_milli' => (int) $posted->sum('quantity_milli'),
            'drawer_minor' => $drawer,
            'attendant_expected_minor' => $attendantExpected,
            'expected_minor' => $expected,
            'counted_minor' => $counted,
            'difference_minor' => $counted === null ? null : $counted - $expected,
            'assignments' => $assignments,
        ];
    }

    private function metered(Shift $shift, MeterReading $opening, ?MeterReading $closing): ?int
    {
        if ($closing === null) {
            return null;
        }
        $event = NozzleEvent::query()->where('shift_id', $shift->id)->where('nozzle_id', $opening->nozzle_id)->whereNotNull('approved_at')->first();
        if ($event === null) {
            return $closing->reading_milli - $opening->reading_milli;
        }

        return max(0, (int) $event->previous_milli - $opening->reading_milli) + max(0, $closing->reading_milli - (int) $event->next_milli);
    }

    /**
     * Cash an attendant collected on this nozzle (not the drawer).
     *
     * @param  Collection<int, FuelSale>  $posted
     */
    public function attendantCash(Collection $posted, ShiftAssignment $assignment): int
    {
        $total = 0;

        foreach ($posted as $sale) {
            if ($sale->nozzle_id !== $assignment->nozzle_id || $sale->attendant_id !== $assignment->attendant_id || $sale->collected_by !== 'attendant') {
                continue;
            }

            $saleCurrency = $sale->currency ?: Money::currency();
            foreach ($sale->payments as $payment) {
                $paymentCurrency = $payment->currency ?: $saleCurrency;
                if ($payment->kind === PaymentMethod::CASH && $paymentCurrency === Money::currency()) {
                    $total += $payment->amount_minor;
                }
            }
        }

        return $total;
    }

    /**
     * Cash still with an attendant after confirmed handovers. A handover is
     * for the person, so it is taken off their nozzles once, not once per nozzle.
     *
     * @param  Collection<int, FuelSale>  $posted
     * @return array<string, int>
     */
    public function expectedByAssignment(Collection $posted, Shift $shift): array
    {
        $expected = [];
        $byAttendant = [];
        foreach ($shift->assignments as $assignment) {
            $expected[$assignment->id] = $this->attendantCash($posted, $assignment);
            $byAttendant[$assignment->attendant_id][] = $assignment->id;
        }

        foreach ($byAttendant as $attendantId => $ids) {
            $remitted = (int) CashTransfer::query()
                ->where('shift_id', $shift->id)
                ->where('source_type', CashTransfer::ATTENDANT)
                ->where('source_id', $attendantId)
                ->whereIn('status', [CashTransfer::CONFIRMED, CashTransfer::DISPUTED])
                ->where(fn ($query) => $query->whereNull('currency')->orWhere('currency', Money::currency()))
                ->sum('counted_minor');
            foreach ($ids as $id) {
                $take = min($expected[$id], $remitted);
                $expected[$id] -= $take;
                $remitted -= $take;
            }
        }

        return $expected;
    }

    /**
     * Physical cash expected in each currency. The workspace currency uses the counted drawer.
     * Another currency uses the count stored for that currency, and is not added to the first.
     *
     * @param  Collection<int, FuelSale>  $posted
     * @return list<array{currency: string, expected_minor: int, counted_minor: int|null}>
     */
    public function cashByCurrency(Shift $shift, Collection $posted): array
    {
        $books = Money::currency();
        $expected = $this->cash->totals($shift->id);
        foreach ($posted as $sale) {
            if ($sale->collected_by !== 'attendant') {
                continue;
            }
            $saleCurrency = $sale->currency ?: $books;
            foreach ($sale->payments as $payment) {
                $paymentCurrency = $payment->currency ?: $saleCurrency;
                if ($payment->kind !== PaymentMethod::CASH || $paymentCurrency === $books) {
                    continue;
                }
                $expected[$paymentCurrency] = ($expected[$paymentCurrency] ?? 0) + (int) $payment->amount_minor;
            }
        }

        $bookAttendant = 0;
        foreach ($shift->assignments as $assignment) {
            $bookAttendant += $this->attendantCash($posted, $assignment);
        }
        $expected[$books] = ($expected[$books] ?? 0) + $bookAttendant;
        $expected[$books] -= (int) CashTransfer::query()
            ->where('shift_id', $shift->id)
            ->where('source_type', CashTransfer::ATTENDANT)
            ->whereIn('status', [CashTransfer::CONFIRMED, CashTransfer::DISPUTED])
            ->where(fn ($query) => $query->whereNull('currency')->orWhere('currency', $books))
            ->sum('counted_minor');

        $foreignOut = CashTransfer::query()
            ->where('shift_id', $shift->id)
            ->where('source_type', CashTransfer::ATTENDANT)
            ->whereIn('status', [CashTransfer::CONFIRMED, CashTransfer::DISPUTED])
            ->whereNotNull('currency')
            ->where('currency', '!=', $books)
            ->get(['currency', 'counted_minor']);
        foreach ($foreignOut as $row) {
            $expected[$row->currency] = ($expected[$row->currency] ?? 0) - (int) $row->counted_minor;
        }

        $counts = is_array($shift->counted_by_currency) ? $shift->counted_by_currency : [];
        $rows = [];
        foreach ($expected as $currency => $amount) {
            if ($amount === 0 && $currency !== $books) {
                continue;
            }
            $counted = $currency === $books
                ? ($shift->counted_drawer_minor === null ? null : (int) $shift->counted_drawer_minor + $this->countedAttendants($shift))
                : (isset($counts[$currency]) ? (int) $counts[$currency] : null);
            $rows[] = [
                'currency' => $currency,
                'expected_minor' => $amount,
                'counted_minor' => $counted,
            ];
        }

        return $rows;
    }

    private function countedAttendants(Shift $shift): int
    {
        return (int) $shift->assignments->sum(fn (ShiftAssignment $assignment) => $assignment->counted_cash_minor ?? 0);
    }

    public function litres(int $milli): string
    {
        return Volume::toLitres($milli);
    }
}
