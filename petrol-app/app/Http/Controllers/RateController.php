<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\ExchangeRate;
use App\Petrol\Currencies;
use App\Petrol\Permissions;
use App\Petrol\Rates;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RateController extends Controller
{
    use WorksInBranch;

    public function index(): Response
    {
        return Inertia::render('rates/index', [
            'books' => Money::currency(),
            'rates' => ExchangeRate::query()->where('branch_id', $this->branchId())->orderByDesc('effective_from')->limit(30)->get()->map(fn (ExchangeRate $rate) => [
                'id' => $rate->id,
                'base' => $rate->base_currency,
                'quote' => $rate->quote_currency,
                'rate' => Money::toMajor((int) $rate->rate_minor, $rate->quote_currency),
                'from' => $rate->effective_from->toDateTimeString(),
                'to' => $rate->effective_to?->toDateTimeString(),
            ]),
            'canManage' => $this->canHere(Permissions::RATES_MANAGE),
            'currencies' => app(Currencies::class)->accepted($this->branchId()),
        ]);
    }

    public function store(Request $request, Rates $rates): RedirectResponse
    {
        $validated = $request->validate([
            'base_currency' => ['required', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
            'quote_currency' => ['required', Rule::in(app(Currencies::class)->acceptedCodes($this->branchId()))],
            'rate' => ['required', 'string', 'max:16'],
        ]);
        $rates->set($this->branchId(), $validated['base_currency'], $validated['quote_currency'], $validated['rate'], $this->member($request));

        return back()->with('success', 'Exchange rate saved. Earlier sales keep the rate they used.');
    }
}
