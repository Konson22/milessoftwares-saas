<?php

namespace App\Sites\Public\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WwwRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return redirect()->away(PlatformHosts::url(PlatformHosts::root(), $request->getRequestUri()), 301);
    }
}
