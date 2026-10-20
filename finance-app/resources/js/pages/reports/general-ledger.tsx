import { Head, Link } from '@inertiajs/react';
import { Fragment } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
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
import { Amount } from '@/components/amount';
import { Select } from '@/components/field';
import { PeriodFilter } from '@/components/period-filter';
import { ReportActions } from '@/components/report-actions';
import { sourceLabel } from '@/lib/format';
import { show as showAccount } from '@/routes/accounts';
import { show as showJournal } from '@/routes/journals';
import { generalLedger } from '@/routes/reports';

type Line = {
    id: string;
    journalId: string;
    number: string;
    date: string;
    memo: string;
    description: string | null;
    source: string;
    debitMinor: number;
    creditMinor: number;
    balanceMinor: number;
};

type Props = {
    from: string;
    to: string;
    account: string | null;
    accountOptions: { id: string; label: string }[];
    limit: number;
    accounts: {
        id: string;
        code: string;
        name: string;
        type: string;
        openingMinor: number;
        debitMinor: number;
        creditMinor: number;
        closingMinor: number;
        lines: Line[];
    }[];
    debitMinor: number;
    creditMinor: number;
    truncated: boolean;
};

/**
 * Every posting in a period, account by account, with opening, running and
 * closing balances.
 */
export default function GeneralLedger({
    from,
    to,
    account,
    accountOptions,
    limit,
    accounts,
    debitMinor,
    creditMinor,
    truncated,
}: Props) {
    return (
        <>
            <Head title="General ledger" />
            <PageHeader
                title="General ledger"
                description={`${from} to ${to}. Balances are in each account's normal direction.`}
            />
            {truncated && (
                <p className="mb-3 text-sm text-amber-800 print:hidden">
                    Only the first {limit.toLocaleString()} lines are listed;
                    the totals cover every line. Choose an account or a shorter
                    period to see the rest.
                </p>
            )}
            <Panel>
                <PanelToolbar>
                    <PeriodFilter
                        form={generalLedger.form()}
                        from={from}
                        to={to}
                    >
                        <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                            Account
                            <Select
                                name="account"
                                defaultValue={account ?? ''}
                                className="w-64"
                            >
                                <option value="">Every account</option>
                                {accountOptions.map((option) => (
                                    <option key={option.id} value={option.id}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                        </label>
                    </PeriodFilter>
                    <ReportActions
                        route={generalLedger}
                        query={{ from, to, account }}
                        exports={[{ key: 'lines', title: 'Lines' }]}
                    />
                </PanelToolbar>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-28">Date</TableHead>
                            <TableHead className="w-28">Journal</TableHead>
                            <TableHead>Memo</TableHead>
                            <TableHead>From</TableHead>
                            <TableHead className="text-right">Debit</TableHead>
                            <TableHead className="text-right">Credit</TableHead>
                            <TableHead className="text-right">
                                Balance
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {accounts.length === 0 && (
                            <TableEmpty colSpan={7}>
                                Nothing posted in this period.
                            </TableEmpty>
                        )}
                        {accounts.map((row) => (
                            <Fragment key={row.id}>
                                <TableRow className="bg-muted/50 hover:bg-muted/50">
                                    <TableCell
                                        colSpan={6}
                                        className="font-semibold"
                                    >
                                        <Link
                                            href={showAccount(row.id, {
                                                query: { from, to },
                                            })}
                                            className="hover:underline"
                                        >
                                            <span className="mr-2 font-mono text-xs text-muted-foreground">
                                                {row.code}
                                            </span>
                                            {row.name}
                                        </Link>
                                        <span className="ml-2 text-xs font-normal text-muted-foreground">
                                            opening balance
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right font-semibold">
                                        <Amount minor={row.openingMinor} />
                                    </TableCell>
                                </TableRow>
                                {row.lines.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell className="tabular-nums">
                                            {line.date}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            <Link
                                                href={showJournal(
                                                    line.journalId,
                                                )}
                                                className="text-primary hover:underline"
                                            >
                                                {line.number}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="max-w-96 truncate">
                                            {line.memo}
                                            {line.description && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · {line.description}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {sourceLabel(line.source)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount
                                                minor={line.debitMinor}
                                                blankZero
                                            />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount
                                                minor={line.creditMinor}
                                                blankZero
                                            />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount minor={line.balanceMinor} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                                <TableRow className="border-b-2">
                                    <TableCell
                                        colSpan={4}
                                        className="text-right text-xs text-muted-foreground"
                                    >
                                        Closing balance of {row.code}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={row.debitMinor} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={row.creditMinor} />
                                    </TableCell>
                                    <TableCell className="text-right font-semibold">
                                        <Amount minor={row.closingMinor} />
                                    </TableCell>
                                </TableRow>
                            </Fragment>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={4}>
                                Posted in the period
                            </TableCell>
                            <TableCell className="text-right">
                                <Amount minor={debitMinor} />
                            </TableCell>
                            <TableCell className="text-right">
                                <Amount minor={creditMinor} />
                            </TableCell>
                            <TableCell />
                        </TableRow>
                    </TableFooter>
                </Table>
            </Panel>
        </>
    );
}
