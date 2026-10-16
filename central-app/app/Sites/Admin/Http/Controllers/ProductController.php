<?php

namespace App\Sites\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Applications\Models\Application;
use App\Modules\Products\Models\ErpProduct;
use App\Modules\Subscriptions\Models\Plan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The ERP product catalog, read-only: products change through
 * database/erp-products.json and php artisan platform:erp-products, so
 * the release rules are checked in one place.
 */
class ProductController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/products/index', [
            'products' => ErpProduct::query()
                ->with(['applications' => fn ($query) => $query->orderBy('sort_order')->orderBy('name'), 'plans'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (ErpProduct $product) => [
                    'id' => $product->id,
                    'code' => $product->code,
                    'name' => $product->name,
                    'tagline' => $product->tagline,
                    'status' => $product->status->value,
                    'applications' => array_values($product->applications->map(fn (Application $application) => [
                        'name' => $application->name,
                        'status' => $application->status->value,
                        'requirement' => $product->requirements()[$application->id],
                    ])->all()),
                    'plans' => array_values($product->plans->map(fn (Plan $plan) => [
                        'code' => $plan->code,
                        'name' => $plan->name,
                        'status' => $plan->status,
                    ])->all()),
                ])
                ->all(),
        ]);
    }
}
