import { Head, Link, useForm } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import {
    ActionButton,
    ApprovalPanel,
    DocumentsPanel,
} from '@/components/actions';
import {
    Detail,
    Errors,
    SelectField,
    Status,
    TextArea,
    TextField,
} from '@/components/fields';
import type { EditableLine } from '@/components/lines-editor';
import { LinesEditor } from '@/components/lines-editor';
import type { Approval, Documents } from '@/lib/procurement';
import { formatDate, money, quantity } from '@/lib/procurement';
import { paid, voidMethod } from '@/routes/invoices';
import {
    amend,
    cancel,
    close,
    decide,
    issue,
    print,
    receive,
    submit,
    update,
} from '@/routes/orders';
import { store as storeInvoice } from '@/routes/orders/invoices';
import { show as requisition } from '@/routes/requisitions';
import { show as sourcing } from '@/routes/sourcing';
import { show as supplier } from '@/routes/suppliers';

type Line = {
    id: string;
    kind: string;
    description: string;
    quantity: string;
    unit: string | null;
    unitPrice: string;
    amount: string;
    accepted: string;
    remaining: string;
};

type Order = {
    id: string;
    number: string | null;
    status: string;
    method: string;
    methodLabel: string;
    methodReason: string | null;
    statusReason: string | null;
    revision: number;
    supplier: string;
    supplierId: string;
    currency: string;
    rate: string | null;
    total: string;
    totalBase: string | null;
    subtotal: string;
    discount: string;
    tax: string;
    delivery: string;
    deliveryDate: string | null;
    deliveryAddress: string | null;
    paymentTerms: string | null;
    notes: string | null;
    late: boolean;
    preparedBy: string;
    awardBased: boolean;
    requisition: { id: string; number: string; title: string } | null;
    sourcing: { id: string; number: string } | null;
    acceptedValue: string;
    invoiced: string;
    lines: Line[];
    receipts: {
        id: string;
        number: string;
        receivedOn: string | null;
        deliveryNote: string | null;
        inspection: string;
        notes: string | null;
        by: string | null;
        lines: {
            lineId: string;
            received: string;
            accepted: string;
            rejected: string;
            reason: string | null;
        }[];
    }[];
    invoices: {
        id: string;
        number: string;
        invoiceDate: string | null;
        dueDate: string | null;
        amount: string;
        matchStatus: string;
        status: string;
        voidReason: string | null;
        paymentStatus: string;
        paidOn: string | null;
        paymentReference: string | null;
        financeStatus: string | null;
    }[];
    revisions: {
        revision: number;
        reason: string;
        total: string;
        date: string | null;
    }[];
};

type Props = {
    order: Order;
    baseCurrency: string;
    financeConnected: boolean;
    approval: Approval;
    documents: Documents;
    can: { manage: boolean; receive: boolean; invoice: boolean };
};

