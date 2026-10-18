import { usePage } from '@inertiajs/react';
import { StatusBadge } from '@miles/ui/components/status-badge';
import type { BuyableItem } from '@/components/pack-select';
import { formatMoney, parseMoney } from '@/lib/money';

/**
 * Selling and buying price of one unit side by side, and the margin
 * between them: red when a unit sells for no more than it costs.
 */
export function PriceBadges({
    sellMinor,
    buyMinor,
    unit,
    buyLabel = 'Buy',
}: {
    sellMinor: number;
    /** null: never bought, so no buying price or margin yet. */
    buyMinor: number | null;
    unit: string;
    buyLabel?: string;
}) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const margin = buyMinor === null ? null : sellMinor - buyMinor;

    return (
        <span className="inline-flex flex-wrap items-center gap-1 tabular-nums">
            <StatusBadge tone="success">
                Sell {money(sellMinor)} / {unit}
            </StatusBadge>
            {buyMinor === null ? (
                <StatusBadge tone="neutral">Not bought yet</StatusBadge>
            ) : (
                <StatusBadge tone="info">
                    {buyLabel} {money(buyMinor)} / {unit}
                </StatusBadge>
            )}
            {margin !== null && (
                <StatusBadge tone={margin > 0 ? 'success' : 'danger'}>
                    Margin {money(margin)}
                    {sellMinor > 0 &&
                        ` (${Math.round((margin / sellMinor) * 100)}%)`}
                </StatusBadge>
            )}
        </span>
    );
}

/**
 * What a bought line works out to while it is typed: a pack's cost split
 * over its units (a box of 10 at 100 is 10 a unit, rounded as the server
 * does), that unit cost against the selling price, and the line's total.
 * Before a cost is typed, the unit's last buying price stands in.
 */
export function CostBreakdown({
    item,
    pack,
    cost,
    quantity,
}: {
    item: BuyableItem | undefined;
    /** null: bought by the unit. */
    pack: { name: string; units: number } | null;
    /** The cost of one unit or pack, as typed. */
    cost: string;
    /** How many units or packs, as typed. */
    quantity: string;
}) {
    const { currency } = usePage().props;
    const code = currency ?? 'USD';

    if (!item) {
        return null;
    }

    const units = pack?.units ?? 1;
    const costMinor = parseMoney(cost, code);
    const count = Number.parseInt(quantity, 10);
    const unitCostMinor =
        costMinor === null ? null : Math.round(costMinor / units);

    return (
        <span className="inline-flex flex-wrap items-center gap-1 tabular-nums">
            {pack && costMinor !== null && (
                <StatusBadge tone="neutral">
                    {pack.name} of {units} at {formatMoney(costMinor, code)} ={' '}
                    {formatMoney(unitCostMinor ?? 0, code)} / {item.unit}
                </StatusBadge>
            )}
            <PriceBadges
                sellMinor={item.sellPriceMinor}
                buyMinor={unitCostMinor ?? item.lastUnitCostMinor}
                unit={item.unit}
                buyLabel={unitCostMinor === null ? 'Last buy' : 'Buy'}
            />
            {costMinor !== null && count > 0 && (
                <StatusBadge tone="neutral">
                    Line {formatMoney(costMinor * count, code)} ({count * units}{' '}
                    {item.unit})
                </StatusBadge>
            )}
        </span>
    );
}
