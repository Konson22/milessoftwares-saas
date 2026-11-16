import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
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
import { Field, invalid, Select } from '@/components/field';
import { formatMoney } from '@/lib/money';
import { formatDate, formatDateTime, statusTone } from '@/lib/status';
import { approve, cancel, index, reject, submit } from '@/routes/procurement';
import {
    close as closeOrder,
    invoice as recordInvoice,
    receive,
    store as raiseOrder,
} from '@/routes/procurement/orders';
import {
    document as invoiceDocument,
    voidMethod as voidInvoice,
} from '@/routes/procurement/orders/invoices';
import { evidence as receiptEvidence } from '@/routes/procurement/orders/receipts';
import {
    choose,
    destroy as removeQuote,
    document as quoteDocument,
    store as addQuote,
} from '@/routes/procurement/quotes';
import type { Option } from '@/types';

type Line = {
    id: string;
    description: string;
    quantity: string;
    unit: string | null;
    unitPriceMinor: number;
    amountMinor: number;
    received: string;
    rejected: string;
    left: string;
};

type Quote = {
    id: string;
    supplier: string;
    amountMinor: number;
    reference: string | null;
    validUntil: string | null;
    expired: boolean;
    document: string | null;
    selected: boolean;
};

type Invoice = {
    id: string;
    number: string;
    amountMinor: number;
    currency: string;
    on: string;
    dueOn: string | null;
    document: string | null;
    by: string;
    voided: { reason: string | null; on: string | null } | null;
};

type Order = {
    id: string;
    number: string;
    supplier: string;
    amountMinor: number;
    commitmentMinor: number;
    invoicedMinor: number;
    finance: { status: string; label: string } | null;
    legacyInvoice: boolean;
    payable: boolean;
    closed: { on: string; reason: string | null } | null;
    invoices: Invoice[];
    can: { invoice: boolean; close: boolean };
    terms: string | null;
    spending: string;
    spendingLabel: string;
    live: boolean;
    invoiceReference: string | null;
    invoiceOn: string | null;
    fullyReceived: boolean;
    receipts: {
        id: string;
        on: string;
        by: string;
        note: string | null;
        lines: number;
        rejected: boolean;
        evidence: string | null;
    }[];
};

type Props = {
    request: {
        id: string;
        number: string;
        title: string;
        justification: string;
        category: string;
        project: string;
        grant: string | null;
        currency: string;
        status: string;
        statusLabel: string;
        estimateMinor: number;
        neededBy: string | null;
        requester: string;
        createdOn: string;
        decider: string | null;
        decidedAt: string | null;
        decisionNote: string | null;
        selectionReason: string | null;
    };
    lines: Line[];
    quotes: Quote[];
    budget: { leftMinor: number | null; lineLeftMinor: number | null } | null;
    budgetLine: { label: string; approved: boolean } | null;
    budgetLines: {
        value: string;
        label: string;
        category: string;
        leftMinor: number | null;
    }[];
    minQuotes: number;
    orders: Order[];
    suppliers: Option[];
    can: {
        submit: boolean;
        decide: boolean;
        cancel: boolean;
        buy: boolean;
        order: boolean;
        receive: boolean;
    };
    today: string;
};

const keep = { preserveScroll: true };
const textarea = 'w-full border border-input bg-card px-2 py-1 text-sm';

