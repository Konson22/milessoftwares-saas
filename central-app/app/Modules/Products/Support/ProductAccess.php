<?php

namespace App\Modules\Products\Support;

use App\Modules\Applications\Models\Application;
use App\Modules\Entitlements\Models\Entitlement;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Products\Enums\ProductStatus;
use App\Modules\Products\Models\ErpProduct;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * The ERP products an organization holds. Products carry no entitlements
 * of their own: an organization holds a live product while it is entitled
 * to every application the product requires. Subscriptions, trials and
 * manual grants keep working exactly as before, and the product view can
 * never contradict what the applications themselves allow.
 */
final class ProductAccess
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return Collection<int, ErpProduct>
     */
    public function for(Organization $organization): Collection
    {
        /** @var list<string> $entitled */
        $entitled = $this->tenant->run($organization, fn () => Entitlement::query()
            ->current()
            ->whereNull('feature_key')
            ->pluck('application_id')
            ->map(fn (mixed $id) => (string) $id)
            ->unique()
            ->values()
            ->all());

        return ErpProduct::query()
            ->whereIn('status', array_map(fn (ProductStatus $status) => $status->value, array_filter(ProductStatus::cases(), fn (ProductStatus $status) => $status->isLive())))
            ->with('requiredApplications:id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (ErpProduct $product) => $product->requiredApplications->isNotEmpty()
                && $product->requiredApplications->every(fn (Application $application) => in_array($application->id, $entitled, true)))
            ->values();
    }
}
