<?php

namespace Database\Seeders;

use App\Modules\Applications\Models\Application;
use App\Modules\Products\Models\ErpProduct;
use App\Modules\Subscriptions\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Plans for every registered product, for trying billing and trials by hand
 * on a developer machine. The prices are placeholders, not the price list.
 * A plan selling an ERP product covers what the product requires (plus its
 * optional applications for "Plus") and is active only while the product is
 * pilot or available, as admin → Plans enforces (ProductPlans). Products or
 * applications not registered here are skipped. Safe to run again: plans are
 * matched by code and brought back to these values.
 *
 *   php artisan db:seed --class=LocalPlansSeeder
 */
class LocalPlansSeeder extends Seeder
{
    private const TRIAL_DAYS = 14;

    private const CURRENCY = 'USD';

    /**
     * code => [name, description, ERP product code or null, application codes, monthly price in cents]
     *
     * @var array<string, array{0: string, 1: string, 2: string|null, 3: list<string>, 4: int}>
     */
    private const PLANS = [
        'pharmacy-standard' => ['Pharmacy Standard', 'The pharmacy: medicines, batches and expiry, purchasing and the counter.', 'pharmacy', ['pharmacy'], 2500],
        'pharmacy-plus' => ['Pharmacy Plus', 'The pharmacy with Finance & Accounting for the books.', 'pharmacy', ['pharmacy', 'finance'], 4000],
        'retail-standard' => ['Retail Standard', 'Retail and wholesale: barcode counter, price lists, invoices and stock.', 'retail_trading', ['retail'], 2500],
        'retail-plus' => ['Retail Plus', 'Retail and wholesale with Finance & Accounting for the books.', 'retail_trading', ['retail', 'finance'], 4000],
        'restaurant-standard' => ['Restaurant Standard', 'Tables, orders, the kitchen, bills and tills.', 'restaurant', ['restaurant'], 2500],
        'restaurant-plus' => ['Restaurant Plus', 'The restaurant with Finance & Accounting for the books.', 'restaurant', ['restaurant', 'finance'], 4000],
        'education-standard' => ['Education Standard', 'Admissions, classes, attendance, examinations and student fees.', 'education', ['school'], 3500],
        'education-plus' => ['Education Plus', 'The school with Finance & Accounting and HR.', 'education', ['school', 'finance', 'hr'], 6000],
        'finance-standard' => ['Finance Standard', 'Finance & Accounting on its own: ledger, invoices, bills and reports.', 'finance_accounting', ['finance'], 2000],
        'hr-standard' => ['HR Standard', 'Staff records, leave and payroll.', null, ['hr'], 1500],
        'logistics-standard' => ['Logistics Standard', 'Shipments, fleet and deliveries.', null, ['logistics'], 2000],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('LocalPlansSeeder only runs with APP_ENV=local.');
        }

        $applications = Application::query()->pluck('id', 'code');
        $products = ErpProduct::query()->get()->keyBy('code');
        $rows = [];

        foreach (self::PLANS as $code => [$name, $description, $productCode, $applicationCodes, $price]) {
            $product = $productCode === null ? null : $products->get($productCode);
            $missing = array_values(array_filter($applicationCodes, fn (string $application) => ! $applications->has($application)));

            if (($productCode !== null && $product === null) || $missing !== []) {
                $rows[] = [$code, 'skipped', $productCode === null || $product !== null ? 'not registered: '.implode(', ', $missing) : "no product {$productCode}"];

                continue;
            }

            // Without a product, the plan is on sale once its applications are.
            $live = $product !== null
                ? $product->status->isLive()
                : Application::query()->whereIn('code', $applicationCodes)->whereNotIn('status', ['beta', 'available', 'deprecated'])->doesntExist();
            $status = $live ? 'active' : 'draft';

            DB::transaction(function () use ($code, $name, $description, $product, $applicationCodes, $applications, $price, $status): void {
                $plan = Plan::query()->where('code', $code)->first() ?? new Plan;
                $plan->forceFill([
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'erp_product_id' => $product?->id,
                    'billing_interval' => 'monthly',
                    'price_minor' => $price,
                    'currency' => self::CURRENCY,
                    'trial_days' => self::TRIAL_DAYS,
                    'is_bundle' => count($applicationCodes) > 1,
                    'is_public' => true,
                    'status' => $status,
                ])->save();
                $plan->applications()->sync(array_map(fn (string $application) => $applications[$application], $applicationCodes));
            });

            $rows[] = [$code, $status, sprintf('%s %.2f / month', self::CURRENCY, $price / 100)];
        }

        $this->command->table(['Plan', 'Status', 'Price'], $rows);
    }
}
