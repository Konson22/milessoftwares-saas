export type BuyableItem = {
    id: string;
    label: string;
    unit: string;
    sellPriceMinor: number;
    /** null: never received, so no buying price yet. */
    lastUnitCostMinor: number | null;
    packs: {
        id: string;
        label: string;
        name: string;
        units: number;
        sellPriceMinor: number;
    }[];
};

/**
 * How a document line is counted: by the item's unit (empty value) or by
 * one of its packs. Remounts when the item changes, back to the unit.
 */
export function PackSelect({
    name,
    line,
    item,
    onChange,
}: {
    name: string;
    line: number;
    item: BuyableItem | undefined;
    onChange?: (packId: string) => void;
}) {
    return (
        <select
            key={item?.id}
            name={name}
            aria-label={`Bought as, line ${line}`}
            className="h-9 w-full rounded-md border bg-transparent px-3 text-sm"
            disabled={!item || item.packs.length === 0}
            onChange={(e) => onChange?.(e.target.value)}
        >
            <option value="">{item?.unit ?? 'unit'}</option>
            {item?.packs.map((pack) => (
                <option key={pack.id} value={pack.id}>
                    {pack.label}
                </option>
            ))}
        </select>
    );
}
