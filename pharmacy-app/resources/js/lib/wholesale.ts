/** A price a price list sets: for one unit (packId null) or one pack. */
export type ListPrice = {
    itemId: string;
    packId: string | null;
    priceMinor: number;
};

/**
 * What a customer on this price list pays for one unit or pack, by the
 * same rule as the server (App\Modules\Sales\Actions\PriceLists): the
 * list's pack price, else its unit price times the pack's units, else the
 * shop's own price.
 */
export function listPrice(
    prices: ListPrice[] | undefined,
    itemId: string,
    pack: { id: string; units: number } | null,
    retailMinor: number,
): number {
    const own = prices?.filter((price) => price.itemId === itemId) ?? [];
    const forPack =
        pack === null ? undefined : own.find((p) => p.packId === pack.id);
    if (forPack) {
        return forPack.priceMinor;
    }

    const unit = own.find((p) => p.packId === null);
    if (unit) {
        return unit.priceMinor * (pack?.units ?? 1);
    }

    return retailMinor;
}

/** A line's total after its discount, rounded as the server does. */
export function lineTotal(
    quantity: number,
    priceMinor: number,
    discountPercent: number,
): number {
    const gross = quantity * priceMinor;

    return gross - Math.floor((gross * discountPercent + 50) / 100);
}
