<?php

namespace App\Http\Controllers;

use App\Support\Industry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The currencies amounts may be typed in, and their rates. Saving goes to
 * Miles\PlatformClient\Money\EntryCurrencyController.
 */
class CurrencyController extends Controller
{
    public function index(Request $request, Industry $industry): Response
    {
        return Inertia::render($industry->page('currencies'), [
            'canManage' => (bool) $request->user()?->can($industry->code().'.sales.settings'),
        ]);
    }
}
