<?php

namespace Miles\PlatformClient\Money;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Adds a currency, changes its rate or removes it. Products route here
 * behind their own settings permission.
 */
final class EntryCurrencyController extends Controller
{
    public function store(Request $request, EntryCurrencies $currencies): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:3'],
            'rate' => ['required', 'string', 'max:24'],
        ]);

        $currency = $currencies->set($validated['code'], $validated['rate'], $request->user()?->getAuthIdentifier());

        return back()->with('success', $currency->code.' rate saved. Amounts already saved keep the rate they used.');
    }

    public function destroy(string $code, EntryCurrencies $currencies): RedirectResponse
    {
        $currencies->remove($code);

        return back()->with('success', strtoupper($code).' removed. Amounts already saved in it are kept.');
    }
}
