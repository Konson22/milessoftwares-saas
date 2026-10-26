import { Form, Head, Link } from '@inertiajs/react';
import { Plus, ReceiptText } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
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
import { create, index, show } from '@/routes/invoices';

type Invoice = {
    id: string;
    number: string;
    customer: string;
    issuedOn: string;
    dueOn: string | null;
    status: string;
    overdue: boolean;
    totalMinor: number;
    balanceMinor: number;
};

type Props = {
    invoices: Invoice[];
    filters: { status: string };
    outstandingMinor: number;
    toInvoice: number;
    canIssue: boolean;
};

const STATUS_FILTERS = [
    { value: 'issued', label: 'Unpaid' },
    { value: 'paid', label: 'Paid' },
    { value: 'voided', label: 'Voided' },
];

export default function Invoices({
    invoices,
    filters,
    outstandingMinor,
    toInvoice,
    canIssue,
}: Props) {
    const money = useMoney();
    const issue = canIssue ? (
        <Button asChild>
            <Link href={create()}>
                <Plus className="size-4" aria-hidden />
                Invoice delivered shipments
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Invoices" />
            <PageHeader
                title="Invoices"
                description={`${money(outstandingMinor)} owed by customers · ${toInvoice} delivered ${toInvoice === 1 ? 'shipment' : 'shipments'} not yet invoiced`}
                actions={issue}
            />

            <Panel>
                <PanelToolbar>
                    <Form {...index.form()} className="flex gap-2">
                        <Select
                            name="status"
                            aria-label="Status"
                            defaultValue={filters.status}
                            placeholder="Every invoice"
                            options={STATUS_FILTERS}
                            className="w-44"
                        />
                        <Button type="submit" variant="outline">
                            Show
                        </Button>
                    </Form>
                </PanelToolbar>

                {invoices.length === 0 ? (
                    <EmptyState
                        icon={ReceiptText}
                        title="No invoices here"
                        action={issue}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Invoice</TableHead>
                                <TableHead>Customer</TableHead>
                                <TableHead>Due</TableHead>
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
                                        <p className="text-xs text-muted-foreground">
                                            {formatDate(invoice.issuedOn)}
                                        </p>
                                    </TableCell>
                                    <TableCell>{invoice.customer}</TableCell>
                                    <TableCell className="tabular-nums">
                                        {invoice.dueOn
                                            ? formatDate(invoice.dueOn)
                                            : '—'}
                                    </TableCell>
                                    <TableCell>
                                        {invoice.overdue ? (
                                            <StatusBadge tone="danger">
                                                Overdue
                                            </StatusBadge>
                                        ) : (
                                            <Status status={invoice.status} />
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(invoice.totalMinor)}
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {invoice.balanceMinor > 0
                                            ? money(invoice.balanceMinor)
                                            : '—'}
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
