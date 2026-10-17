<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| The item master migration on data shaped as before it: every medicine
| becomes an item under the same id, what pointed at the medicine points at
| the item, and rolling back gives the medicine its columns back.
*/

it('moves existing medicines and their stock onto items, and back', function () {
    // Rolled back in order: later migrations that point at items go first.
    /** @var Migration $packs */
    $packs = require database_path('migrations/2026_10_17_100000_create_item_packs.php');
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_16_100000_create_items_and_move_stock_onto_them.php');
    $packs->down();
    $migration->down();

    $organization = (string) Str::uuid();
    $medicine = (string) Str::uuid();
    $batch = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::table('medicines')->insert([
        'id' => $medicine, 'organization_id' => $organization, 'name' => 'Morphine', 'generic_name' => 'morphine sulfate',
        'form' => 'tablet', 'strength' => '10 mg', 'unit' => 'tablet', 'barcode' => '600999',
        'prescription_only' => true, 'controlled' => true, 'sell_price_minor' => 3000, 'reorder_level' => 5, 'active' => true,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('batches')->insert([
        'id' => $batch, 'organization_id' => $organization, 'branch_id' => (string) Str::uuid(), 'medicine_id' => $medicine,
        'batch_number' => 'M-1', 'expires_on' => '2027-03-31', 'unit_cost_minor' => 2000, 'quantity_on_hand' => 10,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $migration->up();

    expect((array) DB::table('items')->sole())->toMatchArray([
        'id' => $medicine, 'organization_id' => $organization, 'kind' => 'medicine', 'name' => 'Morphine 10 mg tablet',
        'alias' => 'morphine sulfate', 'unit' => 'tablet', 'barcode' => '600999', 'sell_price_minor' => 3000, 'reorder_level' => 5,
    ])
        ->and((bool) DB::table('items')->value('restricted'))->toBeTrue()
        ->and((bool) DB::table('items')->value('controlled'))->toBeTrue()
        ->and(DB::table('batches')->where('id', $batch)->value('item_id'))->toBe($medicine)
        ->and(DB::table('medicines')->where('id', $medicine)->value('name'))->toBe('Morphine');

    $migration->down();

    expect((array) DB::table('medicines')->sole())->toMatchArray([
        'id' => $medicine, 'generic_name' => 'morphine sulfate', 'unit' => 'tablet', 'barcode' => '600999',
        'sell_price_minor' => 3000, 'reorder_level' => 5,
    ])
        ->and((bool) DB::table('medicines')->value('controlled'))->toBeTrue()
        ->and(DB::table('batches')->where('id', $batch)->value('medicine_id'))->toBe($medicine);

    // Leave the schema as the other tests expect it.
    $migration->up();
    $packs->up();
});
