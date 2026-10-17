import { Link, usePage } from '@inertiajs/react';
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
import { margin, ReportHeader } from '@/components/report-header';
import { formatMoney } from '@/lib/money';
import { show as stockShow } from '@/routes/stock';

type Props = {
    from: string;
    to: string;
    rows: {
        id: string;
        medicine: string;
        quantity: number;
        returnedQuantity: number;
        discountMinor: number;
        revenueMinor: number;
        costMinor: number;
        profitMinor: number;
    }[];
    totals: {
        revenueMinor: number;
        costMinor: number;
        profitMinor: number;
        discountMinor: number;
    };
};

/**
 * What each medicine earned in the period, most profitable first.
 */
export default function Profitability({ from, to, rows, totals }: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <ReportHeader current="profitability" from={from} to={to} />

            <Panel>
                <PanelHeader
                    title="Profit by medicine"
                    description="Sold less returned, at what it sold for (after discounts) and what its batches cost."
                />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Medicine</TableHead>
                            <TableHead className="text-right">Sold</TableHead>
                            <TableHead className="text-right">
                                Discounts
                            </TableHead>
                            <TableHead className="text-right">
                                Revenue
                            </TableHead>
                            <TableHead className="text-right">Cost</TableHead>
                            <TableHead className="text-right">Profit</TableHead>
                            <TableHead className="text-right">Margin</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableEmpty colSpan={7}>
                                Nothing sold in this period.
                            </TableEmpty>
                        ) : (
                            rows.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        <Link
                                            href={stockShow(row.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {row.medicine}
                                        </Link>
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
                                        {margin(
                                            row.revenueMinor,
                                            row.costMinor,
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                    {rows.length > 0 && (
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={2}>Total</TableCell>
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
                                    {margin(
                                        totals.revenueMinor,
                                        totals.costMinor,
                                    )}
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    )}
                </Table>
            </Panel>
        </>
    );
}
