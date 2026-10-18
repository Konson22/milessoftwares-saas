import { Form, Head, Link } from '@inertiajs/react';
import { Ban } from 'lucide-react';
import { useState } from 'react';
import { Amount } from '@/components/amount';
import { Field, Select } from '@/components/field';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
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
import { useCan } from '@/lib/access';
import { sourceLabel, statusLabel, statusTone } from '@/lib/format';
import { store as receive } from '@/routes/customer-payments';
import { voidMethod as voidInvoice } from '@/routes/invoices';
import { show as showJournal } from '@/routes/journals';

type Props = {
    invoice: {
        id: string;
        number: string;
        customer: string | null;
        source: string;
        issuedOn: string;
        dueOn: string | null;
        totalMinor: number;
        taxMinor: number;
        paidMinor: number;
        creditedMinor: number;
        outstandingMinor: number;
        status: string;
        journalId: string | null;
        voidJournalId: string | null;
        voidReason: string | null;
        voidable: boolean;
        lines: {
            description: string;
            quantity: number | null;
            amount_minor: number;
            account: string | null;
        }[];
    };
    payments: {
        id: string;
        number: string;
        kind: string;
        method: string;
        amountMinor: number;
        receivedOn: string;
        account: string | null;
        journalId: string;
    }[];
    banks: { id: string; label: string }[];
    methods: { value: string; label: string }[];
    today: string;
};

/**
 * One invoice: its lines, payments and refunds, and receiving payment.
 */
