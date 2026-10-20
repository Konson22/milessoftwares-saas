import { usePage } from '@inertiajs/react';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { margin } from '@/components/report-header';
import { RetailReportHeader } from '@/components/retail-report-header';
import { formatMoney } from '@/lib/money';

type Row = {
    id: string;
    name: string;
    sales: number;
    quantity: number;
    returnedQuantity: number;
    discountMinor: number;
    revenueMinor: number;
    costMinor: number;
    profitMinor: number;
    marginPercent: number | null;
};

type Totals = {
    revenueMinor: number;
    costMinor: number;
    profitMinor: number;
    discountMinor: number;
};

type Props = {
    from: string;
    to: string;
    byCategory: Row[];
    byCashier: Row[];
    totals: Totals;
};

/**
 * The period's sales by category and by cashier, each net of returns and
 * with its margin. Both add up to the overview's takings.
 */
export default function SalesReport({
    from,
    to,
    byCategory,
    byCashier,
    totals,
}: Props) {
    return (
        <>
            <RetailReportHeader current="sales" from={from} to={to} />

            <div className="space-y-3">
                <Breakdown
                    title="By category"
                    description="Sold less returned, after discounts, against what the stock cost."
                    first="Category"
                    rows={byCategory}
                    totals={totals}
                />
                <Breakdown
                    title="By cashier"
                    description="Returns count against the cashier who made the sale."
                    first="Cashier"
                    rows={byCashier}
                    totals={totals}
                />
            </div>
        </>
    );
}

function Breakdown({
    title,
    description,
    first,
    rows,
    totals,
}: {
    title: string;
    description: string;
    first: string;
    rows: Row[];
    totals: Totals;
}) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <Panel>
            <PanelHeader title={title} description={description} />
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{first}</TableHead>
                        <TableHead className="text-right">Sales</TableHead>
                        <TableHead className="text-right">Units</TableHead>
                        <TableHead className="text-right">Discounts</TableHead>
                        <TableHead className="text-right">Revenue</TableHead>
                        <TableHead className="text-right">Cost</TableHead>
                        <TableHead className="text-right">Profit</TableHead>
                        <TableHead className="text-right">Margin</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.length === 0 ? (
                        <TableEmpty colSpan={8}>
                            Nothing sold in this period.
                        </TableEmpty>
                    ) : (
                        rows.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell className="font-medium">
                                    {row.name}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {row.sales}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {row.quantity}
                                    {row.returnedQuantity > 0 && (
                                        <span className="block text-xs text-muted-foreground">
                                            {row.returnedQuantity} returned
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-right text-muted-foreground tabular-nums">
                                    {row.discountMinor > 0
                                        ? money(row.discountMinor)
                                        : '–'}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(row.revenueMinor)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(row.costMinor)}
                                </TableCell>
                                <TableCell
                                    className={`text-right font-medium tabular-nums ${row.profitMinor < 0 ? 'text-destructive' : ''}`}
                                >
                                    {money(row.profitMinor)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {margin(row.revenueMinor, row.costMinor)}
                                </TableCell>
                            </TableRow>
                        ))
                    )}
                </TableBody>
                {rows.length > 0 && (
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={3}>Total</TableCell>
                            <TableCell className="text-right tabular-nums">
                                {money(totals.discountMinor)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {money(totals.revenueMinor)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {money(totals.costMinor)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {money(totals.profitMinor)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {margin(totals.revenueMinor, totals.costMinor)}
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                )}
            </Table>
        </Panel>
    );
}
