import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader, PanelToolbar } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
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
import { ReportActions } from '@/components/report-actions';
import { show as showBill } from '@/routes/bills';
import { show as showInvoice } from '@/routes/invoices';
import { agedPayables, agedReceivables } from '@/routes/reports';

type Buckets = {
    currentMinor: number;
    days30Minor: number;
    days60Minor: number;
    days90Minor: number;
    olderMinor: number;
    totalMinor: number;
};

type Props = {
    kind: 'receivables' | 'payables';
    asOf: string;
    parties: (Buckets & { party: string })[];
    documents: {
        id: string;
        number: string;
        party: string;
        date: string;
        dueOn: string | null;
        daysOverdue: number;
        bucket: string;
        totalMinor: number;
        outstandingMinor: number;
    }[];
    totals: Buckets;
    accountMinor: number;
    differenceMinor: number;
};

const COLUMNS: { key: keyof Buckets; title: string }[] = [
    { key: 'currentMinor', title: 'Not yet due' },
    { key: 'days30Minor', title: '1–30 days' },
    { key: 'days60Minor', title: '31–60 days' },
    { key: 'days90Minor', title: '61–90 days' },
    { key: 'olderMinor', title: 'Over 90 days' },
    { key: 'totalMinor', title: 'Total' },
];

/**
 * What customers owe, or what is owed to suppliers, today, by how long it is
 * past due; checked against the receivable or payable account.
 */
export default function Aged({
    kind,
    asOf,
    parties,
    documents,
    totals,
    accountMinor,
    differenceMinor,
}: Props) {
    const receivables = kind === 'receivables';
    const title = receivables ? 'Aged receivables' : 'Aged payables';
    const party = receivables ? 'Customer' : 'Supplier';
    const route = receivables ? agedReceivables : agedPayables;

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                description={`Open ${receivables ? 'invoices' : 'bills'} on ${asOf}, by days past their due date (or their date when they have none).`}
                badges={
                    differenceMinor === 0 ? (
                        <StatusBadge tone="success">
                            Agrees with the{' '}
                            {receivables ? 'receivable' : 'payable'} account
                        </StatusBadge>
                    ) : (
                        <StatusBadge tone="warning">
                            Differs from the account
                        </StatusBadge>
                    )
                }
            />

            <Panel className="mb-4">
                <PanelToolbar>
                    <ReportActions
                        route={route}
                        query={{}}
                        exports={[
                            {
                                key: 'parties',
                                title: `By ${party.toLowerCase()}`,
                            },
                            {
                                key: 'documents',
                                title: receivables ? 'Invoices' : 'Bills',
                            },
                        ]}
                    />
                </PanelToolbar>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{party}</TableHead>
                            {COLUMNS.map((column) => (
                                <TableHead
                                    key={column.key}
                                    className="text-right"
                                >
                                    {column.title}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {parties.length === 0 && (
                            <TableEmpty colSpan={7}>
                                Nothing outstanding.
                            </TableEmpty>
                        )}
                        {parties.map((row) => (
                            <TableRow key={row.party}>
                                <TableCell className="font-medium">
                                    {row.party}
                                </TableCell>
                                {COLUMNS.map((column) => (
                                    <TableCell
                                        key={column.key}
                                        className="text-right"
                                    >
                                        <Amount
                                            minor={row[column.key]}
                                            blankZero
                                        />
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow className="hover:bg-transparent">
                            <TableCell>Total</TableCell>
                            {COLUMNS.map((column) => (
                                <TableCell
                                    key={column.key}
                                    className="text-right"
                                >
                                    <Amount minor={totals[column.key]} />
                                </TableCell>
                            ))}
                        </TableRow>
                        <TableRow className="hover:bg-transparent">
                            <TableCell
                                colSpan={6}
                                className="font-normal text-muted-foreground"
                            >
                                {receivables
                                    ? 'Accounts receivable'
                                    : 'Accounts payable'}{' '}
                                balance
                            </TableCell>
                            <TableCell className="text-right font-normal">
                                <Amount minor={accountMinor} />
                            </TableCell>
                        </TableRow>
                        {differenceMinor !== 0 && (
                            <TableRow className="hover:bg-transparent">
                                <TableCell
                                    colSpan={6}
                                    className="font-normal text-muted-foreground"
                                >
                                    In the account but on no open{' '}
                                    {receivables ? 'invoice' : 'bill'} (opening
                                    balances, journals, payments or credits not
                                    set against one)
                                </TableCell>
                                <TableCell className="text-right font-normal">
                                    <Amount minor={differenceMinor} />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableFooter>
                </Table>
            </Panel>

            <Panel>
                <PanelHeader
                    title={receivables ? 'Open invoices' : 'Open bills'}
                />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{party}</TableHead>
                            <TableHead>Number</TableHead>
                            <TableHead>Date</TableHead>
                            <TableHead>Due</TableHead>
                            <TableHead className="text-right">
                                Days overdue
                            </TableHead>
                            <TableHead className="text-right">Total</TableHead>
                            <TableHead className="text-right">
                                Outstanding
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {documents.length === 0 && (
                            <TableEmpty colSpan={7}>
                                Nothing outstanding.
                            </TableEmpty>
                        )}
                        {documents.map((document) => (
                            <TableRow key={document.id}>
                                <TableCell>{document.party}</TableCell>
                                <TableCell className="font-mono text-xs">
                                    <Link
                                        href={
                                            receivables
                                                ? showInvoice(document.id)
                                                : showBill(document.id)
                                        }
                                        className="text-primary hover:underline"
                                    >
                                        {document.number}
                                    </Link>
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {document.date}
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {document.dueOn ?? '–'}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {document.daysOverdue > 0 ? (
                                        <StatusBadge
                                            tone={
                                                document.daysOverdue > 60
                                                    ? 'danger'
                                                    : 'warning'
                                            }
                                        >
                                            {document.daysOverdue}
                                        </StatusBadge>
                                    ) : (
                                        '–'
                                    )}
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={document.totalMinor} />
                                </TableCell>
                                <TableCell className="text-right font-medium">
                                    <Amount minor={document.outstandingMinor} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
