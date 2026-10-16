<?php

namespace App\Modules\Marketing\Support;

use App\Modules\Applications\Enums\ApplicationKind;
use App\Modules\Applications\Enums\ApplicationStatus;
use App\Modules\Applications\Models\Application;
use App\Modules\Products\Enums\ProductStatus;
use App\Modules\Products\Models\ErpProduct;
use App\Modules\Subscriptions\Models\Plan;

/**
 * What the public site shows, read straight from the application registry
 * and the plan catalog, so marketing never drifts from what is sold.
 * Marketing copy lives in applications.capabilities.marketing:
 * { "tagline": "...", "features": ["...", "..."], "unlisted": false }.
 * Unlisted applications (e.g. the bundled sample product) never appear.
 */
final class MarketingCatalog
{
    private const LISTED = [ApplicationStatus::Available, ApplicationStatus::Beta, ApplicationStatus::Planned];

    /**
     * @return list<array{code: string, name: string, tagline: string|null, description: string|null, status: string, features: list<string>}>
     */
    public function products(): array
    {
        return array_values(Application::query()
            ->where('kind', '!=', ApplicationKind::Platform->value)
            ->whereIn('status', array_map(fn (ApplicationStatus $status) => $status->value, self::LISTED))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->reject(fn (Application $application) => $this->unlisted($application))
            ->map(fn (Application $application) => $this->present($application))
            ->all());
    }

    /**
     * Listed applications a new workspace can try today: released or in
     * beta, not merely announced.
     *
     * @return list<array{code: string, name: string, tagline: string|null, description: string|null, status: string, features: list<string>}>
     */
    public function trialable(): array
    {
        $open = [ApplicationStatus::Available->value, ApplicationStatus::Beta->value];

        return array_values(array_filter($this->products(), fn (array $product) => in_array($product['status'], $open, true)));
    }

    /**
     * @return array{code: string, name: string, tagline: string|null, description: string|null, status: string, features: list<string>}|null
     */
    public function product(string $code): ?array
    {
        $application = Application::query()
            ->where('code', $code)
            ->where('kind', '!=', ApplicationKind::Platform->value)
            ->whereIn('status', array_map(fn (ApplicationStatus $status) => $status->value, self::LISTED))
            ->first();

        return $application === null || $this->unlisted($application) ? null : $this->present($application);
    }

    /**
     * ERP products the site lists, in their real release status: planned
     * ones are "coming soon", pilots are marked as such, retired ones are
     * gone. The status comes from database/erp-products.json, never from
     * marketing copy.
     *
     * @return list<array{code: string, name: string, tagline: string|null, description: string|null, status: string, applications: list<array{code: string, name: string, requirement: string, listed: bool}>}>
     */
    public function erpProducts(): array
    {
        return array_values(ErpProduct::query()
            ->whereIn('status', [ProductStatus::Planned->value, ProductStatus::Pilot->value, ProductStatus::Available->value])
            ->with('applications')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ErpProduct $product) => $this->presentProduct($product))
            ->all());
    }

    /**
     * @return array{code: string, name: string, tagline: string|null, description: string|null, status: string, applications: list<array{code: string, name: string, requirement: string, listed: bool}>}|null
     */
    public function erpProduct(string $code): ?array
    {
        return array_values(array_filter($this->erpProducts(), fn (array $product) => $product['code'] === $code))[0] ?? null;
    }

    /**
     * Public, active plans, optionally only those covering one application
     * or selling one ERP product.
     *
     * @return list<array{code: string, name: string, description: string|null, price: string, interval: string, trialDays: int, isBundle: bool, applications: list<string>, product: array{code: string, name: string, status: string}|null}>
     */
    public function plans(?string $applicationCode = null, ?string $productCode = null): array
    {
        return array_values(Plan::query()
            ->where('status', 'active')
            ->where('is_public', true)
            ->when($applicationCode !== null, fn ($query) => $query->whereHas('applications', fn ($apps) => $apps->where('code', $applicationCode)))
            ->when($productCode !== null, fn ($query) => $query->whereHas('product', fn ($products) => $products->where('code', $productCode)))
            ->with(['applications:id,name', 'product'])
            ->orderBy('price_minor')
            ->get()
            ->map(fn (Plan $plan) => [
                'code' => $plan->code,
                'name' => $plan->name,
                'description' => $plan->description,
                'price' => $plan->price_minor === 0 ? 'Free' : $plan->currency.' '.number_format($plan->price_minor / 100, 2),
                'interval' => $plan->billing_interval,
                'trialDays' => $plan->trial_days,
                'isBundle' => $plan->is_bundle,
                'applications' => array_values($plan->applications->pluck('name')->all()),
                'product' => $plan->product === null ? null : [
                    'code' => $plan->product->code,
                    'name' => $plan->product->name,
                    'status' => $plan->product->status->value,
                ],
            ])
            ->all());
    }

    /**
     * @return array{code: string, name: string, tagline: string|null, description: string|null, status: string, applications: list<array{code: string, name: string, requirement: string, listed: bool}>}
     */
    private function presentProduct(ErpProduct $product): array
    {
        $listed = array_column($this->products(), 'code');
        $requirements = $product->requirements();

        return [
            'code' => $product->code,
            'name' => $product->name,
            'tagline' => $product->tagline,
            'description' => $product->description,
            'status' => $product->status->value,
            'applications' => array_values($product->applications
                ->sortBy(fn (Application $application) => [$requirements[$application->id] === 'required' ? 0 : 1, $application->sort_order, $application->name])
                ->map(fn (Application $application) => [
                    'code' => $application->code,
                    'name' => $application->name,
                    'requirement' => $requirements[$application->id],
                    'listed' => in_array($application->code, $listed, true),
                ])
                ->all()),
        ];
    }

    private function unlisted(Application $application): bool
    {
        return (($application->capabilities ?? [])['marketing']['unlisted'] ?? false) === true;
    }

    /**
     * @return array{code: string, name: string, tagline: string|null, description: string|null, status: string, features: list<string>}
     */
    private function present(Application $application): array
    {
        $marketing = (array) (($application->capabilities ?? [])['marketing'] ?? []);

        return [
            'code' => $application->code,
            'name' => $application->name,
            'tagline' => isset($marketing['tagline']) && is_string($marketing['tagline']) ? $marketing['tagline'] : null,
            'description' => $application->description,
            'status' => $application->status->value,
            'features' => array_values(array_filter((array) ($marketing['features'] ?? []), 'is_string')),
        ];
    }
}
