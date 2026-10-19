<?php

/*
|--------------------------------------------------------------------------
| Industry modules of the workspace application
|--------------------------------------------------------------------------
|
| One entry per application this deployment serves (platform-client:
| PLATFORM_APPLICATION and PLATFORM_HOSTED). The shared modules ask
| App\Support\Industry for the one being served: which items its screens
| and counter handle, and its counter's limits.
|
*/

return [

    'pharmacy' => [
        'name' => env('APP_NAME', 'Pharmacy'),
        // Items the pharmacy keeps: medicines (Pharmacy module).
        'item_kinds' => ['medicine'],
        // The most a member allowed to give discounts (pharmacy.sales.discount)
        // may take off one line at the counter, in percent.
        'max_discount_percent' => (int) env('PHARMACY_MAX_DISCOUNT_PERCENT', 20),
    ],

    'retail' => [
        'name' => 'Retail',
        // Goods on a shop's shelves (Retail module).
        'item_kinds' => ['product'],
        'max_discount_percent' => (int) env('RETAIL_MAX_DISCOUNT_PERCENT', 20),
    ],

];
