<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Actions\Loyalty;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\LoyaltyProgram;
use App\Support\Industry;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The loyalty program of the industry being served, and the customers
 * holding the most points (Loyalty).
 */
class LoyaltyController extends Controller
{
    public function __construct(private readonly Industry $industry) {}

    public function edit(): Response
    {
        $program = LoyaltyProgram::query()->where('application', $this->industry->code())->first();

        return Inertia::render($this->industry->page('loyalty/edit'), [
            'program' => $program === null ? null : [
                'active' => $program->active,
                'spendPerPoint' => Money::toMajor($program->spend_per_point_minor),
                'pointValue' => Money::toMajor($program->point_value_minor),
            ],
            'members' => Customer::query()
                ->where('application', $this->industry->code())
                ->where('loyalty_points', '>', 0)
                ->orderByDesc('loyalty_points')
                ->limit(20)
                ->get(['id', 'number', 'name', 'loyalty_points'])
                ->map(fn (Customer $customer) => [
                    'id' => $customer->id,
                    'number' => $customer->number,
                    'name' => $customer->name,
                    'points' => $customer->loyalty_points,
                ]),
            'outstandingPoints' => (int) Customer::query()
                ->where('application', $this->industry->code())
                ->where('loyalty_points', '>', 0)
                ->sum('loyalty_points'),
        ]);
    }

    public function update(Request $request, Loyalty $loyalty): RedirectResponse
    {
        $validated = $request->validate([
            'active' => ['boolean'],
            'spend_per_point' => ['required', 'string', Money::rule()],
            'point_value' => ['required', 'string', Money::rule()],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $program = $loyalty->configure(
            active: (bool) ($validated['active'] ?? false),
            spendPerPointMinor: Money::toMinor($validated['spend_per_point']),
            pointValueMinor: Money::toMinor($validated['point_value']),
            by: $user,
        );

        return back()->with('success', $program->active ? 'Loyalty program saved: customers earn points at the till.' : 'Loyalty program paused: points already earned keep.');
    }
}
