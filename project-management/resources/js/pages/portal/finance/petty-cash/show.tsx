import { Form, Head, Link } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
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
import { NoteAction, StepAction } from '@/components/note-action';
import { Status } from '@/components/status';
import { formatMoney } from '@/lib/money';
import { formatDate, formatDateTime, todayIso } from '@/lib/status';
import { close, custodian, index, moveToFinance } from '@/routes/petty-cash';
import { store as countStore } from '@/routes/petty-cash/counts';
import {
    receipt,
    reverse,
    store as payStore,
} from '@/routes/petty-cash/payments';
import {
    approve,
    reject,
    store as topUpStore,
} from '@/routes/petty-cash/top-ups';
import type { Option } from '@/types';

type Payment = {
    id: string;
    paidOn: string;
    amountMinor: number;
    description: string;
    payee: string | null;
    chargedTo: string | null;
    receiptName: string;
    recordedBy: string;
    topUpStatus: string | null;
    topUpId: string | null;
    voidReason: string | null;
    void: boolean;
    canVoid: boolean;
};

type TopUp = {
    id: string;
    amountMinor: number;
    status: string;
    requestedBy: string;
    requestedAt: string;
    decidedBy: string | null;
    decidedAt: string | null;
    note: string | null;
    canDecide: boolean;
};

type Props = {
    fund: {
        id: string;
        name: string;
        custodianId: string;
        custodian: string;
        floatMinor: number;
        balanceMinor: number;
        outstandingMinor: number;
        differenceMinor: number;
        currency: string;
        active: boolean;
        /** When it was moved into a Finance fund. */
        movedAt: string | null;
    };
    /** Set when Finance keeps the organization's petty cash. */
    financeUrl: string | null;
    payments: Payment[];
    topUps: TopUp[];
    counts: {
        id: string;
        countedOn: string;
        countedMinor: number;
        bookMinor: number;
        differenceMinor: number;
        note: string | null;
        countedBy: string;
    }[];
    projects: Option[];
    categories: Option[];
    custodians: Option[];
    limitMinor: number | null;
    can: {
        pay: boolean;
        requestTopUp: boolean;
        manage: boolean;
        count: boolean;
        moveToFinance: boolean;
    };
};

/**
 * Recording a payment, with its receipt and what it was spent on. Charged to
 * a project's budget when one is chosen.
 */