export default function ShowInvoice({
    invoice,
    payments,
    banks,
    methods,
    today,
}: Props) {
    const can = useCan();
    const canManage = can('finance.receivables.manage');
    const [voiding, setVoiding] = useState(false);

    return (
        <>
            <Head title={`Invoice ${invoice.number}`} />
            <PageHeader
                title={`Invoice ${invoice.number}`}
                badges={
                    <StatusBadge tone={statusTone(invoice.status)}>
                        {statusLabel(invoice.status)}
                    </StatusBadge>
                }
                description={`${invoice.customer ?? 'No customer name'} · via ${sourceLabel(invoice.source)}`}
                actions={
                    invoice.voidable &&
                    canManage && (
                        <Button
                            variant="outline"
                            onClick={() => setVoiding((value) => !value)}
                        >
                            <Ban className="size-4" aria-hidden />
                            Void
                        </Button>
                    )
                }
            />

            {voiding && (
                <Panel className="mb-3">
                    <PanelHeader
                        title="Void this invoice"
                        description="Its journal is reversed; the invoice stays on record as void."
                    />
                    <PanelBody>
                        <Form
                            {...voidInvoice.form(invoice.id)}
                            onSuccess={() => setVoiding(false)}
                            className="flex flex-wrap items-end gap-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="reason"
                                        label="Reason"
                                        error={errors.reason}
                                        className="min-w-72 flex-1"
                                    >
                                        <Input
                                            id="reason"
                                            name="reason"
                                            required
                                            maxLength={200}
                                        />
                                    </Field>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        Void invoice
                                    </Button>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <div className="grid gap-3 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Description</TableHead>
                                <TableHead>Account</TableHead>
                                <TableHead className="text-right">
                                    Qty
                                </TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invoice.lines.length === 0 && (
                                <TableEmpty colSpan={4}>No lines.</TableEmpty>
                            )}
                            {invoice.lines.map((line, index) => (
                                <TableRow key={index}>
                                    <TableCell>{line.description}</TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {line.account ?? 'Sales'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {line.quantity ?? ''}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={line.amount_minor} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            {invoice.taxMinor > 0 && (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell colSpan={3}>Tax</TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={invoice.taxMinor} />
                                    </TableCell>
                                </TableRow>
                            )}
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={3}>Total</TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={invoice.totalMinor} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </Panel>

                <div className="grid content-start gap-3">
                    <Panel>
                        <PanelHeader title="Summary" />
                        <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-2 p-4 text-sm">
                            <dt className="text-muted-foreground">Date</dt>
                            <dd className="tabular-nums">{invoice.issuedOn}</dd>
                            <dt className="text-muted-foreground">Due</dt>
                            <dd className="tabular-nums">
                                {invoice.dueOn ?? '—'}
                            </dd>
                            {invoice.creditedMinor > 0 && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Returned
                                    </dt>
                                    <dd>
                                        <Amount minor={invoice.creditedMinor} />
                                    </dd>
                                </>
                            )}
                            <dt className="text-muted-foreground">Paid</dt>
                            <dd>
                                <Amount minor={invoice.paidMinor} />
                            </dd>
                            <dt className="text-muted-foreground">
                                Outstanding
                            </dt>
                            <dd className="font-semibold">
                                <Amount minor={invoice.outstandingMinor} />
                            </dd>
                            {invoice.journalId && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Posted
                                    </dt>
                                    <dd>
                                        <Link
                                            href={showJournal(
                                                invoice.journalId,
                                            )}
                                            className="text-primary hover:underline"
                                        >
                                            View journal
                                        </Link>
                                    </dd>
                                </>
                            )}
                            {invoice.voidReason && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Voided
                                    </dt>
                                    <dd>
                                        {invoice.voidReason}
                                        {invoice.voidJournalId && (
                                            <>
                                                {' · '}
                                                <Link
                                                    href={showJournal(
                                                        invoice.voidJournalId,
                                                    )}
                                                    className="text-primary hover:underline"
                                                >
                                                    reversal
                                                </Link>
                                            </>
                                        )}
                                    </dd>
                                </>
                            )}
                        </dl>
                    </Panel>

                    {canManage && invoice.outstandingMinor > 0 && (
                        <Panel>
                            <PanelHeader title="Receive payment" />
                            <PanelBody>
                                <Form
                                    {...receive.form(invoice.id)}
                                    resetOnSuccess
                                    className="grid gap-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <Field
                                                id="account_id"
                                                label="Into"
                                                error={errors.account_id}
                                            >
                                                <Select
                                                    id="account_id"
                                                    name="account_id"
                                                    required
                                                    defaultValue=""
                                                >
                                                    <option value="">
                                                        Choose an account…
                                                    </option>
                                                    {banks.map((bank) => (
                                                        <option
                                                            key={bank.id}
                                                            value={bank.id}
                                                        >
                                                            {bank.label}
                                                        </option>
                                                    ))}
                                                </Select>
                                            </Field>
                                            <Field
                                                id="method"
                                                label="Method"
                                                error={errors.method}
                                            >
                                                <Select
                                                    id="method"
                                                    name="method"
                                                    defaultValue="cash"
                                                >
                                                    {methods.map((method) => (
                                                        <option
                                                            key={method.value}
                                                            value={method.value}
                                                        >
                                                            {method.label}
                                                        </option>
                                                    ))}
                                                </Select>
                                            </Field>
                                            <div className="grid grid-cols-2 gap-3">
                                                <Field
                                                    id="amount"
                                                    label="Amount"
                                                    error={errors.amount}
                                                >
                                                    <Input
                                                        id="amount"
                                                        name="amount"
                                                        inputMode="decimal"
                                                        className="text-right tabular-nums"
                                                        required
                                                    />
                                                </Field>
                                                <Field
                                                    id="received_on"
                                                    label="Date"
                                                    error={errors.received_on}
                                                >
                                                    <Input
                                                        id="received_on"
                                                        name="received_on"
                                                        type="date"
                                                        defaultValue={today}
                                                        required
                                                    />
                                                </Field>
                                            </div>
                                            <Field
                                                id="memo"
                                                label="Memo"
                                                error={errors.memo}
                                            >
                                                <Input
                                                    id="memo"
                                                    name="memo"
                                                    maxLength={255}
                                                />
                                            </Field>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Record payment
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        </Panel>
                    )}

                    <Panel>
                        <PanelHeader title="Payments and refunds" />
                        {payments.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                None yet.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {payments.map((payment) => (
                                    <li
                                        key={payment.id}
                                        className="grid grid-cols-[1fr_auto] gap-x-3 px-4 py-2.5"
                                    >
                                        <Link
                                            href={showJournal(
                                                payment.journalId,
                                            )}
                                            className="font-mono text-xs text-primary hover:underline"
                                        >
                                            {payment.number}
                                        </Link>
                                        <Amount
                                            minor={
                                                payment.kind === 'refund'
                                                    ? -payment.amountMinor
                                                    : payment.amountMinor
                                            }
                                            className="text-right"
                                        />
                                        <span className="text-xs text-muted-foreground">
                                            {payment.kind === 'refund'
                                                ? 'Refund'
                                                : payment.method}{' '}
                                            · {payment.receivedOn}
                                        </span>
                                        <span className="text-right text-xs text-muted-foreground">
                                            {payment.account}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>
            </div>
        </>
    );
}
