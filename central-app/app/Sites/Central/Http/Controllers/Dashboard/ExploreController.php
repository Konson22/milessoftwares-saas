<?php

namespace App\Sites\Central\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Support\MarketingCatalog;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every listed ERP application: released ones start their free trial, in the
 * current business, or in a new one for someone without a business.
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
