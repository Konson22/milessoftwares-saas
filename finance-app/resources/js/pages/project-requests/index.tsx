import { Form, Head } from '@inertiajs/react';
import { Check, FolderKanban, X } from 'lucide-react';
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
import { prepare, refuse } from '@/routes/project-requests';

type Bank = { id: string; label: string; currency: string | null };

type ProjectRequest = {
    id: string;
    project: string;
    description: string;
    supplier: string | null;
    grantReference: string | null;
    category: string;
    currency: string;
    amountMinor: number;
    amountMajor: string;
    revision: number;
    status: string;
    requestedAt: string;
    cancellationRequested: boolean;
    decisionNote: string | null;
    paymentRequest: {
        number: string;
        status: string;
        amountMinor: number;
    } | null;
};

type Props = {
    waiting: ProjectRequest[];
    recent: ProjectRequest[];
    banks: Bank[];
    today: string;
};

const STATUS: Record<string, { label: string; tone: Tone }> = {
    received: { label: 'Waiting', tone: 'warning' },
    prepared: { label: 'Payment request raised', tone: 'info' },
    approved: { label: 'Approved', tone: 'info' },
    paid: { label: 'Paid', tone: 'success' },
    reversed: { label: 'Payment reversed', tone: 'danger' },
    rejected: { label: 'Refused', tone: 'danger' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
};

/**
 * Spending a project approved, asking Finance to pay it. Projects' approval
 * is operational: Finance takes the request in as a payment request (its own
 * review, approval and disbursement follow) or refuses it.
 */
export default function ProjectRequests({
    waiting,
    recent,
    banks,
    today,
}: Props) {
    return (
        <>
            <Head title="Project requests" />
            <PageHeader
                title="Project requests"
                description="Spending approved in Projects, waiting for Finance. Nothing is paid until a payment request is approved and disbursed."
            />

            <Panel className="mb-3">
                <PanelHeader title="Waiting for Finance" />
                {waiting.length === 0 ? (
                    <EmptyState icon={FolderKanban} title="Nothing waiting">
                        Requests appear here when a project hands spending to
                        Finance.
                    </EmptyState>
                ) : (
                    <div className="divide-y">
                        {waiting.map((request) => (
                            <Waiting
                                key={request.id}
                                request={request}
                                banks={banks}
                                today={today}
                            />
                        ))}
                    </div>
                )}
            </Panel>

            <Panel>
                <PanelHeader title="Decided" />
                {recent.length === 0 ? (
                    <PanelBody className="text-sm text-muted-foreground">
                        None yet.
                    </PanelBody>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Request</TableHead>
                                <TableHead>Payment request</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Asked
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {recent.map((request) => (
                                <TableRow key={request.id}>
                                    <TableCell>
                                        <div>{request.project}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {request.description}
                                            {request.revision > 1 &&
                                                ` · revision ${request.revision}`}
                                        </div>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {request.paymentRequest?.number ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={STATUS[request.status]?.tone}
                                        >
                                            {STATUS[request.status]?.label ??
                                                request.status}
                                        </StatusBadge>
                                        {request.cancellationRequested &&
                                            request.status !== 'cancelled' && (
                                                <div className="text-xs text-destructive">
                                                    Projects asked to cancel
                                                </div>
                                            )}
                                        {request.decisionNote && (
                                            <div className="text-xs text-muted-foreground">
                                                {request.decisionNote}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            minor={request.amountMinor}
                                            currency={request.currency}
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

function Waiting({
    request,
    banks,
    today,
}: {
    request: ProjectRequest;
    banks: Bank[];
    today: string;
}) {
    const can = useCan();
    const [mode, setMode] = useState<'prepare' | 'refuse' | null>(null);
    const payable = banks.filter(
        (bank) => (bank.currency ?? request.currency) === request.currency,
    );

    return (
        <div className="grid gap-3 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="font-medium">{request.project}</div>
                    <div className="text-sm">{request.description}</div>
                    <div className="text-xs text-muted-foreground">
                        {[
                            request.supplier,
                            request.grantReference &&
                                `grant ${request.grantReference}`,
                            `asked ${request.requestedAt}`,
                            request.revision > 1 &&
                                `revision ${request.revision}`,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </div>
                </div>
                <div className="text-right">
                    <Amount
                        minor={request.amountMinor}
                        currency={request.currency}
                    />
                </div>
            </div>
            {mode === null && (
                <div className="flex flex-wrap gap-2">
                    {can('finance.payments.prepare') && (
                        <Button size="sm" onClick={() => setMode('prepare')}>
                            <Check className="size-4" aria-hidden />
                            Take in
                        </Button>
                    )}
                    {(can('finance.payments.review') ||
                        can('finance.payments.approve')) && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setMode('refuse')}
                        >
                            <X className="size-4" aria-hidden />
                            Refuse
                        </Button>
                    )}
                </div>
            )}
            {mode === 'prepare' && (
                <Form
                    {...prepare.form(request.id)}
                    className="grid gap-3 sm:grid-cols-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field
                                id={`account-${request.id}`}
                                label={`Pay from (${request.currency})`}
                                error={errors.account_id ?? errors.request}
                            >
                                <select
                                    id={`account-${request.id}`}
                                    name="account_id"
                                    className="h-9 rounded-md border bg-background px-2 text-sm"
                                    required
                                >
                                    {payable.map((bank) => (
                                        <option key={bank.id} value={bank.id}>
                                            {bank.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field
                                id={`amount-${request.id}`}
                                label="Amount (up to what was asked)"
                                error={errors.amount}
                            >
                                <Input
                                    id={`amount-${request.id}`}
                                    name="amount"
                                    inputMode="decimal"
                                    defaultValue={request.amountMajor}
                                    required
                                />
                            </Field>
                            <Field
                                id={`pay-on-${request.id}`}
                                label="Pay on"
                                error={errors.pay_on}
                            >
                                <Input
                                    id={`pay-on-${request.id}`}
                                    name="pay_on"
                                    type="date"
                                    defaultValue={today}
                                    required
                                />
                            </Field>
                            <div className="flex gap-2 sm:col-span-3">
                                <Button
                                    size="sm"
                                    type="submit"
                                    disabled={processing || payable.length === 0}
                                >
                                    Raise payment request
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    type="button"
                                    onClick={() => setMode(null)}
                                >
                                    Back
                                </Button>
                                {payable.length === 0 && (
                                    <span className="text-xs text-destructive">
                                        No bank or cash account in{' '}
                                        {request.currency}.
                                    </span>
                                )}
                            </div>
                        </>
                    )}
                </Form>
            )}
            {mode === 'refuse' && (
                <Form {...refuse.form(request.id)} className="grid gap-2">
                    {({ processing, errors }) => (
                        <>
                            <Field
                                id={`reason-${request.id}`}
                                label="Why (Projects sees this)"
                                error={errors.reason ?? errors.request}
                            >
                                <Input
                                    id={`reason-${request.id}`}
                                    name="reason"
                                    maxLength={255}
                                    required
                                />
                            </Field>
                            <div className="flex gap-2">
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    type="submit"
                                    disabled={processing}
                                >
                                    Refuse
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    type="button"
                                    onClick={() => setMode(null)}
                                >
                                    Back
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}
