import { Form, Head, Link } from '@inertiajs/react';
import { ReceiptText } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Select } from '@/components/field';
import { Status } from '@/components/status';
import { formatDate } from '@/lib/status';
import { useMoney } from '@/lib/use-money';
import { index, show } from '@/routes/invoices';
import type { Option } from '@/types';

type Invoice = {
    id: string;
    number: string;
    student: string;
    studentNumber: string;
    term: string | null;
    issuedOn: string;
    dueOn: string | null;
    status: string;
    overdue: boolean;
    totalMinor: number;
    balanceMinor: number;
};

type Props = {
    invoices: Invoice[];
    filters: { status: string; term: string; search: string };
    terms: Option[];
    outstandingMinor: number;
};

const STATUS_FILTERS: Option[] = [
    { value: 'issued', label: 'Unpaid' },
    { value: 'paid', label: 'Paid' },
    { value: 'voided', label: 'Voided' },
];

/**
 * Fee invoices, newest first.
 */
export default function Invoices({
    invoices,
    filters,
    terms,
    outstandingMinor,
}: Props) {
    const money = useMoney();

    return (
        <>
            <Head title="Invoices" />
            <PageHeader
                title="Invoices"
                description={`${money(outstandingMinor)} owed in fees.`}
            />

            <Panel>
                <PanelToolbar>
                    <Form {...index.form()} className="flex flex-wrap gap-2">
                        <SearchField
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Invoice, student or number"
                            aria-label="Search invoices"
                        />
                        <Select
                            name="term"
                            aria-label="Term"
                            defaultValue={filters.term}
                            placeholder="Every term"
                            options={terms}
                            className="w-auto"
                        />
                        <Select
                            name="status"
                            aria-label="Status"
                            defaultValue={filters.status}
                            placeholder="Every status"
                            options={STATUS_FILTERS}
                            className="w-auto"
                        />
                        <Button type="submit" variant="outline">
                            Show
                        </Button>
                    </Form>
                </PanelToolbar>

                {invoices.length === 0 ? (
                    <EmptyState icon={ReceiptText} title="No invoices found">
                        Bill a term's fees from the Fees page.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Invoice</TableHead>
                                <TableHead>Student</TableHead>
                                <TableHead>Term</TableHead>
                                <TableHead>Issued</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                <TableHead className="text-right">
                                    Balance
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invoices.map((invoice) => (
                                <TableRow key={invoice.id}>
                                    <TableCell>
                                        <Link
                                            href={show(invoice.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {invoice.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {invoice.student}
                                        <span className="ml-2 font-mono text-xs text-muted-foreground">
                                            {invoice.studentNumber}
                                        </span>
                                    </TableCell>
                                    <TableCell>{invoice.term}</TableCell>
                                    <TableCell className="tabular-nums">
                                        {formatDate(invoice.issuedOn)}
                                    </TableCell>
                                    <TableCell>
                                        <Status status={invoice.status} />
                                        {invoice.overdue && (
                                            <StatusBadge
                                                tone="danger"
                                                className="ml-1"
                                            >
                                                Overdue
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(invoice.totalMinor)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(invoice.balanceMinor)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
