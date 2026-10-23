<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Models\ItemSerial;
use App\Modules\Sales\Models\Sale;
use App\Support\Industry;
use Illuminate\Validation\ValidationException;

/**
 * Serial numbers of items that track them. Each unit is recorded once, by
 * its serial; a sale names the serial of every unit it sells (Sell), which
 * then keeps the sale it left in. A void puts its serials back in stock; a
 * return names the serials that came back.
 */
class Serials
{
    public function __construct(private readonly Industry $industry) {}

    /**
     * Records serials of units of an item (as they arrive, or found on the
     * shelf). Blank lines and repeats are ignored.
     *
     * @param  list<string>  $serials
     * @return int how many were recorded
     *
     * @throws ValidationException
     */
    public function record(Item $item, array $serials, User $by): int
    {
        if (! $item->tracks_serials || ! $this->industry->handles($item->kind)) {
            throw ValidationException::withMessages(['item_id' => "{$item->name} does not track serial numbers."]);
        }

        $serials = $this->clean($serials);
        if ($serials === []) {
            throw ValidationException::withMessages(['serials' => 'Enter the serial numbers, one per line.']);
        }

        $known = ItemSerial::query()->where('item_id', $item->id)->whereIn('serial', $serials)->pluck('serial')->all();
        if ($known !== []) {
            throw ValidationException::withMessages(['serials' => 'Already recorded: '.implode(', ', array_slice($known, 0, 10)).'.']);
        }

        foreach ($serials as $serial) {
            ItemSerial::query()->create([
                'item_id' => $item->id,
                'serial' => $serial,
                'status' => ItemSerial::IN_STOCK,
                'recorded_by' => $by->id,
                'recorded_at' => now(),
            ]);
        }

        return count($serials);
    }

    /**
     * The serials a sale's line sells: each recorded and in stock.
     *
     * @param  list<string>  $serials
     *
     * @throws ValidationException
     */
    public function sell(Sale $sale, Item $item, array $serials, string $field): void
    {
        $rows = ItemSerial::query()
            ->where('item_id', $item->id)
            ->whereIn('serial', $serials)
            ->lockForUpdate()
            ->get()
            ->keyBy('serial');

        foreach ($serials as $serial) {
            $row = $rows->get($serial);
            if ($row === null) {
                throw ValidationException::withMessages([$field => "{$serial} is not a recorded serial of {$item->name}."]);
            }
            if ($row->status !== ItemSerial::IN_STOCK) {
                throw ValidationException::withMessages([$field => "{$serial} was already sold."]);
            }

            $row->forceFill(['status' => ItemSerial::SOLD, 'sale_id' => $sale->id, 'sold_at' => $sale->sold_at])->save();
        }
    }

    /**
     * A voided sale: its serials are back in stock.
     */
    public function release(Sale $sale): void
    {
        ItemSerial::query()
            ->where('sale_id', $sale->id)
            ->where('status', ItemSerial::SOLD)
            ->update(['status' => ItemSerial::IN_STOCK, 'sale_id' => null, 'sold_at' => null, 'updated_at' => now()]);
    }

    /**
     * The serials that came back on a return, each sold on this sale.
     *
     * @param  list<string>  $serials
     *
     * @throws ValidationException
     */
    public function returned(Sale $sale, Item $item, array $serials, string $field): void
    {
        $rows = ItemSerial::query()
            ->where('item_id', $item->id)
            ->where('sale_id', $sale->id)
            ->whereIn('serial', $serials)
            ->lockForUpdate()
            ->get()
            ->keyBy('serial');

        foreach ($serials as $serial) {
            $row = $rows->get($serial) ?? throw ValidationException::withMessages([$field => "{$serial} was not sold on sale {$sale->number}."]);
            $row->forceFill(['status' => ItemSerial::IN_STOCK, 'sale_id' => null, 'sold_at' => null])->save();
        }
    }

    /**
     * Trimmed, without blanks or repeats.
     *
     * @param  array<mixed>  $serials
     * @return list<string>
     */
    public function clean(array $serials): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($serial) => mb_substr(trim((string) $serial), 0, 64), $serials),
            fn (string $serial) => $serial !== '',
        )));
    }
}
