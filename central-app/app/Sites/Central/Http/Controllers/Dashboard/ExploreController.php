<?php

namespace App\Sites\Central\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Support\MarketingCatalog;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every listed ERP application, for anyone to start a business on: released
 * ones open a new business with their free trial already chosen.
 */
class ExploreController extends Controller
{
    public function __invoke(MarketingCatalog $catalog): Response
    {
        return Inertia::render('central/explore', [
            'trialDays' => (int) config('platform.marketing.trial_days'),
            'applications' => array_map(fn (array $product) => [
                'code' => $product['code'],
                'name' => $product['name'],
                'tagline' => $product['tagline'] ?? $product['description'],
                'comingSoon' => $product['status'] === 'planned',
            ], $catalog->products()),
        ]);
    }
}