/** One purchase, from the request to payment, all on one page. */
export default function ProcurementShow({
    request,
    lines,
    quotes,
    budget,
    budgetLine,
    budgetLines,
    minQuotes,
    orders,
    suppliers,
    can,
    today,
}: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const money = (minor: number) => formatMoney(minor, request.currency);
    const key = { purchaseRequest: request.id };
    const problem =
        errors.status ??
        errors.quote ??
        errors.order ??
        errors.receipt ??
        errors.invoice ??
        errors.close ??
        errors.budget_line_id ??
        errors.expense;
    const liveOrder = orders.find((order) => order.live);
    const lowest = Math.min(...quotes.map((quote) => quote.amountMinor));

    return (
        <>
            <Head title={request.number} />
            <PageHeader
                title={`${request.number} ${request.title}`}
                description={`${request.project}, requested by ${request.requester} on ${formatDate(request.createdOn)}`}
                actions={
                    <span className="flex items-center gap-3">
                        <StatusBadge tone={statusTone(request.status)}>
                            {request.statusLabel}
                        </StatusBadge>
                        <Link href={index()} className="text-sm underline">
                            All requests
                        </Link>
                    </span>
                }
            />

            {problem && (
                <p role="alert" className="mb-4 text-sm text-destructive">
                    {problem}
                </p>
            )}

            <Panel>
                <PanelHeader
                    title="The request"
                    description={`${request.category}${request.grant ? `, charged to ${request.grant}` : ''}${request.neededBy ? `, needed by ${formatDate(request.neededBy)}` : ''}`}
                />
                <PanelBody className="space-y-4">
                    <p className="text-sm whitespace-pre-wrap">
                        {request.justification}
                    </p>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Item</TableHead>
                                <TableHead className="text-right">
                                    Quantity
                                </TableHead>
                                <TableHead className="text-right">
                                    Unit price
                                </TableHead>
                                <TableHead className="text-right">
                                    Estimate
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {lines.map((line) => (
                                <TableRow key={line.id}>
                                    <TableCell>{line.description}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {line.quantity} {line.unit}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(line.unitPriceMinor)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(line.amountMinor)}
                                    </TableCell>
                                </TableRow>
                            ))}
                            <TableRow>
                                <TableCell
                                    colSpan={3}
                                    className="text-right font-medium"
                                >
                                    Estimated total
                                </TableCell>
                                <TableCell className="text-right font-medium tabular-nums">
                                    {money(request.estimateMinor)}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>

                    <p className="text-sm">
                        Budget line:{' '}
                        {budgetLine ? (
                            <span className="font-medium">
                                {budgetLine.label}
                                {!budgetLine.approved &&
                                    ' (no longer in the approved budget)'}
                            </span>
                        ) : (
                            <span className="text-muted-foreground">
                                not chosen yet
                            </span>
                        )}
                    </p>

                    {budget && (
                        <p
                            className={
                                budget.leftMinor === null ||
                                budget.leftMinor < request.estimateMinor ||
                                (budget.lineLeftMinor !== null &&
                                    budget.lineLeftMinor <
                                        request.estimateMinor)
                                    ? 'text-sm text-destructive'
                                    : 'text-sm text-muted-foreground'
                            }
                        >
                            {budget.leftMinor === null
                                ? 'The project has no approved budget yet: this cannot be submitted.'
                                : `${budget.lineLeftMinor === null ? '' : `${money(budget.lineLeftMinor)} left on the line, `}${money(budget.leftMinor)} left in ${request.category}. The approver checks this, and finance again when approving the order.`}
                        </p>
                    )}

                    {request.decider && (
                        <p className="text-sm text-muted-foreground">
                            Decided by {request.decider}
                            {request.decidedAt &&
                                `, ${formatDateTime(request.decidedAt)}`}
                            {request.decisionNote &&
                                `: ${request.decisionNote}`}
                        </p>
                    )}

                    {(can.submit || can.cancel) && (
                        <span className="flex flex-wrap items-end gap-2">
                            {can.submit &&
                                !budgetLine &&
                                budgetLines.length > 0 && (
                                    <Field
                                        id="budget_line_id"
                                        label="Charge it to"
                                        error={errors.budget_line_id}
                                    >
                                        <Select
                                            id="budget_line_id"
                                            name="budget_line_id"
                                            placeholder="Choose a budget line"
                                            options={budgetLines.map(
                                                (line) => ({
                                                    value: line.value,
                                                    label:
                                                        line.leftMinor === null
                                                            ? line.label
                                                            : `${line.label} (${money(line.leftMinor)} left)`,
                                                }),
                                            )}
                                        />
                                    </Field>
                                )}
                            {can.submit && (
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        const chosen = (
                                            document.getElementById(
                                                'budget_line_id',
                                            ) as HTMLSelectElement | null
                                        )?.value;
                                        router.post(
                                            submit.url(key),
                                            chosen
                                                ? { budget_line_id: chosen }
                                                : {},
                                            keep,
                                        );
                                    }}
                                >
                                    Submit for approval
                                </Button>
                            )}
                            {can.cancel && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(cancel.url(key), {}, keep)
                                    }
                                >
                                    Cancel request
                                </Button>
                            )}
                        </span>
                    )}

                    {can.decide && (
                        <Form
                            method="post"
                            action={approve.url(key)}
                            options={keep}
                            className="space-y-2"
                        >
                            <Field
                                id="decision-note"
                                label="Note (needed to reject)"
                                error={errors.note}
                            >
                                <textarea
                                    id="decision-note"
                                    name="note"
                                    rows={2}
                                    maxLength={2000}
                                    className={textarea}
                                />
                            </Field>
                            <span className="flex gap-2">
                                <Button type="submit" size="sm">
                                    Approve
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                        const note = (
                                            document.getElementById(
                                                'decision-note',
                                            ) as HTMLTextAreaElement
                                        ).value;
                                        router.post(
                                            reject.url(key),
                                            { note },
                                            keep,
                                        );
                                    }}
                                >
                                    Reject
                                </Button>
                            </span>
                        </Form>
                    )}
                </PanelBody>
            </Panel>

            {(can.buy || quotes.length > 0) && (
                <Panel className="mt-6">
                    <PanelHeader
                        title="Quotes"
                        description={`Fewer than ${minQuotes} quotes, or choosing one that isn't the lowest, needs a written reason.`}
                    />
                    <PanelBody className="space-y-4">
                        {quotes.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No quotes yet.
                            </p>
                        )}
                        <ul className="space-y-3 text-sm">
                            {quotes.map((quote) => (
                                <li
                                    key={quote.id}
                                    className="border-b pb-3 last:border-0"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="font-medium">
                                            {quote.supplier}
                                            {quote.reference &&
                                                ` (${quote.reference})`}
                                        </span>
                                        <span className="flex items-center gap-2">
                                            {quote.selected && (
                                                <StatusBadge tone="success">
                                                    Chosen
                                                </StatusBadge>
                                            )}
                                            {quote.expired && (
                                                <StatusBadge tone="warning">
                                                    Expired
                                                </StatusBadge>
                                            )}
                                            <span className="tabular-nums">
                                                {money(quote.amountMinor)}
                                            </span>
                                            {can.buy && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    aria-label={`Remove the quote from ${quote.supplier}`}
                                                    onClick={() =>
                                                        router.delete(
                                                            removeQuote.url({
                                                                ...key,
                                                                quote: quote.id,
                                                            }),
                                                            keep,
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            )}
                                        </span>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {quote.validUntil
                                            ? `Valid until ${formatDate(quote.validUntil)}`
                                            : 'No expiry given'}
                                        {quote.document && (
                                            <>
                                                {', '}
                                                <a
                                                    className="underline"
                                                    href={quoteDocument.url({
                                                        ...key,
                                                        quote: quote.id,
                                                    })}
                                                >
                                                    {quote.document}
                                                </a>
                                            </>
                                        )}
                                    </p>
                                    {can.buy &&
                                        !quote.selected &&
                                        !quote.expired && (
                                            <Form
                                                {...choose.form({
                                                    ...key,
                                                    quote: quote.id,
                                                })}
                                                options={keep}
                                                className="mt-2 flex flex-wrap items-end gap-2"
                                            >
                                                {(quotes.length < minQuotes ||
                                                    quote.amountMinor >
                                                        lowest) && (
                                                    <Field
                                                        id={`reason-${quote.id}`}
                                                        label="Why this one"
                                                        error={errors.reason}
                                                        className="min-w-64 flex-1"
                                                    >
                                                        <Input
                                                            id={`reason-${quote.id}`}
                                                            name="reason"
                                                            required
                                                            maxLength={2000}
                                                        />
                                                    </Field>
                                                )}
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    Choose this quote
                                                </Button>
                                            </Form>
                                        )}
                                </li>
                            ))}
                        </ul>

                        {request.selectionReason && (
                            <p className="text-sm text-muted-foreground">
                                Reason for the choice: {request.selectionReason}
                            </p>
                        )}

                        {can.order && (
                            <Form
                                {...raiseOrder.form(key)}
                                options={keep}
                                className="space-y-2"
                            >
                                <Field
                                    id="terms"
                                    label="Terms on the order"
                                    error={errors.terms}
                                >
                                    <textarea
                                        id="terms"
                                        name="terms"
                                        rows={2}
                                        maxLength={5000}
                                        className={textarea}
                                    />
                                </Field>
                                <Button type="submit" size="sm">
                                    Raise purchase order
                                </Button>
                            </Form>
                        )}

                        {can.buy && (
                            <Form
                                {...addQuote.form(key)}
                                options={keep}
                                resetOnSuccess
                                className="grid gap-3 border-t pt-4 sm:grid-cols-2"
                            >
                                <Field
                                    id="supplier_id"
                                    label="Supplier"
                                    error={errors.supplier_id}
                                >
                                    <Select
                                        id="supplier_id"
                                        name="supplier_id"
                                        placeholder="Choose a supplier"
                                        options={suppliers}
                                    />
                                </Field>
                                <Field
                                    id="supplier_name"
                                    label="Or a new supplier"
                                    error={errors.supplier_name}
                                >
                                    <Input
                                        id="supplier_name"
                                        name="supplier_name"
                                        maxLength={255}
                                    />
                                </Field>
                                <Field
                                    id="amount"
                                    label={`Total (${request.currency})`}
                                    error={errors.amount}
                                >
                                    <Input
                                        id="amount"
                                        name="amount"
                                        inputMode="decimal"
                                        required
                                        {...invalid('amount', errors.amount)}
                                    />
                                </Field>
                                <Field
                                    id="reference"
                                    label="Their reference"
                                    error={errors.reference}
                                >
                                    <Input
                                        id="reference"
                                        name="reference"
                                        maxLength={100}
                                    />
                                </Field>
                                <Field
                                    id="valid_until"
                                    label="Valid until"
                                    error={errors.valid_until}
                                >
                                    <Input
                                        id="valid_until"
                                        name="valid_until"
                                        type="date"
                                    />
                                </Field>
                                <Field
                                    id="document"
                                    label="Quote document"
                                    error={errors.document}
                                >
                                    <Input
                                        id="document"
                                        name="document"
                                        type="file"
                                    />
                                </Field>
                                <div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                    >
                                        Add quote
                                    </Button>
                                </div>
                            </Form>
                        )}
                    </PanelBody>
                </Panel>
            )}

            {orders.map((order) => (
                <Panel key={order.id} className="mt-6">
                    <PanelHeader
                        title={`${order.number} to ${order.supplier}`}
                        description={
                            order.closed
                                ? `Closed ${formatDate(order.closed.on)}${order.closed.reason ? `: ${order.closed.reason}` : ''}`
                                : undefined
                        }
                    />
                    <PanelBody className="space-y-4 text-sm">
                        <dl className="grid grid-cols-2 gap-x-6 gap-y-2 sm:grid-cols-4">
                            <div>
                                <dt className="text-muted-foreground">
                                    Ordered
                                </dt>
                                <dd className="tabular-nums">
                                    {money(order.amountMinor)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Committed
                                </dt>
                                <dd className="tabular-nums">
                                    {money(order.commitmentMinor)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Received
                                </dt>
                                <dd>
                                    {order.fullyReceived
                                        ? 'Everything'
                                        : order.receipts.length > 0
                                          ? 'In part'
                                          : 'Nothing yet'}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    className="text-muted-foreground"
                                    title="Supplier invoices recorded here as evidence. Finance recognizes what is owed and pays it."
                                >
                                    Supplier invoices
                                </dt>
                                <dd className="tabular-nums">
                                    {order.legacyInvoice
                                        ? `Reference ${order.invoiceReference}`
                                        : money(order.invoicedMinor)}
                                </dd>
                            </div>
                        </dl>

                        <p className="flex flex-wrap items-center gap-2">
                            Spending:
                            <StatusBadge tone={statusTone(order.spending)}>
                                {order.spendingLabel}
                            </StatusBadge>
                            {order.finance && (
                                <>
                                    Finance:
                                    <StatusBadge
                                        tone={statusTone(order.finance.status)}
                                    >
                                        {order.finance.label}
                                    </StatusBadge>
                                </>
                            )}
                            <span className="text-muted-foreground">
                                {nextStep(order)}
                            </span>
                        </p>
                        {order.terms && (
                            <p className="whitespace-pre-wrap">{order.terms}</p>
                        )}

                        {order.receipts.length > 0 && (
                            <ul className="space-y-1">
                                {order.receipts.map((receipt) => (
                                    <li key={receipt.id}>
                                        Received {formatDate(receipt.on)} by{' '}
                                        {receipt.by}
                                        {receipt.rejected &&
                                            ', with goods rejected'}
                                        {receipt.note && `: ${receipt.note}`}
                                        {receipt.evidence && (
                                            <>
                                                {' '}
                                                <a
                                                    className="underline"
                                                    href={receiptEvidence.url({
                                                        ...key,
                                                        order: order.id,
                                                        receipt: receipt.id,
                                                    })}
                                                >
                                                    {receipt.evidence}
                                                </a>
                                            </>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}

                        {order.live && (
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
                                            Rejected
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {lines.map((line) => (
                                        <TableRow key={line.id}>
                                            <TableCell>
                                                {line.description}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {line.quantity}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {line.received}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {line.rejected}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}

                        {can.receive &&
                            order.id === liveOrder?.id &&
                            !order.fullyReceived && (
                                <Form
                                    {...receive.form({
                                        ...key,
                                        order: order.id,
                                    })}
                                    options={keep}
                                    resetOnSuccess
                                    className="space-y-3 border-t pt-4"
                                >
                                    <h3 className="font-medium">
                                        Record a delivery
                                    </h3>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        {lines
                                            .filter(
                                                (line) => Number(line.left) > 0,
                                            )
                                            .map((line) => (
                                                <div
                                                    key={line.id}
                                                    className="grid grid-cols-2 gap-2 sm:col-span-2"
                                                >
                                                    <Field
                                                        id={`receive-${line.id}`}
                                                        label={`${line.description}: accepted (${line.left} left)`}
                                                    >
                                                        <Input
                                                            id={`receive-${line.id}`}
                                                            name={`quantities[${line.id}]`}
                                                            inputMode="decimal"
                                                        />
                                                    </Field>
                                                    <Field
                                                        id={`reject-${line.id}`}
                                                        label="Rejected"
                                                    >
                                                        <Input
                                                            id={`reject-${line.id}`}
                                                            name={`rejected[${line.id}]`}
                                                            inputMode="decimal"
                                                        />
                                                    </Field>
                                                </div>
                                            ))}
                                        <Field
                                            id="evidence"
                                            label="Delivery note or photo"
                                            error={errors.evidence}
                                        >
                                            <Input
                                                id="evidence"
                                                name="evidence"
                                                type="file"
                                            />
                                        </Field>
                                        <Field
                                            id="received_on"
                                            label="Received on"
                                            error={errors.received_on}
                                        >
                                            <Input
                                                id="received_on"
                                                name="received_on"
                                                type="date"
                                                max={today}
                                                defaultValue={today}
                                                required
                                            />
                                        </Field>
                                        <Field
                                            id="receipt-note"
                                            label="Note"
                                            error={errors.note}
                                        >
                                            <Input
                                                id="receipt-note"
                                                name="note"
                                                maxLength={2000}
                                            />
                                        </Field>
                                    </div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                    >
                                        Record delivery
                                    </Button>
                                </Form>
                            )}

                        {order.invoices.length > 0 && (
                            <div className="space-y-2 border-t pt-4">
                                <h3 className="font-medium">
                                    Supplier invoices
                                </h3>
                                <ul className="space-y-2">
                                    {order.invoices.map((invoice) => (
                                        <li
                                            key={invoice.id}
                                            className={
                                                invoice.voided
                                                    ? 'text-muted-foreground'
                                                    : undefined
                                            }
                                        >
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span
                                                    className={
                                                        invoice.voided
                                                            ? 'line-through'
                                                            : 'font-medium'
                                                    }
                                                >
                                                    {invoice.number}
                                                </span>
                                                <span className="tabular-nums">
                                                    {formatMoney(
                                                        invoice.amountMinor,
                                                        invoice.currency,
                                                    )}
                                                </span>
                                                <span>
                                                    of {formatDate(invoice.on)}
                                                    {invoice.dueOn &&
                                                        `, due ${formatDate(invoice.dueOn)}`}
                                                    , recorded by {invoice.by}
                                                </span>
                                                {invoice.document && (
                                                    <a
                                                        className="underline"
                                                        href={invoiceDocument.url(
                                                            {
                                                                ...key,
                                                                order: order.id,
                                                                invoice:
                                                                    invoice.id,
                                                            },
                                                        )}
                                                    >
                                                        {invoice.document}
                                                    </a>
                                                )}
                                                {invoice.voided && (
                                                    <StatusBadge tone="neutral">
                                                        Voided
                                                    </StatusBadge>
                                                )}
                                            </span>
                                            {invoice.voided?.reason && (
                                                <p className="text-xs">
                                                    Voided
                                                    {invoice.voided.on &&
                                                        ` ${formatDate(invoice.voided.on)}`}
                                                    : {invoice.voided.reason}
                                                </p>
                                            )}
                                            {!invoice.voided &&
                                                order.can.invoice && (
                                                    <Form
                                                        {...voidInvoice.form({
                                                            ...key,
                                                            order: order.id,
                                                            invoice: invoice.id,
                                                        })}
                                                        options={keep}
                                                        className="mt-1 flex flex-wrap items-end gap-2"
                                                    >
                                                        <Field
                                                            id={`void-${invoice.id}`}
                                                            label="Why void it"
                                                            error={
                                                                errors.reason
                                                            }
                                                            className="min-w-64 flex-1"
                                                        >
                                                            <Input
                                                                id={`void-${invoice.id}`}
                                                                name="reason"
                                                                required
                                                                maxLength={2000}
                                                            />
                                                        </Field>
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="outline"
                                                        >
                                                            Void invoice
                                                        </Button>
                                                    </Form>
                                                )}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {order.can.invoice && (
                            <Form
                                {...recordInvoice.form({
                                    ...key,
                                    order: order.id,
                                })}
                                options={keep}
                                resetOnSuccess
                                className="space-y-3 border-t pt-4"
                            >
                                <h3 className="font-medium">
                                    Record a supplier invoice
                                </h3>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field
                                        id={`number-${order.id}`}
                                        label="Invoice number"
                                        error={errors.number}
                                    >
                                        <Input
                                            id={`number-${order.id}`}
                                            name="number"
                                            maxLength={100}
                                            required
                                        />
                                    </Field>
                                    <Field
                                        id={`invoice-amount-${order.id}`}
                                        label={`Amount (${request.currency}), ${money(order.commitmentMinor - order.invoicedMinor)} not invoiced yet`}
                                        error={errors.amount}
                                    >
                                        <Input
                                            id={`invoice-amount-${order.id}`}
                                            name="amount"
                                            inputMode="decimal"
                                            placeholder={(
                                                (order.commitmentMinor -
                                                    order.invoicedMinor) /
                                                100
                                            ).toFixed(2)}
                                            {...invalid(
                                                `invoice-amount-${order.id}`,
                                                errors.amount,
                                            )}
                                        />
                                    </Field>
                                    <Field
                                        id={`invoice-on-${order.id}`}
                                        label="Invoice date"
                                        error={errors.invoice_on}
                                    >
                                        <Input
                                            id={`invoice-on-${order.id}`}
                                            name="invoice_on"
                                            type="date"
                                            max={today}
                                            defaultValue={today}
                                            required
                                        />
                                    </Field>
                                    <Field
                                        id={`due-on-${order.id}`}
                                        label="Due"
                                        error={errors.due_on}
                                    >
                                        <Input
                                            id={`due-on-${order.id}`}
                                            name="due_on"
                                            type="date"
                                        />
                                    </Field>
                                    <Field
                                        id={`invoice-document-${order.id}`}
                                        label="Invoice document"
                                        error={errors.document}
                                    >
                                        <Input
                                            id={`invoice-document-${order.id}`}
                                            name="document"
                                            type="file"
                                        />
                                    </Field>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    In {request.currency}, the project's
                                    currency. Leave the amount empty for the
                                    whole of what is not invoiced yet.
                                </p>
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                >
                                    Record invoice
                                </Button>
                            </Form>
                        )}

                        {order.can.close && (
                            <Form
                                {...closeOrder.form({
                                    ...key,
                                    order: order.id,
                                })}
                                options={keep}
                                className="space-y-2 border-t pt-4"
                            >
                                <h3 className="font-medium">
                                    Close the order short
                                </h3>
                                <p className="text-muted-foreground">
                                    Nothing more will arrive. The commitment
                                    comes down to what is invoiced (
                                    {money(order.invoicedMinor)}), and the rest
                                    goes back to the budget line.
                                </p>
                                <Field
                                    id={`close-${order.id}`}
                                    label="Why"
                                    error={errors.reason}
                                >
                                    <Input
                                        id={`close-${order.id}`}
                                        name="reason"
                                        required
                                        maxLength={2000}
                                    />
                                </Field>
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                >
                                    Close order
                                </Button>
                            </Form>
                        )}
                    </PanelBody>
                </Panel>
            ))}
        </>
    );
}

/** What has to happen next for this order to be paid, in plain words. */
function nextStep(order: Order): string {
    if (!order.live) {
        return "This order no longer stands. Choose a quote and raise another if it's still needed.";
    }
    if (order.spending === 'paid') {
        return 'Paid.';
    }
    if (order.spending === 'requested') {
        return 'Finance approves it under Approvals, against the budget line and the grant.';
    }
    if (order.finance) {
        return 'With Finance to pay.';
    }
    if (order.payable) {
        return 'Ready for finance to pay.';
    }
    if (!order.fullyReceived && order.closed === null) {
        return 'Waiting for the goods.';
    }

    return 'Waiting for the supplier invoices to come to the commitment.';
}
