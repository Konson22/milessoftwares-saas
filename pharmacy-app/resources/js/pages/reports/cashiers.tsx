import { Link, usePage } from '@inertiajs/react';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { ReportHeader } from '@/components/report-header';
import { formatMoney } from '@/lib/money';
import { statusTone } from '@/lib/status';
import { show as tillShow } from '@/routes/tills';

type Props = {
    from: string;
    to: string;
    byCashier: {
        id: string;
        cashier: string;
        tills: number;
        open: number;
        salesCount: number;
        takenMinor: number;
        cashRefundsMinor: number;
        overMinor: number;
        shortMinor: number;
    }[];
    tills: {
        id: string;
        number: string;
        cashier: string;
        openedAt: string;
        closedAt: string | null;
        status: string;
        salesCount: number;
        takenMinor: number;
        cashTakenMinor: number;
        cashRefundsMinor: number;
        expectedCashMinor: number;
        countedCashMinor: number | null;
        differenceMinor: number | null;
        closingNote: string | null;
    }[];
};

/**
 * Cashier reconciliation: what went through each till and how the count
 * came out, per cashier and per till.
 */
export default function Cashiers({ from, to, byCashier, tills }: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const signed = (minor: number) =>
        minor === 0
            ? money(0)
            : `${minor > 0 ? '+' : '−'}${money(Math.abs(minor))}`;

    return (
        <>
            <ReportHeader current="cashiers" from={from} to={to} />

            <Panel className="mb-3">
                <PanelHeader
                    title="By cashier"
                    description="Tills opened in the period. Over and short come from the count at closing."
                />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Cashier</TableHead>
                            <TableHead className="text-right">Tills</TableHead>
                            <TableHead className="text-right">Sales</TableHead>
                            <TableHead className="text-right">Taken</TableHead>
                            <TableHead className="text-right">
                                Cash refunds
                            </TableHead>
                            <TableHead className="text-right">Over</TableHead>
                            <TableHead className="text-right">Short</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {byCashier.length === 0 ? (
                            <TableEmpty colSpan={7}>
                                No tills in this period.
                            </TableEmpty>
                        ) : (
                            byCashier.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell className="font-medium">
                                        {row.cashier}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.tills}
                                        {row.open > 0 && (
                                            <span className="block text-xs text-muted-foreground">
                                                {row.open} still open
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.salesCount}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(row.takenMinor)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(row.cashRefundsMinor)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.overMinor > 0
                                            ? money(row.overMinor)
                                            : '–'}
                                    </TableCell>
                                    <TableCell
                                        className={`text-right tabular-nums ${row.shortMinor > 0 ? 'font-medium text-destructive' : ''}`}
                                    >
                                        {row.shortMinor > 0
                                            ? money(row.shortMinor)
                                            : '–'}
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Panel>

            <Panel>
                <PanelHeader title="Tills" />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Till</TableHead>
                            <TableHead>Cashier</TableHead>
                            <TableHead>Opened</TableHead>
                            <TableHead className="text-right">Taken</TableHead>
                            <TableHead className="text-right">
                                Expected cash
                            </TableHead>
                            <TableHead className="text-right">
                                Counted
                            </TableHead>
                            <TableHead className="text-right">
                                Over / short
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {tills.length === 0 ? (
                            <TableEmpty colSpan={7}>Nothing.</TableEmpty>
                        ) : (
                            tills.map((till) => (
                                <TableRow key={till.id}>
                                    <TableCell>
                                        <Link
                                            href={tillShow(till.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {till.number}
                                        </Link>{' '}
                                        <StatusBadge
                                            tone={statusTone(till.status)}
                                        >
                                            {till.status}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell>{till.cashier}</TableCell>
                                    <TableCell className="text-muted-foreground tabular-nums">
                                        {new Date(
                                            till.openedAt,
                                        ).toLocaleString()}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(till.takenMinor)}
                                        <span className="block text-xs text-muted-foreground">
                                            {till.salesCount} sales
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(till.expectedCashMinor)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {till.countedCashMinor === null
                                            ? '–'
                                            : money(till.countedCashMinor)}
                                    </TableCell>
                                    <TableCell
                                        className={`text-right tabular-nums ${(till.differenceMinor ?? 0) < 0 ? 'text-destructive' : ''}`}
                                    >
                                        {till.differenceMinor === null
                                            ? '–'
                                            : signed(till.differenceMinor)}
                                        {till.closingNote && (
                                            <span className="block text-xs text-muted-foreground">
                                                {till.closingNote}
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
