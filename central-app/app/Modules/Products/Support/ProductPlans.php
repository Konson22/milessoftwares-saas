<?php

namespace App\Modules\Products\Support;

use App\Modules\Applications\Models\Application;
use App\Modules\Products\Enums\ProductStatus;

/**
 * What a plan selling an ERP product must cover: every application the
 * product requires (assigning the plan is what gives an organization the
 * product), nothing outside the product, and no active plan for a product
 * that is not live. Checked when staff save a plan and when the product
 * catalog changes underneath existing plans.
 */
final class ProductPlans
{
    /**
     * @param  array<string, string>  $requirements  application id => required|optional
     * @param  list<string>  $planApplicationIds
     * @return array<string, string> field => message; empty when the plan fits
     */
    public static function problems(string $productName, ProductStatus $status, array $requirements, array $planApplicationIds, string $planStatus): array
    {
        $problems = [];

        $missing = array_diff(array_keys(array_filter($requirements, fn (string $requirement) => $requirement === 'required')), $planApplicationIds);
        if ($missing !== []) {
            $problems['application_ids'] = "{$productName} needs ".self::names($missing).'.';
        }

        $outside = array_diff($planApplicationIds, array_keys($requirements));
        if ($outside !== [] && ! isset($problems['application_ids'])) {
            $problems['application_ids'] = self::names($outside).(count($outside) === 1 ? ' is' : ' are')." not part of {$productName}.";
        }

        if ($planStatus === 'active' && ! $status->isLive()) {
            $problems['erp_product_id'] = "{$productName} is {$status->value}; only a pilot or available product can have an active plan.";
        }

        return $problems;
    }

    /**
     * @param  array<int, string>  $ids
     */
    private static function names(array $ids): string
    {
        return implode(', ', Application::query()->whereKey(array_values($ids))->orderBy('name')->pluck('name')->all());
    }
}
