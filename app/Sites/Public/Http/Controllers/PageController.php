<?php

namespace App\Sites\Public\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Support\Hosts\PlatformHosts;
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
            'canonical' => PlatformHosts::url(PlatformHosts::root(), "products/{$code}"),
        ]);
    }

    public function pricing(): Response
    {
        return Inertia::render('public/pricing', [
            'plans' => $this->catalog->plans(),
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'pricing'),
        ]);
    }
}
