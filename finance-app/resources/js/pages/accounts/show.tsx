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
import { sourceLabel } from '@/lib/format';
import { show } from '@/routes/accounts';
import { show as showJournal } from '@/routes/journals';

type Props = {
    account: {
        id: string;
        code: string;
        name: string;
        type: string;
        typeLabel: string;
        bankKind: string | null;
        active: boolean;
    };
    from: string;
    to: string;
    openingMinor: number;
    closingMinor: number;
    entries: {
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
    }[];
};

/**
 * One account's ledger: every posting in the period with the running balance.
 */
export default function AccountLedger({
    account,
    from,
    to,
    openingMinor,
    closingMinor,
    entries,
}: Props) {
    return (
        <>
            <Head title={`${account.code} ${account.name}`} />
            <PageHeader
                title={`${account.code} ${account.name}`}
                badges={
                    <>
                        <StatusBadge>{account.typeLabel}</StatusBadge>
                        {account.bankKind && (
                            <StatusBadge tone="info">
                                {account.bankKind}
                            </StatusBadge>
                        )}
                        {!account.active && (
                            <StatusBadge tone="warning">Closed</StatusBadge>
                        )}
                    </>
                }
                description="Balances are shown on the account's normal side."
            />

            <Panel>
                <PanelToolbar>
                    <PeriodFilter
                        form={show.form(account.id)}
                        from={from}
                        to={to}
                    />
                </PanelToolbar>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead>Journal</TableHead>
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
                        <TableRow className="bg-muted/30">
                            <TableCell className="tabular-nums">
                                {from}
                            </TableCell>
                            <TableCell
                                colSpan={5}
                                className="text-muted-foreground"
                            >
                                Opening balance
                            </TableCell>
                            <TableCell className="text-right font-medium">
                                <Amount minor={openingMinor} />
                            </TableCell>
                        </TableRow>
                        {entries.length === 0 && (
                            <TableEmpty colSpan={7}>
                                No postings in this period.
                            </TableEmpty>
                        )}
                        {entries.map((entry) => (
                            <TableRow key={entry.id}>
                                <TableCell className="tabular-nums">
                                    {entry.date}
                                </TableCell>
                                <TableCell className="font-mono text-xs">
                                    <Link
                                        href={showJournal(entry.journalId)}
                                        className="text-primary hover:underline"
                                    >
                                        {entry.number}
                                    </Link>
                                </TableCell>
                                <TableCell>
                                    {entry.memo}
                                    {entry.description && (
                                        <span className="block text-xs text-muted-foreground">
                                            {entry.description}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell>
                                    {sourceLabel(entry.source)}
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount
                                        minor={entry.debitMinor}
                                        blankZero
                                    />
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount
                                        minor={entry.creditMinor}
                                        blankZero
                                    />
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={entry.balanceMinor} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow className="hover:bg-transparent">
                            <TableCell className="tabular-nums">{to}</TableCell>
                            <TableCell colSpan={5}>Closing balance</TableCell>
                            <TableCell className="text-right">
                                <Amount minor={closingMinor} />
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </Panel>
        </>
    );
}
