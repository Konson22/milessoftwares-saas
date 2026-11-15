<?php

namespace App\Http\Middleware;

use App\Mel\Models\Dimension;
use App\Mel\Models\DimensionCategory;
use App\Mel\Models\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The first time an organization opens M&E: its settings, with the
 * organization's name, currency and time zone, and the starting
 * disaggregation dimensions. Runs after EnterWorkspace; afterwards it is one
 * read per request.
 */
final class SetUpMel
{
    /**
     * Starting dimensions, changed freely in M&E settings. Age groups are
     * bands: M&E never asks for dates of birth.
     */
    private const DIMENSIONS = [
        'Sex' => ['Female', 'Male'],
        'Age group' => ['Under 5', '5–17', '18–49', '50 and over'],
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Settings::query()->exists()) {
            DB::transaction(function () {
                Settings::current();

                if (Dimension::query()->exists()) {
                    return;
                }
                foreach (self::DIMENSIONS as $name => $categories) {
                    $dimension = Dimension::query()->create(['name' => $name]);
                    foreach ($categories as $i => $category) {
                        DimensionCategory::query()->create(['dimension_id' => $dimension->id, 'name' => $category, 'sort_order' => $i]);
                    }
                }
            });
        }

        return $next($request);
    }
}
