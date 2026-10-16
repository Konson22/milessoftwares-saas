<?php

namespace App\Sites\Public\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public pages on the apex host. Each page gets an absolute canonical URL
 * for search engines; titles and descriptions are set by the page.
 */
class PageController extends Controller
{
    public function __construct(private readonly MarketingCatalog $catalog) {}

    public function home(): Response
    {
        return Inertia::render('public/home', [
            'products' => $this->catalog->products(),
            'trialDays' => $this->trialDays(),
            'canonical' => PlatformHosts::url(PlatformHosts::root()),
        ]);
    }

    public function product(string $code): Response
    {
        $product = $this->catalog->product($code);
        abort_if($product === null, 404);

        return Inertia::render('public/product', [
            'product' => $product,
            'plans' => $this->catalog->plans($code),
            'trialDays' => $this->trialDays(),
            'canonical' => PlatformHosts::url(PlatformHosts::root(), "products/{$code}"),
        ]);
    }

    /**
     * ?app=<code> preselects an application (links from product pages and
     * elsewhere); anything else falls back to the first one.
     */
    public function start(Request $request): Response
    {
        $products = $this->catalog->trialable();
        $selected = $request->string('app')->toString();

        return Inertia::render('public/start', [
            'products' => $products,
            'selected' => in_array($selected, array_column($products, 'code'), true) ? $selected : null,
            'trialDays' => $this->trialDays(),
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'start'),
        ]);
    }

    public function pricing(): Response
    {
        return Inertia::render('public/pricing', [
            'plans' => $this->catalog->plans(),
            'trialDays' => $this->trialDays(),
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'pricing'),
        ]);
    }

    /** Length of the free trial a new workspace can start. */
    private function trialDays(): int
    {
        return (int) config('platform.marketing.trial_days');
    }
}