export default function ShowOrder({
    order: o,
    baseCurrency,
    financeConnected,
    approval,
    documents,
    can,
}: Props) {
    const draft = ['draft', 'returned'].includes(o.status);
    const open = ['issued', 'partially_received', 'received'].includes(
        o.status,
    );
    const nothingReceived = o.receipts.length === 0;

    return (
        <>
            <Head title={o.number ?? 'Draft order'} />
            <PageHeader
                title={`${o.number ?? 'Draft order'} · ${o.supplier}`}
                badges={
                    <>
                        <Status value={o.status} />
                        {o.late && (
                            <StatusBadge tone="danger">Late</StatusBadge>
                        )}
                    </>
                }
                actions={
                    <>
                        {o.number && (
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={print(o.id).url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Printer /> Print
                                </a>
                            </Button>
                        )}
                        {can.manage && draft && (
                            <ActionButton
                                url={submit(o.id).url}
                                label="Submit for approval"
                            />
                        )}
                        {can.manage && o.status === 'approved' && (
                            <ActionButton
                                url={issue(o.id).url}
                                label="Issue to supplier"
                            />
                        )}
                        {can.manage &&
                            ['approved', 'issued'].includes(o.status) &&
                            nothingReceived && (
                                <ActionButton
                                    url={amend(o.id).url}
                                    label="Amend"
                                    variant="outline"
                                    reason="Why does the order change?"
                                />
                            )}
                        {can.manage && open && (
                            <ActionButton
                                url={close(o.id).url}
                                label="Close order"
                                variant="outline"
                                reason="Reason (needed if not everything arrived)"
                                reasonOptional
                            />
                        )}
                        {can.manage &&
                            !['closed', 'cancelled'].includes(o.status) &&
                            nothingReceived && (
                                <ActionButton
                                    url={cancel(o.id).url}
                                    label="Cancel"
                                    variant="destructive"
                                    reason="Why is it cancelled?"
                                />
                            )}
                    </>
                }
            />
            <div className="grid gap-6">
                {o.statusReason && (
                    <div className="rounded-md border border-amber-500 bg-background px-3 py-2 text-sm">
                        {o.statusReason}
                    </div>
                )}

                <Panel>
                    <PanelBody>
                        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Detail label="Supplier">
                                <Link
                                    href={supplier(o.supplierId)}
                                    className="hover:underline"
                                >
                                    {o.supplier}
                                </Link>
                            </Detail>
                            <Detail label="Method">
                                {o.methodLabel}
                                {o.sourcing && (
                                    <>
                                        {' · '}
                                        <Link
                                            href={sourcing(o.sourcing.id)}
                                            className="hover:underline"
                                        >
                                            {o.sourcing.number}
                                        </Link>
                                    </>
                                )}
                            </Detail>
                            <Detail label="Requisition">
                                {o.requisition ? (
                                    <Link
                                        href={requisition(o.requisition.id)}
                                        className="hover:underline"
                                    >
                                        {o.requisition.number}
                                    </Link>
                                ) : null}
                            </Detail>
                            <Detail label="Prepared by">{o.preparedBy}</Detail>
                            <Detail label="Delivery">
                                {formatDate(o.deliveryDate)}
                            </Detail>
                            <Detail label="Payment terms">
                                {o.paymentTerms}
                            </Detail>
                            <Detail label="Total">
                                {money(o.total, o.currency)}
                                {o.totalBase && (
                                    <span className="block text-xs text-muted-foreground">
                                        ≈ {money(o.totalBase, baseCurrency)} at{' '}
                                        {o.rate}
                                    </span>
                                )}
                            </Detail>
                            <Detail label="Revision">{o.revision}</Detail>
                            <Detail label="Accepted so far">
                                {money(o.acceptedValue, o.currency)}
                            </Detail>
                            <Detail label="Invoiced">
                                {money(o.invoiced, o.currency)}
                            </Detail>
                        </dl>
                        {o.deliveryAddress && (
                            <p className="mt-4 text-sm whitespace-pre-line">
                                <span className="text-muted-foreground">
                                    Deliver to:{' '}
                                </span>
                                {o.deliveryAddress}
                            </p>
                        )}
                    </PanelBody>
                </Panel>

                {can.manage && draft ? (
                    <EditOrder order={o} />
                ) : (
                    <Panel>
                        <PanelHeader title="Lines" />
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Item</TableHead>
                                    <TableHead className="text-right">
                                        Ordered
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Accepted
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Unit price
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {o.lines.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell className="whitespace-normal">
                                            {line.description}
                                            <span className="block text-xs text-muted-foreground">
                                                {line.kind === 'service'
                                                    ? 'Service'
                                                    : 'Goods'}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {quantity(line.quantity)}{' '}
                                            {line.unit}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {quantity(line.accepted)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(line.unitPrice, o.currency)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(line.amount, o.currency)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>
                )}

                <ApprovalPanel
                    approval={approval}
                    decideUrl={decide(o.id).url}
                />

                {can.receive && <Receive order={o} />}

                {o.receipts.length > 0 && (
                    <Panel>
                        <PanelHeader title="Receipts" />
                        <PanelBody className="grid gap-4">
                            {o.receipts.map((r) => (
                                <div key={r.id} className="grid gap-1 text-sm">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {r.number}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {formatDate(r.receivedOn)} · {r.by}
                                            {r.deliveryNote &&
                                                ` · note ${r.deliveryNote}`}
                                        </span>
                                        <Status value={r.inspection} />
                                    </div>
                                    <ul className="text-muted-foreground">
                                        {r.lines.map((l) => {
                                            const line = o.lines.find(
                                                (x) => x.id === l.lineId,
                                            );

                                            return (
                                                <li key={l.lineId}>
                                                    {line?.description}:{' '}
                                                    {quantity(l.accepted)}{' '}
                                                    accepted
                                                    {Number(l.rejected) > 0 &&
                                                        `, ${quantity(l.rejected)} rejected (${l.reason})`}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            ))}
                        </PanelBody>
                    </Panel>
                )}

                {(o.invoices.length > 0 || (can.invoice && open)) && (
                    <Invoices
                        order={o}
                        canInvoice={can.invoice}
                        financeConnected={financeConnected}
                    />
                )}

                {o.revisions.length > 0 && (
                    <Panel>
                        <PanelHeader title="Earlier revisions" />
                        <PanelBody>
                            <ul className="grid gap-1 text-sm">
                                {o.revisions.map((r) => (
                                    <li key={r.revision}>
                                        Revision {r.revision} ·{' '}
                                        {money(r.total, o.currency)} ·{' '}
                                        {formatDate(r.date)} · {r.reason}
                                    </li>
                                ))}
                            </ul>
                        </PanelBody>
                    </Panel>
                )}

                <DocumentsPanel documents={documents} />
            </div>
        </>
    );
}

function EditOrder({ order }: { order: Order }) {
    const form = useForm({
        delivery_date: order.deliveryDate ?? '',
        delivery_address: order.deliveryAddress ?? '',
        payment_terms: order.paymentTerms ?? '',
        notes: order.notes ?? '',
        lines: order.lines.map((l): EditableLine => ({
            id: l.id,
            kind: l.kind,
            description: l.description,
            quantity: quantity(l.quantity),
            unit: l.unit ?? '',
            unit_price: l.unitPrice,
        })),
    });
    const errors = form.errors as Record<string, string>;

    return (
        <Panel>
            <PanelHeader
                title="Order details"
                description={
                    order.awardBased
                        ? 'Prices come from the awarded quotation.'
                        : undefined
                }
            />
            <PanelBody>
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(update(order.id).url, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-3">
                        <TextField
                            label="Delivery date"
                            type="date"
                            value={form.data.delivery_date}
                            onChange={(v) => form.setData('delivery_date', v)}
                            error={errors.delivery_date}
                        />
                        <TextField
                            label="Payment terms"
                            value={form.data.payment_terms}
                            onChange={(v) => form.setData('payment_terms', v)}
                            error={errors.payment_terms}
                        />
                        <TextArea
                            label="Deliver to"
                            value={form.data.delivery_address}
                            onChange={(v) =>
                                form.setData('delivery_address', v)
                            }
                            rows={2}
                        />
                        <TextArea
                            label="Notes to the supplier"
                            value={form.data.notes}
                            onChange={(v) => form.setData('notes', v)}
                            rows={2}
                            className="sm:col-span-3"
                        />
                    </div>
                    <LinesEditor
                        lines={form.data.lines}
                        onChange={(lines) => form.setData('lines', lines)}
                        errors={errors}
                        currency={order.currency}
                        specification={false}
                    />
                    <Errors
                        errors={errors.order ? { order: errors.order } : {}}
                    />
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Save order
                        </Button>
                    </div>
                </form>
            </PanelBody>
        </Panel>
    );
}

function Receive({ order }: { order: Order }) {
    const form = useForm({
        received_on: new Date().toISOString().slice(0, 10),
        delivery_note: '',
        inspection: 'passed',
        notes: '',
        lines: order.lines
            .filter((l) => Number(l.remaining) > 0)
            .map((l) => ({
                line_id: l.id,
                accepted: '',
                rejected: '',
                rejection_reason: '',
            })),
    });
    const errors = form.errors as Record<string, string>;
    const set = (i: number, patch: Partial<(typeof form.data.lines)[number]>) =>
        form.setData(
            'lines',
            form.data.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)),
        );

    if (form.data.lines.length === 0) {
        return null;
    }

    return (
        <Panel>
            <PanelHeader
                title="Record a delivery"
                description="Accept what meets the order; reject the rest with a reason. Services are accepted the same way."
            />
            <PanelBody>
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            lines: data.lines.filter(
                                (l) => l.accepted !== '' || l.rejected !== '',
                            ),
                        }));
                        form.post(receive(order.id).url, {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-3">
                        <TextField
                            label="Received on"
                            type="date"
                            value={form.data.received_on}
                            onChange={(v) => form.setData('received_on', v)}
                            error={errors.received_on}
                        />
                        <TextField
                            label="Delivery note"
                            value={form.data.delivery_note}
                            onChange={(v) => form.setData('delivery_note', v)}
                        />
                        <SelectField
                            label="Inspection"
                            value={form.data.inspection}
                            onChange={(v) => form.setData('inspection', v)}
                            options={[
                                { value: 'passed', label: 'Passed' },
                                { value: 'partial', label: 'Partly accepted' },
                                { value: 'failed', label: 'Failed' },
                            ]}
                        />
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Item</TableHead>
                                <TableHead className="text-right">
                                    Still due
                                </TableHead>
                                <TableHead>Accepted</TableHead>
                                <TableHead>Rejected</TableHead>
                                <TableHead>Why rejected</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {form.data.lines.map((row, i) => {
                                const line = order.lines.find(
                                    (l) => l.id === row.line_id,
                                )!;

                                return (
                                    <TableRow key={row.line_id}>
                                        <TableCell className="whitespace-normal">
                                            {line.description}
                                            {[
                                                `lines.${i}.accepted`,
                                                `lines.${i}.rejection_reason`,
                                            ]
                                                .filter((k) => errors[k])
                                                .map((k) => (
                                                    <span
                                                        key={k}
                                                        className="block text-xs text-destructive"
                                                    >
                                                        {errors[k]}
                                                    </span>
                                                ))}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {quantity(line.remaining)}{' '}
                                            {line.unit}
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                aria-label={`Accepted ${line.description}`}
                                                inputMode="decimal"
                                                value={row.accepted}
                                                onChange={(e) =>
                                                    set(i, {
                                                        accepted:
                                                            e.target.value,
                                                    })
                                                }
                                                className="w-24"
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                aria-label={`Rejected ${line.description}`}
                                                inputMode="decimal"
                                                value={row.rejected}
                                                onChange={(e) =>
                                                    set(i, {
                                                        rejected:
                                                            e.target.value,
                                                    })
                                                }
                                                className="w-24"
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                aria-label={`Why ${line.description} was rejected`}
                                                value={row.rejection_reason}
                                                onChange={(e) =>
                                                    set(i, {
                                                        rejection_reason:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                    <Errors
                        errors={
                            errors.order
                                ? { order: errors.order }
                                : errors.lines
                                  ? { lines: errors.lines }
                                  : {}
                        }
                    />
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Record receipt
                        </Button>
                    </div>
                </form>
            </PanelBody>
        </Panel>
    );
}

function Invoices({
    order,
    canInvoice,
    financeConnected,
}: {
    order: Order;
    canInvoice: boolean;
    financeConnected: boolean;
}) {
    const open = ['issued', 'partially_received', 'received'].includes(
        order.status,
    );
    const form = useForm({
        number: '',
        invoice_date: new Date().toISOString().slice(0, 10),
        due_date: '',
        amount: '',
    });
    const errors = form.errors as Record<string, string>;

    return (
        <Panel>
            <PanelHeader
                title="Supplier invoices"
                description={
                    financeConnected
                        ? 'Matched invoices go to Finance as bills; Finance records the payment.'
                        : 'Tracked here only. Payments noted here are not verified by any accounting system.'
                }
            />
            {order.invoices.length > 0 && (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Invoice</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                            <TableHead>Match</TableHead>
                            <TableHead>Payment</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {order.invoices.map((inv) => (
                            <TableRow key={inv.id}>
                                <TableCell>
                                    <span className="font-medium">
                                        {inv.number}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {formatDate(inv.invoiceDate)}
                                        {inv.dueDate &&
                                            ` · due ${formatDate(inv.dueDate)}`}
                                    </span>
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(inv.amount, order.currency)}
                                </TableCell>
                                <TableCell>
                                    {inv.status === 'voided' ? (
                                        <span title={inv.voidReason ?? ''}>
                                            <Status value="voided" />
                                        </span>
                                    ) : (
                                        <Status value={inv.matchStatus} />
                                    )}
                                </TableCell>
                                <TableCell>
                                    {inv.financeStatus === 'sent' ? (
                                        <StatusBadge tone="info">
                                            With Finance
                                        </StatusBadge>
                                    ) : (
                                        <Status value={inv.paymentStatus} />
                                    )}
                                    {inv.paidOn && (
                                        <span className="block text-xs text-muted-foreground">
                                            {formatDate(inv.paidOn)}
                                            {inv.paymentReference &&
                                                ` · ${inv.paymentReference}`}{' '}
                                            · not verified
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-right">
                                    {canInvoice &&
                                        inv.status === 'recorded' &&
                                        inv.financeStatus !== 'sent' && (
                                            <div className="flex justify-end gap-2">
                                                {inv.paymentStatus ===
                                                    'unpaid' && (
                                                    <MarkPaid
                                                        invoiceId={inv.id}
                                                    />
                                                )}
                                                {inv.paymentStatus ===
                                                    'unpaid' && (
                                                    <ActionButton
                                                        url={
                                                            voidMethod(inv.id)
                                                                .url
                                                        }
                                                        label="Void"
                                                        variant="outline"
                                                        reason="Why is it voided?"
                                                    />
                                                )}
                                            </div>
                                        )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
            {canInvoice && open && (
                <PanelBody className="border-t">
                    <form
                        className="grid gap-3 sm:grid-cols-5 sm:items-end"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(storeInvoice(order.id).url, {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <TextField
                            label="Supplier's invoice number"
                            value={form.data.number}
                            onChange={(v) => form.setData('number', v)}
                            error={errors.number}
                        />
                        <TextField
                            label="Invoice date"
                            type="date"
                            value={form.data.invoice_date}
                            onChange={(v) => form.setData('invoice_date', v)}
                            error={errors.invoice_date}
                        />
                        <TextField
                            label="Due"
                            type="date"
                            value={form.data.due_date}
                            onChange={(v) => form.setData('due_date', v)}
                            error={errors.due_date}
                        />
                        <TextField
                            label={`Amount (${order.currency})`}
                            inputMode="decimal"
                            value={form.data.amount}
                            onChange={(v) => form.setData('amount', v)}
                            error={errors.amount}
                        />
                        <div>
                            <Button type="submit" disabled={form.processing}>
                                Record invoice
                            </Button>
                        </div>
                    </form>
                    <Errors
                        errors={
                            errors.invoice ? { invoice: errors.invoice } : {}
                        }
                    />
                </PanelBody>
            )}
        </Panel>
    );
}

function MarkPaid({ invoiceId }: { invoiceId: string }) {
    const form = useForm({
        paid_on: new Date().toISOString().slice(0, 10),
        payment_reference: '',
    });

    return (
        <form
            className="flex items-center gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(paid(invoiceId).url, { preserveScroll: true });
            }}
        >
            <Input
                type="date"
                aria-label="Paid on"
                value={form.data.paid_on}
                onChange={(e) => form.setData('paid_on', e.target.value)}
                className="w-36"
            />
            <Input
                aria-label="Payment reference"
                placeholder="Reference"
                value={form.data.payment_reference}
                onChange={(e) =>
                    form.setData('payment_reference', e.target.value)
                }
                className="w-28"
            />
            <Button
                type="submit"
                size="sm"
                variant="outline"
                disabled={form.processing}
            >
                Mark paid
            </Button>
        </form>
    );
}
