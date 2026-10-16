<?php

use App\Models\User;
use App\Modules\Inventory\Actions\InsufficientStock;
use App\Modules\Inventory\Actions\Stock;
use App\Modules\Inventory\Enums\AdjustmentReason;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Pharmacy\Models\Medicine;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->stock = app(Stock::class);
    $this->user = User::factory()->create();
    $this->juba = $this->platform()->branch('acme', 'Juba');
    $this->wau = $this->platform()->branch('acme', 'Wau');
    $this->medicine = $this->asTenant('acme', fn () => Medicine::factory()->create());
    $this->today = CarbonImmutable::parse('2026-10-12');
    $this->saleId = (string) Str::uuid();
});

function receive(string $branch, string $batch, string $expires, int $quantity): Batch
{
    return test()->asTenant('acme', fn () => test()->stock->add(
        test()->medicine, $branch, $batch, CarbonImmutable::parse($expires), $quantity, 100, test()->user,
    ));
}

it('adds stock to a batch and records why', function () {
    receive($this->juba, 'B1', '2027-01-31', 30);
    $batch = receive($this->juba, 'B1', '2027-01-31', 20);

    expect($batch->quantity_on_hand)->toBe(50);

    $movements = $this->asTenant('acme', fn () => StockMovement::query()->orderBy('created_at')->get());
    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('quantity')->all())->toBe([30, 20])
        ->and($movements[0]->type)->toBe(MovementType::Receipt)
        ->and($movements[0]->user_id)->toBe($this->user->id);
});

it('refuses the same batch number with another expiry', function () {
    receive($this->juba, 'B1', '2027-01-31', 30);

    expect(fn () => receive($this->juba, 'B1', '2027-06-30', 5))->toThrow(ValidationException::class);
});

it('sells the oldest expiry first, across batches, never expired stock', function () {
    receive($this->juba, 'EXPIRED', '2026-10-12', 100);   // expires today: not sellable
    receive($this->juba, 'LATE', '2027-06-30', 10);
    receive($this->juba, 'SOON', '2026-12-31', 4);

    $taken = $this->asTenant('acme', fn () => $this->stock->take($this->medicine, $this->juba, 6, $this->user, 'sale', $this->saleId, $this->today));

    expect(array_map(fn ($part) => [$part['batch']->batch_number, $part['quantity']], $taken))
        ->toBe([['SOON', 4], ['LATE', 2]])
        ->and($this->asTenant('acme', fn () => $this->stock->sellable($this->medicine, $this->juba, $this->today)))->toBe(8);
});

it('takes nothing when there is not enough sellable stock', function () {
    receive($this->juba, 'EXPIRED', '2026-10-01', 100);
    receive($this->juba, 'OK', '2027-06-30', 3);

    expect(fn () => $this->asTenant('acme', fn () => $this->stock->take($this->medicine, $this->juba, 5, $this->user, 'sale', $this->saleId, $this->today)))
        ->toThrow(InsufficientStock::class);

    expect($this->asTenant('acme', fn () => Batch::query()->where('batch_number', 'OK')->value('quantity_on_hand')))->toBe(3);
});

it('keeps branches apart', function () {
    receive($this->wau, 'W1', '2027-06-30', 50);

    expect(fn () => $this->asTenant('acme', fn () => $this->stock->take($this->medicine, $this->juba, 1, $this->user, 'sale', $this->saleId, $this->today)))
        ->toThrow(InsufficientStock::class);
});

it('adjusts stock by hand but never below zero', function () {
    $batch = receive($this->juba, 'B1', '2027-01-31', 10);

    $this->asTenant('acme', fn () => $this->stock->adjust($batch, -3, AdjustmentReason::Damaged, 'Dropped', $this->user));
    expect($batch->refresh()->quantity_on_hand)->toBe(7);

    expect(fn () => $this->asTenant('acme', fn () => $this->stock->adjust($batch, -8, AdjustmentReason::CountCorrection, null, $this->user)))
        ->toThrow(ValidationException::class);
    expect($batch->refresh()->quantity_on_hand)->toBe(7);

    $adjustment = $this->asTenant('acme', fn () => StockMovement::query()->where('type', MovementType::Adjustment)->sole());
    expect($adjustment)->quantity->toBe(-3)->reason->toBe('damaged')->note->toBe('Dropped');
});

it('puts stock back into the batch it came from', function () {
    receive($this->juba, 'B1', '2027-01-31', 10);
    $taken = $this->asTenant('acme', fn () => $this->stock->take($this->medicine, $this->juba, 4, $this->user, 'sale', $this->saleId, $this->today));

    $this->asTenant('acme', fn () => $this->stock->putBack($taken[0]['batch'], 4, $this->user, 'sale', $this->saleId));

    expect($taken[0]['batch']->refresh()->quantity_on_hand)->toBe(10);
});

it('never changes or deletes recorded movements', function () {
    receive($this->juba, 'B1', '2027-01-31', 10);
    $movement = $this->asTenant('acme', fn () => StockMovement::query()->sole());

    expect(fn () => $movement->forceFill(['quantity' => 99])->save())->toThrow(LogicException::class)
        ->and(fn () => $movement->delete())->toThrow(LogicException::class);
});

it('is protected by the database too (PostgreSQL)', function () {
    $batch = receive($this->juba, 'B1', '2027-01-31', 10);

    // Even code that bypasses the Stock service cannot make stock negative
    // or rewrite history.
    // Each attempt in its own savepoint: a failed statement aborts the
    // surrounding PostgreSQL transaction.
    expect(fn () => DB::transaction(fn () => DB::table('batches')->where('id', $batch->id)->update(['quantity_on_hand' => -1])))
        ->toThrow(QueryException::class, 'batches_quantity_not_negative');
    expect(fn () => DB::transaction(fn () => DB::table('stock_movements')->update(['quantity' => 99])))
        ->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('stock_movements')->delete()))
        ->toThrow(QueryException::class, 'append-only');

    expect($batch->refresh()->quantity_on_hand)->toBe(10);
})->skip(fn () => DB::getDriverName() !== 'pgsql', 'PostgreSQL constraints');
