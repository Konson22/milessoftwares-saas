<?php

namespace App\Modules\Procurement\Models;

/**
 * A document line counted as the supplier sold it: so many packs (or
 * units, when pack_name is null), at so much each. Stock is in units.
 *
 * @property string|null $pack_name
 * @property int $units_per_pack
 */
trait BoughtInPacks
{
    /**
     * "box of 30", or null when bought by the unit.
     */
    public function packLabel(): ?string
    {
        return $this->pack_name === null ? null : "{$this->pack_name} of {$this->units_per_pack}";
    }

    /**
     * A quantity of this line's packs, in the item's unit.
     */
    public function inUnits(int $packs): int
    {
        return $packs * $this->units_per_pack;
    }
}
