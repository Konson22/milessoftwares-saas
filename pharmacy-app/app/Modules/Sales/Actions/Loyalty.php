<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\LoyaltyMovement;
use App\Modules\Sales\Models\LoyaltyProgram;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleReturn;
use App\Support\Industry;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Loyalty points of the industry being served. A sale naming a customer
 * earns points on what was paid for it (after any points redeemed);
 * points redeemed take their value off the sale as a discount (Sell spreads
 * it over the lines, so Finance and returns see what was really charged).
 * A void takes back all a sale earned and gives back what it redeemed; a
 * return takes back the points its share of the sale earned. A customer's
 * balance changes only here, with the customer locked.
 */
class Loyalty
{
    public function __construct(private readonly Industry $industry) {}

    /**
     * The program, while it runs.
     */
    public function program(): ?LoyaltyProgram
    {
        return LoyaltyProgram::query()
            ->where('application', $this->industry->code())
            ->where('active', true)
            ->first();
    }

    /**
     * Sets the program up, or changes it; points already earned keep.
     *
     * @throws ValidationException
     */
    public function configure(bool $active, int $spendPerPointMinor, int $pointValueMinor, User $by): LoyaltyProgram
    {
        if ($spendPerPointMinor < 1) {
            throw ValidationException::withMessages(['spend_per_point' => 'A point takes some spending to earn.']);
        }
        if ($pointValueMinor < 1) {
            throw ValidationException::withMessages(['point_value' => 'A point is worth something.']);
        }

        $program = LoyaltyProgram::query()->where('application', $this->industry->code())->first()
            ?? new LoyaltyProgram(['application' => $this->industry->code()]);
        $program->fill([
            'active' => $active,
            'spend_per_point_minor' => $spendPerPointMinor,
            'point_value_minor' => $pointValueMinor,
            'updated_by' => $by->id,
        ])->save();

        return $program;
    }

    /**
     * What redeeming these points takes off a sale of this total. The
     * customer is locked by the caller.
     *
     * @throws ValidationException
     */
    public function worth(?LoyaltyProgram $program, ?Customer $customer, int $points, int $totalMinor): int
    {
        if ($program === null) {
            throw ValidationException::withMessages(['redeem_points' => 'There is no loyalty program running.']);
        }
        if ($customer === null) {
            throw ValidationException::withMessages(['redeem_points' => 'Name the customer whose points these are.']);
        }
        if ($points > $customer->loyalty_points) {
            throw ValidationException::withMessages(['redeem_points' => "{$customer->name} has ".max(0, $customer->loyalty_points).' points.']);
        }

        $worth = $points * $program->point_value_minor;
        if ($worth > $totalMinor) {
            throw ValidationException::withMessages(['redeem_points' => "{$points} points are worth ".Money::format($worth).', more than the sale.']);
        }

        return $worth;
    }

    /**
     * Records what a sale redeemed and earned. The customer is locked by
     * the caller (Sell).
     */
    public function settle(?LoyaltyProgram $program, Sale $sale, Customer $customer, int $redeemed): void
    {
        if ($program === null) {
            return;
        }
        if ($redeemed > 0) {
            $this->move($customer, $sale, null, LoyaltyMovement::REDEEMED, -$redeemed);
        }
        if (($earned = $program->earns($sale->total_minor)) > 0) {
            $this->move($customer, $sale, null, LoyaltyMovement::EARNED, $earned);
        }
    }

    /**
     * A voided sale: what it earned comes off, what it redeemed goes back.
     */
    public function reverse(Sale $sale): void
    {
        $net = (int) LoyaltyMovement::query()->where('sale_id', $sale->id)->sum('points');
        if ($sale->customer_id === null || $net === 0) {
            return;
        }

        $this->move($this->lock($sale->customer_id), $sale, null, LoyaltyMovement::REVERSED, -$net);
    }

    /**
     * Part of a sale came back: the points that part earned come off.
     * Worked out on what all its returns add up to, so returns in parts
     * take back exactly what the sale earned.
     */
    public function returned(Sale $sale, SaleReturn $return): void
    {
        $movements = LoyaltyMovement::query()->where('sale_id', $sale->id)->get();
        $earned = (int) $movements->where('kind', LoyaltyMovement::EARNED)->sum('points');
        if ($sale->customer_id === null || $earned === 0 || $sale->total_minor === 0) {
            return;
        }

        $returnedMinor = (int) SaleReturn::query()->where('sale_id', $sale->id)->sum('total_minor');
        $due = intdiv($earned * min($returnedMinor, $sale->total_minor), $sale->total_minor);
        $taken = -(int) $movements->where('kind', LoyaltyMovement::RETURNED)->sum('points');

        if ($due > $taken) {
            $this->move($this->lock($sale->customer_id), $sale, $return, LoyaltyMovement::RETURNED, -($due - $taken));
        }
    }

    private function lock(string $customerId): Customer
    {
        return Customer::query()->whereKey($customerId)->lockForUpdate()->firstOrFail();
    }

    private function move(Customer $customer, Sale $sale, ?SaleReturn $return, string $kind, int $points): void
    {
        LoyaltyMovement::query()->create([
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'sale_return_id' => $return?->id,
            'kind' => $kind,
            'points' => $points,
        ]);
        $customer->forceFill(['loyalty_points' => $customer->loyalty_points + $points])->save();
    }
}
