<?php

namespace App\Sites\Public\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    public function sitemap(MarketingCatalog $catalog): Response
    {
        $paths = ['', 'start', 'pricing', 'contact'];

        foreach ($catalog->products() as $product) {
            $paths[] = 'products/'.$product['code'];
        }

        foreach ($catalog->erpProducts() as $product) {
            $paths[] = 'erp/'.$product['code'];
        }

        $urls = array_map(
            fn (string $path) => '<url><loc>'.e(PlatformHosts::url(PlatformHosts::root(), $path)).'</loc></url>',
            $paths,
        );

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $urls).'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Only the apex is meant for search engines; every other host serves
     * signed-in areas and answers robots.txt with a blanket disallow.
     */
    public function robots(): Response
    {
        $body = "User-agent: *\nAllow: /\nSitemap: ".PlatformHosts::url(PlatformHosts::root(), 'sitemap.xml')."\n";

        return response($body, 200, ['Content-Type' => 'text/plain']);
    }

    public function disallowAll(): Response
    {
        return response("User-agent: *\nDisallow: /\n", 200, ['Content-Type' => 'text/plain']);
    }
}
