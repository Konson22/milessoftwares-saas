<?php

use App\Sites\Public\Http\Controllers\LeadController;
use App\Sites\Public\Http\Controllers\PageController;
use App\Sites\Public\Http\Controllers\SeoController;
use App\Sites\Public\Http\Controllers\WwwRedirectController;
use App\Support\Hosts\PlatformHosts;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public site
|--------------------------------------------------------------------------
|
| milessoftwares.com and www. Pages live in resources/js/sites/public.
| Route files load in the order public, admin, central (bootstrap/app.php):
| central ends with the {tenant} wildcard, which must come last.
|
*/

// milessoftwares.com — public marketing site
Route::domain(PlatformHosts::root())->group(function () {
    Route::get('/', [PageController::class, 'home'])->name('home');

    Route::name('marketing.')->group(function () {
        Route::get('products/{code}', [PageController::class, 'product'])->where('code', '[a-z][a-z0-9_]*')->name('product');
        Route::get('pricing', [PageController::class, 'pricing'])->name('pricing');

        // Public forms: rate limited per IP in front of the spam checks.
        Route::get('demo', [LeadController::class, 'demoForm'])->name('demo');
        Route::post('demo', [LeadController::class, 'storeDemo'])->middleware('throttle:5,60')->name('demo.store');
        Route::get('contact', [LeadController::class, 'contactForm'])->name('contact');
        Route::post('contact', [LeadController::class, 'storeContact'])->middleware('throttle:5,60')->name('contact.store');
        Route::inertia('thanks', 'public/thanks')->name('thanks');

        Route::get('sitemap.xml', [SeoController::class, 'sitemap'])->name('sitemap');
        Route::get('robots.txt', [SeoController::class, 'robots'])->name('robots');
    });
});

// www.milessoftwares.com — permanent redirect to the apex, path preserved
Route::domain(PlatformHosts::www())->group(function () {
    Route::get('{path?}', WwwRedirectController::class)->where('path', '.*');
});
