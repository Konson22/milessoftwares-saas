<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\WorksInBranch;
use App\Models\Currency;
use App\Models\StationCurrency;
use App\Petrol\Currencies;
use App\Petrol\Permissions;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Miles\PlatformClient\Access\CurrentAccess;

class CurrencyController extends Controller
{
    use WorksInBranch;

    public function index(Currencies $currencies): Response
    {
        $this->authorizeView();

        return Inertia::render('currencies/index', [
            'catalog' => $currencies->catalog(),
            'accepted' => $currencies->acceptedCodes($this->branchId()),
            'configured' => StationCurrency::query()->where('branch_id', $this->branchId())->exists(),
            'books' => Money::currency(),
            'canManage' => $this->canManage(),
            'canEnable' => $this->canHere(Permissions::CURRENCIES_ENABLE),
        ]);
    }

    public function store(Request $request, Currencies $currencies): RedirectResponse
    {
        abort_unless($this->canManage(), 403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'size:3'],
            'symbol' => ['required', 'string', 'max:8'],
            'decimals' => ['required', 'integer', 'min:0', 'max:4'],
        ]);

        $currencies->create($validated['code'], $validated['name'], $validated['symbol'], (int) $validated['decimals'], $this->member($request));

        return back();
    }

    public function update(Request $request, Currency $currency, Currencies $currencies): RedirectResponse
    {
        abort_unless($this->canManage(), 403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'symbol' => ['required', 'string', 'max:8'],
            'decimals' => ['required', 'integer', 'min:0', 'max:4'],
            'active' => ['required', 'boolean'],
        ]);

        $currencies->update($currency, $validated['name'], $validated['symbol'], (int) $validated['decimals'], (bool) $validated['active'], $this->member($request));

        return back();
    }

    public function station(Request $request, Currencies $currencies): RedirectResponse
    {
        abort_unless($this->canHere(Permissions::CURRENCIES_ENABLE), 403);
        $validated = $request->validate([
            'enabled' => ['present', 'array'],
            'enabled.*' => ['string', 'size:3'],
        ]);

        $currencies->setStation($this->branchId(), $validated['enabled'], $this->member($request));

        return back();
    }

    private function authorizeView(): void
    {
        abort_unless($this->canHere(Permissions::CURRENCIES_VIEW) || $this->canManage(), 403);
    }

    private function canManage(): bool
    {
        $access = app(CurrentAccess::class)->get();

        return $access !== null && ($access->isOwner || $access->allowsIn(Permissions::CURRENCIES_MANAGE));
    }
}
