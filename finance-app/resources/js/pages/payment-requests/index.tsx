import { Form, Head, Link } from '@inertiajs/react';
import { Banknote, Check, Eye, Plus, Undo2, X } from 'lucide-react';
import { useState } from 'react';
import { Amount } from '@/components/amount';
import { Field } from '@/components/field';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import type { Tone } from '@miles/ui/components/status-badge';
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
import { useCan } from '@/lib/access';
import { show as journalRoute } from '@/routes/journals';
import {
    approve,
    create,
    disburse,
    reject,
    review,
    withdraw,
} from '@/routes/payment-requests';

type Payment = {
    id: string;
    number: string;
    kind: string;
    status: string;
    description: string;
    payee: string | null;
    from: string;
    to: string | null;
    costCentre: string | null;
    amountMinor: number;
    currency: string | null;
    booksMinor: number;
    payOn: string;
    preparedBy: string;
    preparedAt: string;
    reviewedBy: string | null;
    approvedBy: string | null;
    disbursedBy: string | null;
    closedBy: string | null;
    closeNote: string | null;
    journal: { id: string; number: string } | null;
    own: boolean;
    canReview: boolean;
    canApprove: boolean;
    canDisburse: boolean;
    canReject: boolean;
    canWithdraw: boolean;
};

type Props = {
    open: Payment[];
    closed: Payment[];
    today: string;
};

const KINDS: Record<string, string> = {
    supplier: 'Supplier payment',
    expense: 'Expense',
    advance: 'Employee advance',
    transfer: 'Transfer',
    tax: 'Tax settlement',
    journal: 'Manual journal',
    reversal: 'Journal reversal',
};

/**
 * Why paying was refused, whichever step of the books refused it: what the
 * date and reference fields do not show themselves.
 */
function refusal(errors: Record<string, string>): string | undefined {
    return Object.entries(errors).find(
        ([field]) => !['paid_on', 'entry_date', 'reference'].includes(field),
    )?.[1];
}

const STATUS: Record<string, { label: string; tone: Tone }> = {
    pending_review: { label: 'In review', tone: 'warning' },
    pending_approval: { label: 'Waiting for approval', tone: 'warning' },
    approved: { label: 'Ready to disburse', tone: 'info' },
    disbursed: { label: 'Disbursed', tone: 'success' },
    rejected: { label: 'Rejected', tone: 'danger' },
    withdrawn: { label: 'Withdrawn', tone: 'neutral' },
};

/**
 * Money on its way out: prepared, reviewed, approved, then disbursed, when it
 * reaches the books.
 */
