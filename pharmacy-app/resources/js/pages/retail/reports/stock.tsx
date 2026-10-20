import { Link, usePage } from '@inertiajs/react';
import { AlertTriangle, Hourglass, PackageX } from 'lucide-react';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { RetailReportHeader } from '@/components/retail-report-header';
import { formatMoney } from '@/lib/money';
import { show as stockShow } from '@/routes/retail/stock';

type Row = {
    id: string;
    name: string;
    category: string | null;
    unit: string;
    onHand: number;
    stockCostMinor: number;
    reorderLevel: number;
    lastCostMinor: number | null;
    soldUnits: number;
    perDay: number;
    coverDays: number | null;
    lastSoldOn: string | null;
};

type Props = {
    from: string;
    to: string;
    slowCoverDays: number;
    reorder: (Row & { shortBy: number })[];
    slow: Row[];
    totals: {
        reorderCount: number;
        outOfStock: number;
        slowCount: number;
        slowCostMinor: number;
    };
};

/**
 * What to reorder and what is not moving, against the period's sales.
 */
export default function StockReport({
    from,
    to,
    slowCoverDays,
    reorder,
    slow,
    totals,
}: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <RetailReportHeader current="stock" from={from} to={to} />

            <div className="mb-3 grid gap-3 sm:grid-cols-3">
                <StatCard
                    label="To reorder"
                    value={totals.reorderCount}
                    hint="At or under their reorder level"
                    icon={AlertTriangle}
                    accent="amber"
                />
                <StatCard
                    label="Out of stock"
                    value={totals.outOfStock}
                    hint="Nothing sellable on the shelf"
                    icon={PackageX}
                    accent="red"
                />
                <StatCard
                    label="Slow movers"
                    value={totals.slowCount}
                    hint={`${money(totals.slowCostMinor)} at cost tied up`}
                    icon={Hourglass}
                    accent="slate"
                />
            </div>

            <div className="space-y-3">
                <Panel>
                    <PanelHeader
                        title="Reorder list"
                        description="Sellable stock at or under the reorder level, furthest under first, with how fast it sold in the period."
                    />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Product</TableHead>
                                <TableHead className="text-right">
                                    On hand
                                </TableHead>
                                <TableHead className="text-right">
                                    Reorder level
                                </TableHead>
                                <TableHead className="text-right">
                                    Sold
                                </TableHead>
                                <TableHead className="text-right">
                                    Per day
                                </TableHead>
                                <TableHead className="text-right">
                                    Last cost
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {reorder.length === 0 ? (
                                <TableEmpty colSpan={6}>
                                    Nothing is under its reorder level.
                                </TableEmpty>
                            ) : (
                                reorder.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>
                                            <ProductName row={row} />
                                        </TableCell>
                                        <TableCell
                                            className={`text-right tabular-nums ${row.onHand === 0 ? 'font-medium text-destructive' : ''}`}
                                        >
                                            {row.onHand} {row.unit}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.reorderLevel}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.soldUnits}
                                        </TableCell>
                                        <TableCell className="text-right text-muted-foreground tabular-nums">
                                            {row.perDay}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.lastCostMinor === null
                                                ? '–'
                                                : money(row.lastCostMinor)}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Slow movers"
                        description={`Stock that sold nothing in the period, or would take more than ${slowCoverDays} days to sell at its pace; most money tied up first.`}
                    />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Product</TableHead>
                                <TableHead className="text-right">
                                    On hand
                                </TableHead>
                                <TableHead className="text-right">
                                    At cost
                                </TableHead>
                                <TableHead className="text-right">
                                    Sold
                                </TableHead>
                                <TableHead className="text-right">
                                    Days of cover
                                </TableHead>
                                <TableHead>Last sold</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {slow.length === 0 ? (
                                <TableEmpty colSpan={6}>
                                    Everything on the shelves is moving.
                                </TableEmpty>
                            ) : (
                                slow.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>
                                            <ProductName row={row} />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.onHand} {row.unit}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(row.stockCostMinor)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.soldUnits}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.coverDays ?? '–'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {row.lastSoldOn ?? 'Never'}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </Panel>
            </div>
        </>
    );
}

function ProductName({ row }: { row: Row }) {
    return (
        <>
            <Link
                href={stockShow(row.id)}
                className="font-medium hover:underline"
            >
                {row.name}
            </Link>
            {row.category && (
                <span className="block text-xs text-muted-foreground">
                    {row.category}
                </span>
            )}
        </>
    );
}
