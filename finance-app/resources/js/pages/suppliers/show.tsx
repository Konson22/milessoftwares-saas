import { Form, Head, Link } from '@inertiajs/react';
import { Banknote, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { Amount } from '@/components/amount';
import { DocumentsTable } from '@/components/documents-table';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@/components/panel';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/lib/access';
import { sourceLabel, useMoney } from '@/lib/format';
import { create as newBill, show as showBill } from '@/routes/bills';
import { show as showJournal } from '@/routes/journals';
import { create as pay } from '@/routes/supplier-payments';
import { update } from '@/routes/suppliers';

type Props = {
    supplier: {
        id: string;
        name: string;
        email: string | null;
        phone: string | null;
        source: string | null;
        outstandingMinor: number;
    };
    bills: {
        id: string;
        number: string;
        source: string;
        billDate: string;
        dueOn: string | null;
        totalMinor: number;
        outstandingMinor: number;
        status: string;
    }[];
    payments: {
        id: string;
        number: string;
        paidOn: string;
        amountMinor: number;
        account: string | null;
        reference: string | null;
        journalId: string;
    }[];
    credits: {
        id: string;
        number: string;
        creditedOn: string;
        billId: string | null;
        billNumber: string | null;
        totalMinor: number;
        unappliedMinor: number;
        reason: string;
        journalId: string | null;
    }[];
};

/**
 * One supplier: bills, payments, credits and what is owed.
 */
export default function ShowSupplier({
    supplier,
    bills,
    payments,
    credits,
}: Props) {
    const can = useCan();
    const money = useMoney();
    const canManage = can('finance.payables.manage');
    const [editing, setEditing] = useState(false);

    return (
        <>
            <Head title={supplier.name} />
            <PageHeader
                title={supplier.name}
                badges={
                    supplier.source && (
                        <StatusBadge tone="info">
                            From {sourceLabel(supplier.source)}
                        </StatusBadge>
                    )
                }
                description={`Owed: ${money(supplier.outstandingMinor)}${supplier.phone ? ` · ${supplier.phone}` : ''}${supplier.email ? ` · ${supplier.email}` : ''}`}
                actions={
                    canManage && (
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setEditing((value) => !value)}
                            >
                                <Pencil className="size-4" aria-hidden />
                                Edit
                            </Button>
                            <Button variant="outline" asChild>
                                <Link
                                    href={newBill({
                                        query: { supplier: supplier.id },
                                    })}
                                >
                                    <Plus className="size-4" aria-hidden />
                                    New bill
                                </Link>
                            </Button>
                            <Button asChild>
                                <Link
                                    href={pay({
                                        query: { supplier: supplier.id },
                                    })}
                                >
                                    <Banknote className="size-4" aria-hidden />
                                    Pay
                                </Link>
                            </Button>
                        </>
                    )
                }
            />

            {editing && (
                <Panel className="mb-5">
                    <PanelHeader title="Edit supplier" />
                    <PanelBody>
                        <Form
                            {...update.form(supplier.id)}
                            onSuccess={() => setEditing(false)}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="name"
                                        label="Name"
                                        error={errors.name}
                                        className="sm:col-span-2"
                                    >
                                        <Input
                                            id="name"
                                            name="name"
                                            defaultValue={supplier.name}
                                            required
                                        />
                                    </Field>
                                    <Field
                                        id="email"
                                        label="Email"
                                        error={errors.email}
                                    >
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            defaultValue={supplier.email ?? ''}
                                        />
                                    </Field>
                                    <Field
                                        id="phone"
                                        label="Phone"
                                        error={errors.phone}
                                    >
                                        <Input
                                            id="phone"
                                            name="phone"
                                            defaultValue={supplier.phone ?? ''}
                                        />
                                    </Field>
                                    <div className="sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Save
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel className="mb-5">
                <PanelHeader title="Bills" />
                <DocumentsTable
                    rows={bills.map((bill) => ({
                        ...bill,
                        party: supplier.name,
                        date: bill.billDate,
                    }))}
                    partyLabel="Supplier"
                    href={(id) => showBill(id)}
                    empty="No bills from this supplier."
                    showParty={false}
                />
            </Panel>

            <Panel>
                <PanelHeader title="Payments" />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Number</TableHead>
                            <TableHead>Date</TableHead>
                            <TableHead>Paid from</TableHead>
                            <TableHead>Reference</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {payments.length === 0 && (
                            <TableEmpty colSpan={5}>
                                No payments yet.
                            </TableEmpty>
                        )}
                        {payments.map((payment) => (
                            <TableRow key={payment.id}>
                                <TableCell className="font-mono text-xs">
                                    <Link
                                        href={showJournal(payment.journalId)}
                                        className="text-primary hover:underline"
                                    >
                                        {payment.number}
                                    </Link>
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {payment.paidOn}
                                </TableCell>
                                <TableCell>{payment.account}</TableCell>
                                <TableCell className="text-muted-foreground">
                                    {payment.reference ?? '—'}
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={payment.amountMinor} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>

            {credits.length > 0 && (
                <Panel className="mt-5">
                    <PanelHeader
                        title="Credits"
                        description="Goods returned: taken off the bill they came on; any rest stays on account."
                    />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Number</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead>Bill</TableHead>
                                <TableHead>Reason</TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                                <TableHead className="text-right">
                                    On account
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {credits.map((credit) => (
                                <TableRow key={credit.id}>
                                    <TableCell className="font-mono text-xs">
                                        {credit.journalId ? (
                                            <Link
                                                href={showJournal(
                                                    credit.journalId,
                                                )}
                                                className="text-primary hover:underline"
                                            >
                                                {credit.number}
                                            </Link>
                                        ) : (
                                            credit.number
                                        )}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {credit.creditedOn}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {credit.billId ? (
                                            <Link
                                                href={showBill(credit.billId)}
                                                className="hover:underline"
                                            >
                                                {credit.billNumber}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {credit.reason}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={credit.totalMinor} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            minor={credit.unappliedMinor}
                                            blankZero
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>
            )}
        </>
    );
}