export default function PaymentRequests({ open, closed, today }: Props) {
    const can = useCan();

    return (
        <>
            <Head title="Payment requests" />
            <PageHeader
                title="Payment requests"
                description="Nothing reaches the books until a request is disbursed."
                actions={
                    can('finance.payments.prepare') && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus className="size-4" aria-hidden />
                                New request
                            </Link>
                        </Button>
                    )
                }
            />

            <Panel className="mb-3">
                <PanelHeader title="Open" />
                {open.length === 0 ? (
                    <EmptyState icon={Banknote} title="Nothing waiting">
                        Requests appear here when they are prepared.
                    </EmptyState>
                ) : (
                    <div className="divide-y">
                        {open.map((payment) => (
                            <OpenRequest
                                key={payment.id}
                                payment={payment}
                                today={today}
                            />
                        ))}
                    </div>
                )}
            </Panel>

            <Panel>
                <PanelHeader title="Recently closed" />
                {closed.length === 0 ? (
                    <PanelBody className="text-sm text-muted-foreground">
                        None yet.
                    </PanelBody>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Request</TableHead>
                                <TableHead>Who</TableHead>
                                <TableHead>Outcome</TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {closed.map((payment) => (
                                <TableRow key={payment.id}>
                                    <TableCell>
                                        <span className="font-mono text-xs">
                                            {payment.number}
                                        </span>{' '}
                                        {payment.journal && (
                                            <Link
                                                href={journalRoute(
                                                    payment.journal.id,
                                                )}
                                                className="font-mono text-xs hover:underline"
                                            >
                                                → {payment.journal.number}
                                            </Link>
                                        )}
                                        <div className="text-xs text-muted-foreground">
                                            {KINDS[payment.kind]} ·{' '}
                                            {payment.description}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-xs">
                                        <Trail payment={payment} />
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={STATUS[payment.status]?.tone}
                                        >
                                            {STATUS[payment.status]?.label ??
                                                payment.status}
                                        </StatusBadge>
                                        {payment.closeNote && (
                                            <div className="text-xs text-muted-foreground">
                                                {payment.closeNote}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            minor={payment.amountMinor}
                                            currency={payment.currency}
                                        />
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

function Trail({ payment }: { payment: Payment }) {
    return (
        <span className="text-muted-foreground">
            {[
                `Prepared by ${payment.preparedBy}`,
                payment.reviewedBy && `reviewed by ${payment.reviewedBy}`,
                payment.approvedBy && `approved by ${payment.approvedBy}`,
                payment.disbursedBy && `disbursed by ${payment.disbursedBy}`,
                payment.closedBy && `closed by ${payment.closedBy}`,
            ]
                .filter(Boolean)
                .join(', ')}
        </span>
    );
}

function StepButton({
    action,
    label,
    icon: Icon,
}: {
    action: ReturnType<typeof approve.form>;
    label: string;
    icon: typeof Check;
}) {
    return (
        <Form {...action}>
            {({ processing, errors }) => (
                <>
                    <Button size="sm" type="submit" disabled={processing}>
                        <Icon className="size-4" aria-hidden />
                        {label}
                    </Button>
                    {errors.request && (
                        <p className="mt-1 text-xs text-destructive">
                            {errors.request}
                        </p>
                    )}
                </>
            )}
        </Form>
    );
}

function OpenRequest({ payment, today }: { payment: Payment; today: string }) {
    const [rejecting, setRejecting] = useState(false);
    const [paying, setPaying] = useState(false);
    const status = STATUS[payment.status];

    return (
        <div className="grid gap-2 p-3">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <span className="font-mono text-xs">{payment.number}</span>{' '}
                    <span className="font-medium">{payment.description}</span>{' '}
                    <StatusBadge tone={status?.tone}>
                        {status?.label ?? payment.status}
                    </StatusBadge>
                    <div className="text-xs text-muted-foreground">
                        {KINDS[payment.kind]}
                        {payment.payee && ` to ${payment.payee}`} · from{' '}
                        {payment.from}
                        {payment.to && ` · ${payment.to}`}
                        {payment.costCentre && ` · ${payment.costCentre}`} · for{' '}
                        {payment.payOn}
                    </div>
                    <div className="text-xs">
                        <Trail payment={payment} />
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Amount
                        minor={payment.amountMinor}
                        currency={payment.currency}
                        className="font-semibold"
                    />
                    {payment.canReview && (
                        <StepButton
                            action={review.form(payment.id)}
                            label="Mark reviewed"
                            icon={Eye}
                        />
                    )}
                    {payment.canApprove && (
                        <StepButton
                            action={approve.form(payment.id)}
                            label="Approve"
                            icon={Check}
                        />
                    )}
                    {payment.canDisburse && (
                        <Button
                            size="sm"
                            onClick={() => setPaying((value) => !value)}
                        >
                            <Banknote className="size-4" aria-hidden />
                            Disburse
                        </Button>
                    )}
                    {payment.canReject && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setRejecting((value) => !value)}
                        >
                            <X className="size-4" aria-hidden />
                            Reject
                        </Button>
                    )}
                    {payment.canWithdraw && (
                        <Form {...withdraw.form(payment.id)}>
                            {({ processing }) => (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    type="submit"
                                    disabled={processing}
                                >
                                    <Undo2 className="size-4" aria-hidden />
                                    Withdraw
                                </Button>
                            )}
                        </Form>
                    )}
                </div>
            </div>

            {paying && (
                <Form
                    {...disburse.form(payment.id)}
                    className="flex flex-wrap items-end gap-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field
                                id={`paid-on-${payment.id}`}
                                label="Paid on"
                                error={errors.paid_on ?? errors.entry_date}
                            >
                                <Input
                                    id={`paid-on-${payment.id}`}
                                    name="paid_on"
                                    type="date"
                                    defaultValue={today}
                                    required
                                />
                            </Field>
                            <Field
                                id={`reference-${payment.id}`}
                                label="Reference"
                                error={errors.reference}
                                className="min-w-48 flex-1"
                            >
                                <Input
                                    id={`reference-${payment.id}`}
                                    name="reference"
                                    maxLength={64}
                                />
                            </Field>
                            <Button type="submit" disabled={processing}>
                                Pay {payment.number}
                            </Button>
                            {refusal(errors) && (
                                <p className="w-full text-sm text-destructive">
                                    {refusal(errors)}
                                </p>
                            )}
                        </>
                    )}
                </Form>
            )}

            {rejecting && (
                <Form
                    {...reject.form(payment.id)}
                    className="flex flex-wrap items-end gap-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field
                                id={`reason-${payment.id}`}
                                label="Why"
                                error={errors.reason ?? errors.request}
                                className="min-w-64 flex-1"
                            >
                                <Input
                                    id={`reason-${payment.id}`}
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
                                Reject {payment.number}
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}
