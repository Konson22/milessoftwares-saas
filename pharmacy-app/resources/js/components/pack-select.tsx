export type BuyableItem = {
    id: string;
    label: string;
    unit: string;
    packs: { id: string; label: string }[];
};

/**
 * How a document line is counted: by the item's unit (empty value) or by
 * one of its packs. Remounts when the item changes, back to the unit.
 */
export function PackSelect({
    name,
    line,
    item,
}: {
    name: string;
    line: number;
    item: BuyableItem | undefined;
}) {
    return (
        <select
            key={item?.id}
            name={name}
            aria-label={`Bought as, line ${line}`}
            className="h-9 w-full rounded-md border bg-transparent px-3 text-sm"
            disabled={!item || item.packs.length === 0}
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
