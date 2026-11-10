import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
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
import { approve, cancel, disburse, index, reject } from '@/routes/advances';
import {
    destroy as removeLine,
    receipt,
    store as addLine,
} from '@/routes/advances/lines';
import {
    approve as approveRetirement,
    returnMethod as returnRetirement,
    store as submitRetirement,
} from '@/routes/advances/retirements';
import { store as recordReturn } from '@/routes/advances/returns';
import {
    approve as approveWriteOff,
    reject as rejectWriteOff,
} from '@/routes/advances/settlements';
import { store as requestWriteOff } from '@/routes/advances/write-offs';
import type { Option } from '@/types';

type Line = {
    id: string;
    spentOn: string;
    description: string;
    payee: string | null;
    chargedTo: string | null;
    amountMinor: number;
    receiptName: string;
    canRemove: boolean;
};

type Props = {
    advance: {
        id: string;
        reference: string;
        holder: string;
        purpose: string;
        status: string;
        amountMinor: number;
        outstandingMinor: number;
        currency: string;
        requestedAt: string;
        decidedBy: string | null;
        decidedAt: string | null;
        decisionNote: string | null;
        disbursedBy: string | null;
        disbursedOn: string | null;
        source: string | null;
        paymentReference: string | null;
        dueOn: string | null;
        daysOverdue: number;
    };
    retirements: {
        id: string;
        status: string;
        returnedReason: string | null;
        decidedBy: string | null;
        totalMinor: number;
        canDecide: boolean;
        lines: Line[];
    }[];
    settlements: {
        id: string;
        type: string;
        status: string;
        amountMinor: number;
        on: string;
        reason: string | null;
        recordedBy: string;
        decidedBy: string | null;
        decisionNote: string | null;
        account: string | null;
        canDecide: boolean;
    }[];
    projects: Option[];
    categories: Option[];
    methods: Option[];
    can: {
        decide: boolean;
        cancel: boolean;
        disburse: boolean;
        addReceipts: boolean;
        settle: boolean;
    };
};

/**
 * A receipt accounting for part of the advance.
 */
