<?php

namespace App\Sites\Public\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Support\MarketingCatalog;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            'catalog' => $this->catalog->catalog(),
            'products' => $this->catalog->products(),
            'trialDays' => $this->trialDays(),
            'canonical' => PlatformHosts::url(PlatformHosts::root()),
        ]);
    }

    /** Every catalog product on one page, ERP solutions then applications. */
    public function products(): Response
    {
        return Inertia::render('public/products', [
            'catalog' => $this->catalog->catalog(),
            'trialDays' => $this->trialDays(),
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'products'),
        ]);
    }

    /**
     * An ERP solution's page. Old links by registry code (erp/retail_trading)
     * move to the solution's own address.
     */
    public function erpProduct(string $slug): Response|RedirectResponse
    {
        return $this->landing('erp', $slug)
            ?? $this->moved('erp', $slug)
            ?? abort(404);
    }

    public function application(string $slug): Response|RedirectResponse
    {
        return $this->landing('application', $slug) ?? abort(404);
    }

    /**
     * A registry application by code. When the catalog has a page for it
     * (finance → apps/finance) that page is the one to use.
     */
    public function product(string $code): Response|RedirectResponse
    {
        if (($moved = $this->moved('application', $code)) !== null) {
            return $moved;
        }

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
            // Which products offer each way of running them.
            'catalog' => $this->catalog->catalog(),
            'trialDays' => $this->trialDays(),
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'pricing'),
        ]);
    }

    /** Custom Solutions: custom development and customizing a Tatook product. */
    public function services(): Response
    {
        return Inertia::render('public/services', [
            'trialDays' => $this->trialDays(),
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'services'),
        ]);
    }

    public function platform(): Response
    {
        return Inertia::render('public/platform', [
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'platform'),
        ]);
    }

    /** Everything in the catalog that is not released yet. */
    public function comingSoon(): Response
    {
        return Inertia::render('public/coming-soon', [
            'catalog' => array_values(array_filter($this->catalog->catalog(), fn (array $entry) => $entry['status'] === 'coming_soon')),
            'trialDays' => $this->trialDays(),
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'coming-soon'),
        ]);
    }

    /** The Windows and Android apps, each with its download when the file is on the server. */
    public function downloads(): Response
    {
        return Inertia::render('public/downloads', [
            'apps' => array_map(function (array $app): array {
                $path = $this->downloadPath($app['file']);

                return [
                    'code' => $app['code'],
                    'platform' => $app['platform'],
                    'name' => $app['name'],
                    'summary' => $app['summary'],
                    'needs' => $app['needs'],
                    'version' => $app['version'],
                    'product' => $app['product'],
                    'url' => $path === null ? null : PlatformHosts::url(PlatformHosts::root(), "download/{$app['code']}"),
                    'size' => $path === null ? null : filesize($path),
                    'store' => $app['store'] ?? null,
                    'releases' => array_map(function (array $release) use ($app): array {
                        // The current version is the download above; an older one only by its own file.
                        $file = $release['version'] === $app['version'] ? null : ($release['file'] ?? null);
                        $path = $file === null ? null : $this->downloadPath($file);

                        return [
                            'version' => $release['version'],
                            'date' => $release['date'] ?? null,
                            'changes' => $release['changes'],
                            'url' => $path === null ? null : PlatformHosts::url(PlatformHosts::root(), "download/{$app['code']}/{$release['version']}"),
                        ];
                    }, $app['releases'] ?? []),
                ];
            }, $this->downloadApps()),
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'downloads'),
        ]);
    }

    /** One installer, under its own file name: the current version, or an older one still on the server. */
    public function download(string $code, ?string $version = null): BinaryFileResponse
    {
        $app = collect($this->downloadApps())->firstWhere('code', $code);
        abort_if($app === null, 404);

        $file = $version === null || $version === $app['version']
            ? $app['file']
            : (collect($app['releases'] ?? [])->firstWhere('version', $version)['file'] ?? null);
        $path = $file === null ? null : $this->downloadPath($file);
        abort_if($path === null, 404);

        $type = $app['platform'] === 'android' ? 'application/vnd.android.package-archive' : 'application/vnd.microsoft.portable-executable';

        return response()->download($path, basename($file), ['Content-Type' => $type]);
    }

    public function about(): Response
    {
        return Inertia::render('public/about', [
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'about'),
        ]);
    }

    public function privacy(): Response
    {
        return Inertia::render('public/privacy', [
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'privacy'),
        ]);
    }

    /** The Mataem Plus restaurant app's privacy policy, the URL on its Play Store listing. */
    public function privacyMataemPlus(): Response
    {
        return Inertia::render('public/privacy-mataem-plus', [
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'privacy/mataem-plus'),
        ]);
    }

    /** The Miles Pharma POS mobile app's privacy policy, the URL for its store listing. */
    public function privacyMilesPharmacy(): Response
    {
        return Inertia::render('public/privacy-miles-pharmacy', [
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'privacy/miles-pharmacy'),
        ]);
    }

    public function terms(): Response
    {
        return Inertia::render('public/terms', [
            'trialDays' => $this->trialDays(),
            'canonical' => PlatformHosts::url(PlatformHosts::root(), 'terms'),
        ]);
    }

    /**
     * One product's landing page, with the public plans that sell it.
     */
    private function landing(string $category, string $slug): ?Response
    {
        $entry = $this->catalog->catalogEntry($category, $slug);

        if ($entry === null) {
            return null;
        }

        $registry = $entry['registry'] ?? null;
        $plans = match ($registry['type'] ?? null) {
            'erp' => $this->catalog->plans(productCode: $registry['code']),
            'application' => $this->catalog->plans($registry['code']),
            default => [],
        };

        return Inertia::render(
            $category === 'application' && $slug === 'finance' ? 'public/finance' : 'public/landing',
            [
                'product' => $entry,
                'plans' => $entry['status'] === 'coming_soon' ? [] : $plans,
                'trialDays' => $this->trialDays(),
                'canonical' => PlatformHosts::url(PlatformHosts::root(), $entry['url']),
            ],
        );
    }

    private function moved(string $type, string $code): ?RedirectResponse
    {
        $url = $this->catalog->catalogUrlFor($type, $code);
        $requested = ($type === 'erp' ? 'erp/' : 'products/').$code;

        return $url === null || $url === $requested ? null : redirect(PlatformHosts::url(PlatformHosts::root(), $url), 301);
    }

    /**
     * @return list<array{code: string, platform: string, name: string, summary: string, needs: string, file: string, version: string, product: string, store?: string|null, releases?: list<array{version: string, date?: string|null, file?: string, changes: list<string>}>}>
     */
    private function downloadApps(): array
    {
        return array_values((array) config('platform.downloads.apps'));
    }

    /** The installer on disk, or null while it is not there. */
    private function downloadPath(string $file): ?string
    {
        $path = rtrim((string) config('platform.downloads.path'), '/\\').DIRECTORY_SEPARATOR.basename($file);

        return is_file($path) ? $path : null;
    }

    /** Length of the free trial a workspace can start. */
    private function trialDays(): int
    {
        return (int) config('platform.marketing.trial_days');
    }
}
