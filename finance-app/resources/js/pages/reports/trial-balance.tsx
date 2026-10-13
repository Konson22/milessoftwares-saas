import { Head, Link } from '@inertiajs/react';
import { Amount } from '@/components/amount';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelToolbar } from '@/components/panel';
import { PeriodFilter } from '@/components/period-filter';
import { StatusBadge } from '@/components/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { show as showAccount } from '@/routes/accounts';
import { trialBalance } from '@/routes/reports';

type Props = {
    asOf: string;
    rows: {
        id: string;
        code: string;
        name: string;
        type: string;
        debitMinor: number;
        creditMinor: number;
    }[];
    debitMinor: number;
    creditMinor: number;
};

/**
 * Every account's balance on a day; debits and credits must agree.
 */
export default function TrialBalance({
    asOf,
    rows,
    debitMinor,
    creditMinor,
}: Props) {
    return (
        <>
            <Head title="Trial balance" />
            <PageHeader
                title="Trial balance"
                description={`As of ${asOf}`}
                badges={
                    debitMinor === creditMinor ? (
                        <StatusBadge tone="success">Balanced</StatusBadge>
                    ) : (
                        <StatusBadge tone="danger">Out of balance</StatusBadge>
                    )
                }
            />
            <Panel className="max-w-4xl">
                <PanelToolbar>
                    <PeriodFilter form={trialBalance.form()} asOf={asOf} />
                </PanelToolbar>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-24">Code</TableHead>
                            <TableHead>Account</TableHead>
                            <TableHead className="text-right">Debit</TableHead>
                            <TableHead className="text-right">Credit</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 && (
                            <TableEmpty colSpan={4}>
                                Nothing posted yet.
                            </TableEmpty>
                        )}
                        {rows.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell className="font-mono text-xs">
                                    {row.code}
                                </TableCell>
                                <TableCell>
                                    <Link
                                        href={showAccount(row.id)}
                                        className="hover:underline"
                                    >
                                        {row.name}
                                    </Link>
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={row.debitMinor} blankZero />
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={row.creditMinor} blankZero />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={2}>Totals</TableCell>
                            <TableCell className="text-right">
                                <Amount minor={debitMinor} />
                            </TableCell>
                            <TableCell className="text-right">
                                <Amount minor={creditMinor} />
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </Panel>
        </>
    );
}