function ReceiptForm({
    advanceId,
    currency,
    projects,
    categories,
}: {
    advanceId: string;
    currency: string;
    projects: Option[];
    categories: Option[];
}) {
    const [project, setProject] = useState('');

    return (
        <Form
            {...addLine.form(advanceId)}
            options={{ preserveScroll: true }}
            resetOnSuccess
            onSuccess={() => setProject('')}
            className="grid gap-3 sm:grid-cols-3"
        >
            {({ errors, processing }) => (
                <>
                    <Field id="spent_on" label="Date" error={errors.spent_on}>
                        <Input
                            id="spent_on"
                            name="spent_on"
                            type="date"
                            required
                            defaultValue={todayIso()}
                            {...invalid('spent_on', errors.spent_on)}
                        />
                    </Field>
                    <Field
                        id="amount"
                        label={`Amount (${currency})`}
                        error={errors.amount}
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
                        <Input id="payee" name="payee" maxLength={255} />
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
                    {errors.advance && (
                        <p
                            role="alert"
                            className="text-xs text-destructive sm:col-span-3"
                        >
                            {errors.advance}
                        </p>
                    )}
                    <div className="sm:col-span-3">
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
                            Add receipt
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * Paying the advance out, or taking cash back: how the money moved and when.
 */
function BankForm({
    form,
    methods,
    label,
    bankLabel,
    id,
    amount,
    currency,
    referenceName,
    referenceLabel,
    error,
}: {
    form: { action: string; method: 'post' };
    methods: Option[];
    label: string;
    bankLabel: string;
    id: string;
    amount?: boolean;
    currency: string;
    referenceName: string;
    referenceLabel: string;
    error?: string;
}) {
    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="grid gap-3 sm:grid-cols-2"
        >
            {({ errors, processing }) => (
                <>
                    {amount && (
                        <Field
                            id={`${id}-amount`}
                            label={`Amount (${currency})`}
                            error={errors.amount}
                        >
                            <Input
                                id={`${id}-amount`}
                                name="amount"
                                required
                                inputMode="decimal"
                                {...invalid(`${id}-amount`, errors.amount)}
                            />
                        </Field>
                    )}
                    <Field
                        id={`${id}-method`}
                        label={bankLabel}
                        error={errors.payment_method}
                    >
                        <Select
                            id={`${id}-method`}
                            name="payment_method"
                            required
                            options={methods}
                        />
                    </Field>
                    <Field id={`${id}-on`} label="Date" error={errors.on}>
                        <Input
                            id={`${id}-on`}
                            name="on"
                            type="date"
                            required
                            defaultValue={todayIso()}
                        />
                    </Field>
                    <Field
                        id={`${id}-ref`}
                        label={referenceLabel}
                        error={errors[referenceName as keyof typeof errors]}
                    >
                        <Input
                            id={`${id}-ref`}
                            name={referenceName}
                            maxLength={255}
                        />
                    </Field>
                    {(errors.advance ?? error) && (
                        <p
                            role="alert"
                            className="text-xs text-destructive sm:col-span-2"
                        >
                            {errors.advance ?? error}
                        </p>
                    )}
                    <div className="sm:col-span-2">
                        <Button type="submit" disabled={processing}>
                            {label}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * One advance: where it stands, its receipts, and the steps open to you.
 */
export default function StaffAdvancePage({
    advance,
    retirements,
    settlements,
    projects,
    categories,
    methods,
    can,
}: Props) {
    const money = (minor: number) => formatMoney(minor, advance.currency);
    const draft = retirements.find((item) => item.status === 'draft');

    return (
        <>
            <Head title={advance.reference} />
            <PageHeader
                title={`${advance.reference} · ${advance.purpose}`}
                description={
                    <>
                        <Link href={index()} className="hover:underline">
                            Staff advances
                        </Link>
                        {` · ${advance.holder}`}
                    </>
                }
            />

            <div className="grid gap-4">
                <Panel>
                    <PanelBody>
                        <dl className="flex flex-wrap gap-x-8 gap-y-3 text-sm">
                            <div>
                                <dt className="text-muted-foreground">
                                    Status
                                </dt>
                                <dd>
                                    <Status status={advance.status} />
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Amount
                                </dt>
                                <dd className="font-medium tabular-nums">
                                    {money(advance.amountMinor)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Still owed
                                </dt>
                                <dd className="font-medium tabular-nums">
                                    {money(advance.outstandingMinor)}
                                </dd>
                            </div>
                            {advance.dueOn && (
                                <div>
                                    <dt className="text-muted-foreground">
                                        Account for it by
                                    </dt>
                                    <dd className="font-medium">
                                        {formatDate(advance.dueOn)}
                                        {advance.daysOverdue > 0 && (
                                            <span className="ml-2 text-destructive">
                                                {advance.daysOverdue} days late
                                            </span>
                                        )}
                                    </dd>
                                </div>
                            )}
                        </dl>
                        <div className="mt-3 grid gap-1 text-xs text-muted-foreground">
                            <p>Asked {formatDateTime(advance.requestedAt)}</p>
                            {advance.decidedAt && (
                                <p>
                                    Decided by {advance.decidedBy},{' '}
                                    {formatDateTime(advance.decidedAt)}
                                    {advance.decisionNote &&
                                        `: ${advance.decisionNote}`}
                                </p>
                            )}
                            {advance.disbursedOn && (
                                <p>
                                    Paid out by {advance.disbursedBy},{' '}
                                    {formatDate(advance.disbursedOn)}, by{' '}
                                    {advance.source?.toLowerCase()}
                                    {advance.paymentReference &&
                                        ` (${advance.paymentReference})`}
                                </p>
                            )}
                        </div>
                        {(can.decide || can.cancel) && (
                            <div className="mt-3 flex flex-wrap items-start gap-2">
                                {can.decide && (
                                    <>
                                        <NoteAction
                                            form={approve.form(advance.id)}
                                            label="Approve"
                                            prompt="A note for the record (optional)"
                                            variant="default"
                                        />
                                        <NoteAction
                                            form={reject.form(advance.id)}
                                            label="Reject"
                                            prompt="Why it is rejected"
                                            required
                                        />
                                    </>
                                )}
                                {can.cancel && (
                                    <StepAction
                                        form={cancel.form(advance.id)}
                                        label="Cancel it"
                                        variant="ghost"
                                    />
                                )}
                            </div>
                        )}
                    </PanelBody>
                </Panel>

                {can.disburse && (
                    <Panel className="max-w-3xl">
                        <PanelHeader title="Pay it out" />
                        <PanelBody>
                            <BankForm
                                id="disburse"
                                form={disburse.form(advance.id)}
                                methods={methods}
                                label={`Pay out ${money(advance.amountMinor)}`}
                                bankLabel="Paid by"
                                currency={advance.currency}
                                referenceName="reference"
                                referenceLabel="Payment reference (optional)"
                            />
                        </PanelBody>
                    </Panel>
                )}

                {retirements.map((retirement) => (
                    <Panel key={retirement.id}>
                        <PanelHeader
                            title={`Receipts: ${money(retirement.totalMinor)}`}
                            actions={<Status status={retirement.status} />}
                        />
                        {retirement.returnedReason && (
                            <PanelBody>
                                <p className="text-sm font-medium text-destructive">
                                    Returned: {retirement.returnedReason}
                                </p>
                            </PanelBody>
                        )}
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>For</TableHead>
                                    <TableHead>Charged to</TableHead>
                                    <TableHead>Receipt</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {retirement.lines.map((line) => {
                                    const route = {
                                        advance: advance.id,
                                        line: line.id,
                                    };

                                    return (
                                        <TableRow key={line.id}>
                                            <TableCell className="text-muted-foreground">
                                                {formatDate(line.spentOn)}
                                            </TableCell>
                                            <TableCell>
                                                {line.description}
                                                {line.payee && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {line.payee}
                                                    </p>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {line.chargedTo}
                                            </TableCell>
                                            <TableCell>
                                                <a
                                                    href={receipt(route).url}
                                                    className="text-sm underline"
                                                >
                                                    {line.receiptName}
                                                </a>
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {money(line.amountMinor)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {line.canRemove && (
                                                    <Form
                                                        {...removeLine.form(
                                                            route,
                                                        )}
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                    >
                                                        <Button
                                                            type="submit"
                                                            variant="ghost"
                                                            size="sm"
                                                        >
                                                            Remove
                                                        </Button>
                                                    </Form>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                        {retirement.canDecide && (
                            <PanelBody>
                                <div className="flex flex-wrap items-start gap-2">
                                    <StepAction
                                        form={approveRetirement.form({
                                            advance: advance.id,
                                            retirement: retirement.id,
                                        })}
                                        label="I checked them: approve"
                                        variant="default"
                                    />
                                    <NoteAction
                                        form={returnRetirement.form({
                                            advance: advance.id,
                                            retirement: retirement.id,
                                        })}
                                        label="Return"
                                        prompt="What needs correcting"
                                        required
                                    />
                                </div>
                            </PanelBody>
                        )}
                    </Panel>
                ))}

                {can.addReceipts && (
                    <Panel className="max-w-3xl">
                        <PanelHeader title="Account for this advance" />
                        <PanelBody className="grid gap-4">
                            <ReceiptForm
                                advanceId={advance.id}
                                currency={advance.currency}
                                projects={projects}
                                categories={categories}
                            />
                            {draft && draft.lines.length > 0 && (
                                <StepAction
                                    form={submitRetirement.form(advance.id)}
                                    label={`Send ${money(draft.totalMinor)} of receipts for a decision`}
                                    variant="default"
                                />
                            )}
                        </PanelBody>
                    </Panel>
                )}

                {settlements.length > 0 && (
                    <Panel>
                        <PanelHeader title="Cash returned and written off" />
                        <ul className="divide-y">
                            {settlements.map((settlement) => (
                                <li
                                    key={settlement.id}
                                    className="grid gap-2 p-4 text-sm"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium tabular-nums">
                                            {money(settlement.amountMinor)}
                                        </span>
                                        <span>
                                            {settlement.type === 'return'
                                                ? 'Cash returned'
                                                : 'Write-off'}
                                        </span>
                                        <Status status={settlement.status} />
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {formatDate(settlement.on)}, recorded by{' '}
                                        {settlement.recordedBy}
                                        {settlement.account &&
                                            `, by ${settlement.account.toLowerCase()}`}
                                        {settlement.reason &&
                                            `: ${settlement.reason}`}
                                    </p>
                                    {settlement.decidedBy && (
                                        <p className="text-xs text-muted-foreground">
                                            Decided by {settlement.decidedBy}
                                            {settlement.decisionNote &&
                                                `: ${settlement.decisionNote}`}
                                        </p>
                                    )}
                                    {settlement.canDecide && (
                                        <div className="flex flex-wrap items-start gap-2">
                                            <StepAction
                                                form={approveWriteOff.form({
                                                    advance: advance.id,
                                                    settlement: settlement.id,
                                                })}
                                                label="Approve write-off"
                                                variant="default"
                                            />
                                            <NoteAction
                                                form={rejectWriteOff.form({
                                                    advance: advance.id,
                                                    settlement: settlement.id,
                                                })}
                                                label="Reject"
                                                prompt="Why it stays owed"
                                                required
                                            />
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}

                {can.settle && (
                    <Panel className="max-w-3xl">
                        <PanelHeader title="Settle what is left" />
                        <PanelBody className="grid gap-6">
                            <BankForm
                                id="return"
                                form={recordReturn.form(advance.id)}
                                methods={methods}
                                label="Record cash returned"
                                bankLabel="Handed back by"
                                amount
                                currency={advance.currency}
                                referenceName="note"
                                referenceLabel="Note (optional)"
                            />
                            <Form
                                {...requestWriteOff.form(advance.id)}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="grid gap-3 sm:grid-cols-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="write_off_amount"
                                            label={`Write off (${advance.currency})`}
                                            error={
                                                errors.write_off_amount ??
                                                errors.amount
                                            }
                                        >
                                            <Input
                                                id="write_off_amount"
                                                name="write_off_amount"
                                                required
                                                inputMode="decimal"
                                            />
                                        </Field>
                                        <Field
                                            id="reason"
                                            label="Why it will not be recovered"
                                            error={errors.reason}
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id="reason"
                                                name="reason"
                                                required
                                                maxLength={2000}
                                            />
                                        </Field>
                                        <div className="sm:col-span-3">
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Ask a second person to approve
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
