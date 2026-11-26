<?php

namespace Database\Seeders;

use App\Models\DiningTable;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Miles\PlatformClient\Api\Organization;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use RuntimeException;

/**
 * Royal Rest's menu and tables only: no staff, orders, bills or stock (for
 * those, RoyalRestSeeder). The dishes, drinks and table layout are
 * RoyalRestSeeder's; tables go to the branches the file names (by code).
 * Safe to run again: a dish or table already there is kept.
 *
 * DEMO_BUSINESS_FILE holds {organization: {...}, branches: {CODE: id}}, as
 * central's RoyalRestSeeder writes it.
 *
 *   DEMO_BUSINESS_FILE=/tmp/royal-rest.json php artisan db:seed --class=RoyalRestMenuSeeder
 */
class RoyalRestMenuSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('RoyalRestMenuSeeder only runs with APP_ENV=local.');
        }

        $file = (string) getenv('DEMO_BUSINESS_FILE');
        if ($file === '' || ! is_file($file)) {
            throw new RuntimeException('Set DEMO_BUSINESS_FILE to the Royal Rest JSON file.');
        }
        $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $organization = Organization::fromArray($data['organization']);
        /** @var array<string, string> $branches */
        $branches = $data['branches'];

        app(CurrentTenant::class)->run($organization, function () use ($branches) {
            [$dishes, $drinks, $tables] = DB::transaction(function () use ($branches) {
                $dishes = 0;
                foreach (RoyalRestSeeder::DISHES as [$name, $section, $price]) {
                    $item = MenuItem::query()->firstOrCreate(
                        ['kind' => MenuItem::DISH, 'name' => $name],
                        ['section' => $section, 'unit' => 'plate', 'price_minor' => $price * 100, 'active' => true],
                    );
                    $dishes += (int) $item->wasRecentlyCreated;
                }

                $drinks = 0;
                foreach (RoyalRestSeeder::DRINKS as [$name, $unit, $price, , $reorder, , $barcode]) {
                    $item = MenuItem::query()->firstOrCreate(
                        ['kind' => MenuItem::DRINK, 'name' => $name],
                        ['section' => 'Cold drinks', 'unit' => $unit, 'price_minor' => $price * 100, 'barcode' => $barcode, 'reorder_level' => $reorder, 'active' => true],
                    );
                    $drinks += (int) $item->wasRecentlyCreated;
                }

                $tables = 0;
                foreach (RoyalRestSeeder::BRANCHES as $code => [$layout]) {
                    if (! isset($branches[$code])) {
                        continue;
                    }
                    foreach ($layout as [$name, $area, $seats]) {
                        $table = DiningTable::query()->firstOrCreate(
                            ['branch_id' => $branches[$code], 'name' => $name],
                            ['area' => $area, 'seats' => $seats, 'active' => true],
                        );
                        $tables += (int) $table->wasRecentlyCreated;
                    }
                }

                return [$dishes, $drinks, $tables];
            });

            $this->command?->info(sprintf('Royal Rest: %d dishes, %d drinks and %d tables added (%s).', $dishes, $drinks, $tables, implode(', ', array_keys(array_intersect_key(RoyalRestSeeder::BRANCHES, $branches)))));
        });
    }
}
