import { Head, Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { SelectField, Status, TextField } from '@/components/fields';
import { formatDate, money } from '@/lib/procurement';
import { show as order } from '@/routes/orders';
import { exportMethod, index } from '@/routes/reports';

type Props = {
    by: string;
    from: string | null;
    to: string | null;
    currency: string;
    rows: {
        label: string;
        orders: number;
        committed: string;
        invoiced: string;
        paidExternally: string;
    }[];
    totals: {
        committedMinor: string;
        invoicedMinor: string;
        paidExternallyMinor: string;
        sentToFinanceMinor: string;
    };
    unpaidInvoices: {
        id: string;
        number: string;
        supplier: string;
        orderId: string;
        order: string;
        amount: string;
        currency: string;
        dueDate: string | null;
        matchStatus: string;
    }[];
};

const DIMENSIONS = [
    { value: 'supplier', label: 'Supplier' },
    { value: 'department', label: 'Department' },
    { value: 'method', label: 'Method' },
    { value: 'month', label: 'Month approved' },
];

export default function Reports({
    by,
    from,
    to,
    currency,
    rows,
    totals,
    unpaidInvoices,
}: Props) {
    const [range, setRange] = useState({ from: from ?? '', to: to ?? '' });
    const go = (next: { by?: string; from?: string; to?: string }) =>
        router.get(
            index().url,
            {
                by: next.by ?? by,
                from: (next.from ?? range.from) || undefined,
                to: (next.to ?? range.to) || undefined,
            },
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Reports" />
            <PageHeader
                title="Reports"
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <a href={exportMethod().url}>
                            <Download /> All orders (CSV)
                        </a>
                    </Button>
                }
            />
            <div className="grid gap-6">
                <Panel>
                    <PanelBody>
                        <dl className="grid gap-4 sm:grid-cols-4">
                            {(
                                [
                                    ['Committed', totals.committedMinor],
                                    ['Invoiced', totals.invoicedMinor],
                                    [
                                        'Paid, recorded by hand (not verified)',
                                        totals.paidExternallyMinor,
                                    ],
                                    [
                                        'Sent to Finance',
                                        totals.sentToFinanceMinor,
                                    ],
                                ] as const
                            ).map(([label, value]) => (
                                <div key={label} className="grid gap-1">
                                    <dt className="text-xs text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd className="text-lg font-semibold tabular-nums">
                                        {money(value, currency)}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Spend"
                        description={`Approved orders not cancelled, in ${currency}.`}
                    />
                    <PanelToolbar className="flex flex-wrap items-end gap-4">
                        <SelectField
                            label="Group by"
                            value={by}
                            onChange={(v) => go({ by: v })}
                            options={DIMENSIONS}
                            className="w-48"
                        />
                        <TextField
                            label="Approved from"
                            type="date"
                            value={range.from}
                            onChange={(v) => setRange({ ...range, from: v })}
                        />
                        <TextField
                            label="to"
                            type="date"
                            value={range.to}
                            onChange={(v) => setRange({ ...range, to: v })}
                        />
                        <Button variant="outline" onClick={() => go({})}>
                            Apply
                        </Button>
                    </PanelToolbar>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {DIMENSIONS.find((d) => d.value === by)
                                        ?.label ?? by}
                                </TableHead>
                                <TableHead className="text-right">
                                    Orders
                                </TableHead>
                                <TableHead className="text-right">
                                    Committed
                                </TableHead>
                                <TableHead className="text-right">
                                    Invoiced
                                </TableHead>
                                <TableHead className="text-right">
                                    Paid (by hand)
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="text-muted-foreground"
                                    >
                                        No approved orders in this range.
                                    </TableCell>
                                </TableRow>
                            )}
                            {rows.map((row) => (
                                <TableRow key={row.label}>
                                    <TableCell>{row.label}</TableCell>
                                    <TableCell className="text-right">
                                        {row.orders}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(row.committed, currency)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(row.invoiced, currency)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(row.paidExternally, currency)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Unpaid supplier invoices"
                        description="Not sent to Finance and not marked paid."
                    />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Invoice</TableHead>
                                <TableHead>Order</TableHead>
                                <TableHead>Due</TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                                <TableHead>Match</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {unpaidInvoices.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="text-muted-foreground"
                                    >
                                        None.
                                    </TableCell>
                                </TableRow>
                            )}
                            {unpaidInvoices.map((inv) => (
                                <TableRow key={inv.id}>
                                    <TableCell>
                                        {inv.number}
                                        <span className="block text-xs text-muted-foreground">
                                            {inv.supplier}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <Link
                                            href={order(inv.orderId)}
                                            className="hover:underline"
                                        >
                                            {inv.order}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {formatDate(inv.dueDate)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(inv.amount, inv.currency)}
                                    </TableCell>
                                    <TableCell>
                                        <Status value={inv.matchStatus} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>
            </div>
        </>
    );
}
