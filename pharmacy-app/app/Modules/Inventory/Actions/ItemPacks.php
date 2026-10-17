<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPack;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The packs an item is bought in. Documents copy a pack's name and size,
 * so changing or removing a pack never touches them.
 */
class ItemPacks
{
    /**
     * Makes the item's packs exactly these, matched by name.
     *
     * @param  list<array{name: string, units: int}>  $packs
     *
     * @throws ValidationException
     */
    public function sync(Item $item, array $packs, string $field = 'packs'): void
    {
        $byName = [];
        foreach ($packs as $index => $pack) {
            $name = mb_strtolower(trim($pack['name']));

            if ($name === mb_strtolower($item->unit)) {
                throw ValidationException::withMessages(["{$field}.{$index}.name" => "A pack cannot be called {$item->unit}: that is the unit stock is counted in."]);
            }
            if (isset($byName[$name])) {
                throw ValidationException::withMessages(["{$field}.{$index}.name" => 'Each pack needs its own name.']);
            }
            if ($pack['units'] < 2) {
                throw ValidationException::withMessages(["{$field}.{$index}.units" => 'A pack holds at least 2.']);
            }

            $byName[$name] = $pack['units'];
        }

        DB::transaction(function () use ($item, $byName) {
            ItemPack::query()->where('item_id', $item->id)->whereNotIn('name', array_keys($byName))->delete();

            foreach ($byName as $name => $units) {
                ItemPack::query()->updateOrCreate(['item_id' => $item->id, 'name' => $name], ['units' => $units]);
            }
        });
    }

    /**
     * The pack a document line names, if it names one; never another item's.
     *
     * @throws ValidationException
     */
    public function find(string $itemId, ?string $packId, string $field): ?ItemPack
    {
        if ($packId === null || $packId === '') {
            return null;
        }

        return ItemPack::query()->where('item_id', $itemId)->whereKey($packId)->first()
            ?? throw ValidationException::withMessages([$field => 'Not a pack of this item.']);
    }
}
