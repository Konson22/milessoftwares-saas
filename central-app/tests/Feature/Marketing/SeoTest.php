<?php

use App\Modules\Marketing\Support\MarketingCatalog;
use App\Support\Hosts\PlatformHosts;

/*
 * Search engines: what the public site offers them (sitemap, robots rules,
 * metadata, Google tags) and what every other host keeps from them.
 */

function apexUrl(string $path = ''): string
{
    return PlatformHosts::url(PlatformHosts::root(), $path);
}

it('lists only live, unique public pages in the sitemap', function () {
    $xml = $this->get(apexUrl('sitemap.xml'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->getContent();

    $sitemap = simplexml_load_string($xml);
    expect($sitemap)->not->toBeFalse();

    $urls = [];
    foreach ($sitemap->url as $url) {
        $urls[] = (string) $url->loc;
    }

    expect($urls)->not->toBeEmpty()
        ->and(array_unique($urls))->toHaveCount(count($urls));

    // Every URL is on the apex and answers 200 itself: no redirects, no
    // missing pages, nothing kept out of search.
    foreach ($urls as $url) {
        expect(str_starts_with($url, apexUrl()))->toBeTrue($url);

        $this->get($url)
            ->assertOk()
            ->assertHeaderMissing('X-Robots-Tag')
            ->assertDontSee('name="robots" content="noindex"', false);
    }
});

it('gives each host its robots rules', function () {
    $this->get(apexUrl('robots.txt'))
        ->assertOk()
        ->assertSee("User-agent: *\nAllow: /", false)
        ->assertSee('Sitemap: '.apexUrl('sitemap.xml'), false);

    // Crawlable, so the noindex header on its pages is seen.
    $this->get(PlatformHosts::url(PlatformHosts::auth(), 'robots.txt'))
        ->assertOk()
        ->assertSee('Allow: /', false)
        ->assertDontSee('Sitemap:', false);

    // Workspaces' own robots.txt: tests/Feature/Tenancy/RouteSweepTest.php.
    foreach ([PlatformHosts::admin(), PlatformHosts::app()] as $host) {
        $this->get(PlatformHosts::url($host, 'robots.txt'))->assertSee("User-agent: *\nDisallow: /", false);
    }
});

it('keeps every signed-in host out of search results', function (string $host, string $path) {
    $host = match ($host) {
        'auth' => PlatformHosts::auth(),
        'admin' => PlatformHosts::admin(),
        'workspace' => 'acme.'.PlatformHosts::root(),
    };

    $this->get(PlatformHosts::url($host, $path))->assertHeader('X-Robots-Tag', 'noindex, nofollow');
})->with([
    'sign-in' => ['auth', 'login'],
    'sign-up' => ['auth', 'register'],
    'admin' => ['admin', ''],
    'workspace' => ['workspace', 'dashboard'],
]);

it('lets search engines index the public site', function () {
    $this->get(apexUrl())->assertOk()->assertHeaderMissing('X-Robots-Tag');
});

it('adds Google verification and Analytics to the public site only when configured', function () {
    $this->get(apexUrl())
        ->assertDontSee('google-site-verification', false)
        ->assertDontSee('googletagmanager.com/gtag', false);

    config([
        'services.google.site_verification' => 'verify-token-123',
        'services.google.analytics_id' => 'G-TEST12345',
    ]);

    $public = $this->get(apexUrl())->assertOk();
    $public->assertSee('<meta name="google-site-verification" content="verify-token-123">', false)
        ->assertSee('"G-TEST12345"', false)
        ->assertSee('https://www.googletagmanager.com/gtag/js?id=', false);
    expect($public->headers->get('Content-Security-Policy'))
        ->toContain('https://www.googletagmanager.com')
        ->toContain('https://*.google-analytics.com');

    // Never on the signed-in hosts.
    $signIn = $this->get(PlatformHosts::url(PlatformHosts::auth(), 'login'));
    $signIn->assertDontSee('google-site-verification', false)->assertDontSee('G-TEST12345', false);
    expect($signIn->headers->get('Content-Security-Policy'))->not->toContain('googletagmanager');
});

it('loads Google Tag Manager on the public site in place of the direct Analytics tag', function () {
    $this->get(apexUrl())->assertDontSee('gtm.js', false)->assertDontSee('googletagmanager.com/ns.html', false);

    config([
        'services.google.tag_manager_id' => 'GTM-TEST123',
        'services.google.analytics_id' => 'G-TEST12345',
    ]);

    $public = $this->get(apexUrl())->assertOk();
    $public->assertSee("'gtm.start'", false)
        ->assertSee('https://www.googletagmanager.com/gtm.js?id=', false)
        ->assertSee('"GTM-TEST123"', false)
        ->assertSee('googletagmanager.com/ns.html?id=GTM-TEST123', false)
        // GA4 runs as a tag in the container: no second, direct GA tag.
        ->assertDontSee('G-TEST12345', false);
    expect($public->headers->get('Content-Security-Policy'))
        ->toContain('script-src')->toContain('https://www.googletagmanager.com')
        ->toContain('frame-src \'self\' https://www.googletagmanager.com')
        ->toContain('https://*.google-analytics.com');

    $signIn = $this->get(PlatformHosts::url(PlatformHosts::auth(), 'login'));
    $signIn->assertDontSee('GTM-TEST123', false)->assertDontSee('gtm.js', false);
    expect($signIn->headers->get('Content-Security-Policy'))->not->toContain('googletagmanager');
});

it('gives every product page a search title and description of the right length', function () {
    $entries = app(MarketingCatalog::class)->catalog();
    expect($entries)->not->toBeEmpty();

    foreach ($entries as $entry) {
        // Up to about 60 characters before the release status, which Google
        // may cut short on a narrow screen without losing the product.
        $title = preg_replace('/ \((coming soon|early access)\)$/', '', $entry['seoTitle']).' - Tatook';
        $description = $entry['seoDescription'];

        expect(mb_strlen($title))->toBeLessThanOrEqual(60, $title)
            ->and(mb_strlen($description))->toBeGreaterThanOrEqual(90, $entry['slug'])
            ->and(mb_strlen($description))->toBeLessThanOrEqual(165, $entry['slug']);
    }

    expect(array_unique(array_column($entries, 'seoTitle')))->toHaveCount(count($entries))
        ->and(array_unique(array_column($entries, 'seoDescription')))->toHaveCount(count($entries));
});

it('says in the search title when a product is not released yet', function () {
    foreach (app(MarketingCatalog::class)->catalog() as $entry) {
        $suffix = ['coming_soon' => ' (coming soon)', 'early_access' => ' (early access)'][$entry['status']] ?? null;

        expect($suffix === null ? ! preg_match('/\((coming soon|early access)\)$/', $entry['seoTitle']) : str_ends_with($entry['seoTitle'], $suffix))
            ->toBeTrue($entry['seoTitle']);
    }
});

it('covers the search terms the business is found by', function () {
    $copy = mb_strtolower(implode(' ', array_map(
        fn (array $entry) => $entry['seoTitle'].' '.$entry['seoDescription'],
        app(MarketingCatalog::class)->catalog(),
    )));

    foreach (['erp', 'accounting', 'hr', 'payroll', 'inventory', 'procurement', 'project management'] as $term) {
        expect($copy)->toContain($term);
    }
});
