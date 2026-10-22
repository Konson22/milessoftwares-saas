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
     * catalog(), once per instance: the sitemap and redirects ask repeatedly.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $catalog = null;

    /**
     * @return list<array{code: string, name: string, tagline: string|null, description: string|null, status: string, features: list<string>, trialEnabled: bool}>
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
     * Listed applications a workspace can try today: released or in beta,
     * not merely announced, and with trials allowed by staff. Every path
     * that starts a trial checks against this list.
     *
     * @return list<array{code: string, name: string, tagline: string|null, description: string|null, status: string, features: list<string>, trialEnabled: bool}>
     */
    public function trialable(): array
    {
        $open = [ApplicationStatus::Available->value, ApplicationStatus::Beta->value];

        return array_values(array_filter($this->products(), fn (array $product) => $product['trialEnabled'] && in_array($product['status'], $open, true)));
    }

    /**
     * The public product catalog: ERP solutions and business applications,
     * worded in resources/content/marketing-catalog.json and placed in their
     * real release status from the registry. An entry the registry does not
     * know (or has unlisted) is coming soon; a retired one is not shown.
     * Trial eligibility follows trialable(): an ERP is tried through its
     * own industry application (Finance comes alongside, see
     * ManageEntitlements::startTrial).
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $applications = array_column($this->products(), null, 'code');
        $erpProducts = array_column($this->erpProducts(), null, 'code');
        $trialable = array_column($this->trialable(), 'code');
        $retired = [
            'erp' => ErpProduct::query()->where('status', ProductStatus::Retired->value)->pluck('code')->all(),
            'application' => Application::query()->where('status', ApplicationStatus::Retired->value)->pluck('code')->all(),
        ];

        $entries = [];

        foreach (self::content() as $entry) {
            $registry = $entry['registry'] ?? null;
            $type = $registry['type'] ?? null;
            $code = $registry['code'] ?? null;

            if ($type !== null && in_array($code, $retired[$type] ?? [], true)) {
                continue;
            }

            [$status, $trialCode, $includes] = match ($type) {
                'erp' => $this->erpStanding($erpProducts[$code] ?? null),
                'application' => [$this->applicationStatus($applications[$code]['status'] ?? null), $code, []],
                default => ['coming_soon', null, []],
            };

            $entries[] = [
                ...$entry,
                'url' => self::url($entry['category'], $entry['slug']),
                'status' => $status,
                'includes' => $includes,
                'trial' => $status !== 'coming_soon' && $trialCode !== null && in_array($trialCode, $trialable, true) ? $trialCode : null,
            ];
        }

        return $this->catalog = $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function catalogEntry(string $category, string $slug): ?array
    {
        foreach ($this->catalog() as $entry) {
            if ($entry['category'] === $category && $entry['slug'] === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Where a registry code's public page now lives (old /erp/{code} and
     * /products/{code} links), or null when no shown catalog entry names it.
     */
    public function catalogUrlFor(string $type, string $code): ?string
    {
        foreach ($this->catalog() as $entry) {
            if (($entry['registry']['type'] ?? null) === $type && ($entry['registry']['code'] ?? null) === $code) {
                return $entry['url'];
            }
        }

        return null;
    }

    public static function url(string $category, string $slug): string
    {
        return ($category === 'erp' ? 'erp/' : 'apps/').$slug;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function content(): array
    {
        static $content = null;

        return $content ??= (array) json_decode((string) file_get_contents(resource_path('content/marketing-catalog.json')), true, flags: JSON_THROW_ON_ERROR)['products'];
    }

    /**
     * An ERP product's public status, the application its trial starts,
     * and what it is made of.
     *
     * @param  array<string, mixed>|null  $product
     * @return array{0: string, 1: string|null, 2: list<array<string, mixed>>}
     */
    private function erpStanding(?array $product): array
    {
        if ($product === null) {
            return ['coming_soon', null, []];
        }

        $status = match ($product['status']) {
            ProductStatus::Available->value => 'available',
            ProductStatus::Pilot->value => 'early_access',
            default => 'coming_soon',
        };

        // The product's own application, not the shared modules it requires.
        $shared = Application::query()->where('kind', 'shared_module')->pluck('code')->all();
        $own = array_values(array_filter($product['applications'], fn (array $application) => $application['requirement'] === 'required' && ! in_array($application['code'], $shared, true)));

        return [$status, $own[0]['code'] ?? null, $product['applications']];
    }

    private function applicationStatus(?string $status): string
    {
        return match ($status) {
            ApplicationStatus::Available->value, ApplicationStatus::Deprecated->value => 'available',
            ApplicationStatus::Beta->value => 'early_access',
            default => 'coming_soon',
        };
    }

    /**
     * @return array{code: string, name: string, tagline: string|null, description: string|null, status: string, features: list<string>, trialEnabled: bool}|null
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
     * @return array{code: string, name: string, tagline: string|null, description: string|null, status: string, features: list<string>, trialEnabled: bool}
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
            'trialEnabled' => $application->trial_enabled,
        ];
    }
}
