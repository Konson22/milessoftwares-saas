<?php

use Illuminate\Contracts\Queue\ShouldQueue;

/*
| Module boundaries (ADR-0003). Shared modules serve every industry module,
| so they never depend on one; industry modules (Pharmacy, later Retail)
| build on the shared ones. Shared modules announce what happened with
| events instead of calling an industry module.
*/

/**
 * Batches, order lines and sale lines still point at the medicine as their
 * item. Until a shared item master exists (Phase 3, master data), this is
 * the one Pharmacy class shared modules may use.
 */
const ITEM = 'App\Modules\Pharmacy\Models\Medicine';

// One rule per module: an array of targets combined with ignoring() lets
// violations through (checked against Pest 5.3).
foreach (['MasterData', 'Inventory', 'Procurement', 'Sales'] as $module) {
    arch("{$module} does not depend on the pharmacy")
        ->expect("App\\Modules\\{$module}")
        ->not->toUse('App\Modules\Pharmacy')
        ->ignoring(ITEM);
}

arch('master data depends on no other module')
    ->expect('App\Modules\MasterData')
    ->not->toUse(['App\Modules\Inventory', 'App\Modules\Procurement', 'App\Modules\Sales', 'App\Modules\Pharmacy']);

arch('inventory knows nothing of the documents that move stock')
    ->expect('App\Modules\Inventory')
    ->not->toUse(['App\Modules\Procurement', 'App\Modules\Sales']);

arch('procurement and sales stay apart')
    ->expect('App\Modules\Procurement')
    ->not->toUse('App\Modules\Sales')
    ->and('App\Modules\Sales')
    ->not->toUse('App\Modules\Procurement');

arch('workspace support is below every module')
    ->expect('App\Support')
    ->not->toUse('App\Modules');

arch('modules do not reach into the workspace pages')
    ->expect('App\Modules')
    ->not->toUse('App\Http\Controllers')
    ->ignoring('App\Http\Controllers\Controller');

arch('listeners run in the transaction that raised the event')
    ->expect('App\Modules\Pharmacy\Listeners')
    ->not->toImplement(ShouldQueue::class);