function PayForm({
    fund,
    projects,
    categories,
    limitMinor,
}: Pick<Props, 'fund' | 'projects' | 'categories' | 'limitMinor'>) {
    const [project, setProject] = useState('');

    return (
        <Form
            {...payStore.form(fund.id)}
            options={{ preserveScroll: true }}
            resetOnSuccess
            onSuccess={() => setProject('')}
            className="grid gap-3 sm:grid-cols-3"
        >
            {({ errors, processing }) => (
                <>
                    <Field id="paid_on" label="Date" error={errors.paid_on}>
                        <Input
                            id="paid_on"
                            name="paid_on"
                            type="date"
                            required
                            defaultValue={todayIso()}
                            {...invalid('paid_on', errors.paid_on)}
                        />
                    </Field>
                    <Field
                        id="amount"
                        label={`Amount (${fund.currency})`}
                        error={errors.amount}
                        hint={
                            limitMinor === null
                                ? undefined
                                : `Up to ${formatMoney(limitMinor, fund.currency)}`
                        }
                    >
                        <Input
                            id="amount"
                            name="amount"
                            required
                            inputMode="decimal"
                            {...invalid('amount', errors.amount)}
                        />
                    </Field>
                    <Field id="payee" label="Paid to" error={errors.payee}>
                        <Input
                            id="payee"
                            name="payee"
                            maxLength={255}
                            {...invalid('payee', errors.payee)}
                        />
                    </Field>
                    <Field
                        id="description"
                        label="For"
                        error={errors.description}
                        className="sm:col-span-3"
                    >
                        <Input
                            id="description"
                            name="description"
                            required
                            maxLength={255}
                            {...invalid('description', errors.description)}
                        />
                    </Field>
                    <Field
                        id="project_id"
                        label="Project"
                        error={errors.project_id}
                    >
                        <Select
                            id="project_id"
                            name="project_id"
                            value={project}
                            onChange={(event) => setProject(event.target.value)}
                            placeholder="None"
                            options={projects}
                        />
                    </Field>
                    <Field
                        id="category"
                        label={project === '' ? 'Spent on' : 'Budget category'}
                        error={errors.category}
                    >
                        <Select
                            id="category"
                            name="category"
                            required
                            options={categories}
                        />
                    </Field>
                    <Field id="receipt" label="Receipt" error={errors.receipt}>
                        <Input
                            id="receipt"
                            name="receipt"
                            type="file"
                            required
                            accept=".pdf,.jpg,.jpeg,.png,.webp"
                            {...invalid('receipt', errors.receipt)}
                        />
                    </Field>
                    {errors.fund && (
                        <p
                            role="alert"
                            className="text-xs text-destructive sm:col-span-3"
                        >
                            {errors.fund}
                        </p>
                    )}
                    <div className="sm:col-span-3">
                        <Button type="submit" disabled={processing}>
                            Record payment
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * Approving a top-up: the cash comes from the bank, on the day it is drawn.
 */
function ApproveTopUp({
    fundId,
    topUpId,
}: {
    fundId: string;
    topUpId: string;
}) {
    const [open, setOpen] = useState(false);

    if (!open) {
        return (
            <Button size="sm" onClick={() => setOpen(true)}>
                Approve
            </Button>
        );
    }

    return (
        <Form
            {...approve.form({ fund: fundId, topUp: topUpId })}
            options={{ preserveScroll: true }}
            onSuccess={() => setOpen(false)}
            className="grid w-full gap-3 sm:grid-cols-2"
        >
            {({ errors, processing }) => (
                <>
                    <Field id="top_up_on" label="Date" error={errors.on}>
                        <Input
                            id="top_up_on"
                            name="on"
                            type="date"
                            required
                            defaultValue={todayIso()}
                        />
                    </Field>
                    <Field
                        id="top_up_note"
                        label="A note for the record (optional)"
                        error={errors.note}
                        className="sm:col-span-2"
                    >
                        <Input id="top_up_note" name="note" maxLength={2000} />
                    </Field>
                    {errors.top_up && (
                        <p
                            role="alert"
                            className="text-xs text-destructive sm:col-span-2"
                        >
                            {errors.top_up}
                        </p>
                    )}
                    <div className="flex gap-2 sm:col-span-2">
                        <Button type="submit" size="sm" disabled={processing}>
                            I checked the receipts: approve
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => setOpen(false)}
                        >
                            Close
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * One box: what it holds, its payments, top-ups and counts.
 */
export default function PettyCashBox({
    fund,
    payments,
    topUps,
    counts,
    projects,
    categories,
    custodians,
    limitMinor,
    can,
    financeUrl,
}: Props) {
    const money = (minor: number) => formatMoney(minor, fund.currency);
    const moved = fund.movedAt !== null;

    return (
        <>
            <Head title={fund.name} />
            <PageHeader
                title={fund.name}
                description={
                    <>
                        <Link href={index()} className="hover:underline">
                            Petty cash
                        </Link>
                        {` · ${fund.custodian}`}
                        {!fund.active &&
                            (moved ? ' · in Finance' : ' · closed')}
                    </>
                }
            />

            <div className="grid gap-4">
                {(moved || financeUrl !== null) && (
                    <div
                        role="status"
                        className="grid gap-3 border border-input bg-card p-3 text-sm"
                    >
                        <p>
                            {moved
                                ? `Moved into Finance on ${formatDateTime(fund.movedAt ?? '')}. It is kept there now; its payments and receipts stay here to look back on.`
                                : 'Petty cash is kept in Finance. Move this box there to keep using it: Finance takes it at the same set amount, holding what it holds now, and its managers name the custodian there after counting the cash.'}{' '}
                            {financeUrl !== null && (
                                <a href={financeUrl} className="underline">
                                    Open petty cash in Finance
                                </a>
                            )}
                        </p>
                        {can.moveToFinance && (
                            <Form
                                {...moveToFinance.form(fund.id)}
                                options={{ preserveScroll: true }}
                            >
                                {({ errors, processing }) => (
                                    <>
                                        {errors.fund && (
                                            <p
                                                role="alert"
                                                className="mb-2 text-xs text-destructive"
                                            >
                                                {errors.fund}
                                            </p>
                                        )}
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Move to Finance
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </div>
                )}
                <Panel>
                    <PanelBody>
                        <dl className="flex flex-wrap gap-x-8 gap-y-3 text-sm">
                            <div>
                                <dt className="text-muted-foreground">Holds</dt>
                                <dd className="font-medium tabular-nums">
                                    {money(fund.balanceMinor)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Set amount
                                </dt>
                                <dd className="font-medium tabular-nums">
                                    {money(fund.floatMinor)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Receipts not yet topped up
                                </dt>
                                <dd className="font-medium tabular-nums">
                                    {money(fund.outstandingMinor)}
                                </dd>
                            </div>
                            {fund.differenceMinor !== 0 && (
                                <div>
                                    <dt className="text-muted-foreground">
                                        Doesn't add up by
                                    </dt>
                                    <dd className="font-medium text-destructive tabular-nums">
                                        {money(fund.differenceMinor)}
                                    </dd>
                                </div>
                            )}
                        </dl>
                        {can.requestTopUp && (
                            <div className="mt-3">
                                <StepAction
                                    form={topUpStore.form(fund.id)}
                                    label="Ask for a top-up"
                                />
                            </div>
                        )}
                    </PanelBody>
                </Panel>

                {topUps.length > 0 && (
                    <Panel>
                        <PanelHeader title="Top-ups" />
                        <ul className="divide-y">
                            {topUps.map((topUp) => (
                                <li key={topUp.id} className="grid gap-2 p-4">
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="font-medium tabular-nums">
                                            {money(topUp.amountMinor)}
                                        </span>
                                        <Status status={topUp.status} />
                                        <span className="text-xs text-muted-foreground">
                                            Asked by {topUp.requestedBy},{' '}
                                            {formatDateTime(topUp.requestedAt)}
                                        </span>
                                    </div>
                                    {topUp.decidedAt && (
                                        <p className="text-xs text-muted-foreground">
                                            Decided by {topUp.decidedBy},{' '}
                                            {formatDateTime(topUp.decidedAt)}
                                            {topUp.note && `: ${topUp.note}`}
                                        </p>
                                    )}
                                    {topUp.canDecide && (
                                        <div className="flex flex-wrap items-start gap-2">
                                            <ApproveTopUp
                                                fundId={fund.id}
                                                topUpId={topUp.id}
                                            />
                                            <NoteAction
                                                form={reject.form({
                                                    fund: fund.id,
                                                    topUp: topUp.id,
                                                })}
                                                label="Reject"
                                                prompt="What is wrong with the receipts"
                                                required
                                            />
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}

                {can.pay && (
                    <Panel className="max-w-3xl">
                        <PanelHeader title="Record a payment" />
                        <PanelBody>
                            <PayForm
                                fund={fund}
                                projects={projects}
                                categories={categories}
                                limitMinor={limitMinor}
                            />
                        </PanelBody>
                    </Panel>
                )}

                <Panel>
                    <PanelHeader title="Payments" />
                    {payments.length === 0 ? (
                        <EmptyState
                            icon={Receipt}
                            title="No payments from this box yet"
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>For</TableHead>
                                    <TableHead>Charged to</TableHead>
                                    <TableHead>Receipt</TableHead>
                                    <TableHead>Top-up</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {payments.map((payment) => (
                                    <TableRow key={payment.id}>
                                        <TableCell className="text-muted-foreground">
                                            {formatDate(payment.paidOn)}
                                        </TableCell>
                                        <TableCell>
                                            <span
                                                className={
                                                    payment.void
                                                        ? 'line-through'
                                                        : undefined
                                                }
                                            >
                                                {payment.description}
                                            </span>
                                            {payment.payee && (
                                                <p className="text-xs text-muted-foreground">
                                                    {payment.payee}
                                                </p>
                                            )}
                                            {payment.void && (
                                                <p className="text-xs text-destructive">
                                                    Void: {payment.voidReason}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {payment.chargedTo}
                                        </TableCell>
                                        <TableCell>
                                            <a
                                                href={
                                                    receipt({
                                                        fund: fund.id,
                                                        payment: payment.id,
                                                    }).url
                                                }
                                                className="text-sm underline"
                                            >
                                                {payment.receiptName}
                                            </a>
                                        </TableCell>
                                        <TableCell>
                                            {payment.topUpStatus && (
                                                <Status
                                                    status={payment.topUpStatus}
                                                />
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(payment.amountMinor)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {payment.canVoid && (
                                                <NoteAction
                                                    form={reverse.form({
                                                        fund: fund.id,
                                                        payment: payment.id,
                                                    })}
                                                    label="Void"
                                                    prompt="Why it is voided"
                                                    required
                                                />
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>

                <Panel>
                    <PanelHeader title="Counts" />
                    {counts.length > 0 && (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>Counted by</TableHead>
                                    <TableHead className="text-right">
                                        Counted
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Expected
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Difference
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {counts.map((count) => (
                                    <TableRow key={count.id}>
                                        <TableCell className="text-muted-foreground">
                                            {formatDate(count.countedOn)}
                                        </TableCell>
                                        <TableCell>
                                            {count.countedBy}
                                            {count.note && (
                                                <p className="text-xs text-muted-foreground">
                                                    {count.note}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(count.countedMinor)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(count.bookMinor)}
                                        </TableCell>
                                        <TableCell
                                            className={
                                                count.differenceMinor === 0
                                                    ? 'text-right tabular-nums'
                                                    : 'text-right text-destructive tabular-nums'
                                            }
                                        >
                                            {money(count.differenceMinor)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    {can.count && (
                        <PanelBody>
                            <Form
                                {...countStore.form(fund.id)}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="grid max-w-3xl gap-3 sm:grid-cols-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="counted_on"
                                            label="Date"
                                            error={errors.counted_on}
                                        >
                                            <Input
                                                id="counted_on"
                                                name="counted_on"
                                                type="date"
                                                required
                                                defaultValue={todayIso()}
                                            />
                                        </Field>
                                        <Field
                                            id="counted"
                                            label={`Cash counted (${fund.currency})`}
                                            error={errors.counted}
                                        >
                                            <Input
                                                id="counted"
                                                name="counted"
                                                required
                                                inputMode="decimal"
                                                {...invalid(
                                                    'counted',
                                                    errors.counted,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="note"
                                            label="Note (needed if it differs)"
                                            error={errors.note}
                                        >
                                            <Input
                                                id="note"
                                                name="note"
                                                maxLength={2000}
                                                {...invalid(
                                                    'note',
                                                    errors.note,
                                                )}
                                            />
                                        </Field>
                                        <div className="sm:col-span-3">
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Record count
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    )}
                </Panel>

                {can.manage && (
                    <Panel className="max-w-3xl">
                        <PanelHeader title="Manage this box" />
                        <PanelBody className="grid gap-6">
                            <Form
                                {...custodian.form(fund.id)}
                                options={{ preserveScroll: true }}
                                className="flex items-end gap-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="custodian_id"
                                            label="Custodian"
                                            error={errors.custodian_id}
                                            className="flex-1"
                                        >
                                            <Select
                                                id="custodian_id"
                                                name="custodian_id"
                                                required
                                                defaultValue={fund.custodianId}
                                                options={custodians}
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Change
                                        </Button>
                                    </>
                                )}
                            </Form>
                            <Form
                                {...close.form(fund.id)}
                                options={{ preserveScroll: true }}
                                className="grid gap-3 sm:grid-cols-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="close_on"
                                            label="Date"
                                            error={errors.on}
                                        >
                                            <Input
                                                id="close_on"
                                                name="on"
                                                type="date"
                                                required
                                                defaultValue={todayIso()}
                                            />
                                        </Field>
                                        {errors.close && (
                                            <p
                                                role="alert"
                                                className="text-xs text-destructive sm:col-span-3"
                                            >
                                                {errors.close}
                                            </p>
                                        )}
                                        <div className="sm:col-span-3">
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Close this box
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    </Panel>
                )}
            </div>
        </>
    );
}
