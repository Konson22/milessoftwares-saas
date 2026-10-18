import { Form } from '@inertiajs/react';
import { StatusBadge } from '@miles/ui/components/status-badge';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';
import {
    formatDate,
    formatMoney,
    humanize,
    invoiceTone,
    methodLabel,
    paymentTone,
    requestTone,
} from '@/shared/lib/billing';
import { ReasonForm } from '@/sites/admin/components/reason-form';
import type { Subscription } from '@/sites/admin/components/billing-controls';

export type OrganizationBilling = {
    invoices: {
        id: string;
        number: string;
        status: string;
        currency: string;
        totalMinor: number;
        balanceMinor: number;
        issuedOn: string;
        dueOn: string;
        voidable: boolean;
        payments: {
            id: string;
            status: string;
            method: string;
            amountMinor: number;
            reference: string | null;
            paidOn: string;
            note: string | null;
        }[];
    }[];
    requests: {
        id: string;
        type: 'subscribe' | 'cancel';
        status: string;
        plan: string | null;
        trialDays: number;
        note: string | null;
        decisionNote: string | null;
        requestedBy: string | null;
        requestedAt: string | null;
    }[];
    dueDays: number;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm';
const methods = ['bank_transfer', 'mobile_money', 'cash', 'card', 'other'];

function dateIn(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return date.toLocaleDateString('en-CA');
}

/**
 * Plan requests from the owner, for staff with entitlements.manage.
 */
export function PlanRequestControls({
    base,
    requests,
}: {
    base: string;
    requests: OrganizationBilling['requests'];
}) {
    return (
        <div id="plan-requests" className="space-y-3">
            <h2 className="text-lg font-medium">Plan requests</h2>
            {requests.length === 0 ? (
                <p className="text-sm text-muted-foreground">None.</p>
            ) : (
                <ul className="divide-y rounded-lg border text-sm">
                    {requests.map((request) => (
                        <li key={request.id} className="space-y-2 p-3">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge tone={requestTone[request.status]}>
                                    {humanize(request.status)}
                                </StatusBadge>
                                <span className="flex-1 font-medium">
                                    {request.type === 'subscribe'
                                        ? `Subscribe to ${request.plan}`
                                        : 'Cancel a subscription'}
                                </span>
                                <span className="text-muted-foreground">
                                    {formatDate(request.requestedAt)}
                                    {request.requestedBy &&
                                        ` · ${request.requestedBy}`}
                                </span>
                            </div>
                            {request.note && (
                                <p className="text-muted-foreground">
                                    “{request.note}”
                                </p>
                            )}
                            {request.decisionNote && (
                                <p className="text-muted-foreground">
                                    Reply: {request.decisionNote}
                                </p>
                            )}
                            {request.status === 'pending' && (
                                <div className="flex flex-wrap items-start gap-3">
                                    <Form
                                        action={`${base}/plan-requests/${request.id}/approve`}
                                        method="post"
                                        options={{ preserveScroll: true }}
                                        className="flex items-center gap-2"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                {request.type === 'subscribe' &&
                                                    request.trialDays > 0 && (
                                                        <label className="flex items-center gap-1.5">
                                                            <input
                                                                type="checkbox"
                                                                name="trial"
                                                                value="1"
                                                            />
                                                            {request.trialDays}
                                                            -day trial
                                                        </label>
                                                    )}
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    Approve
                                                </Button>
                                                <InputError
                                                    message={errors.request}
                                                />
                                            </>
                                        )}
                                    </Form>
                                    <ReasonForm
                                        action={`${base}/plan-requests/${request.id}/decline`}
                                        field="note"
                                        label="Decline"
                                        placeholder="Reply to the customer"
                                    />
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * Invoices and payments for staff with billing.manage. Invoices are never
 * edited: a wrong one is voided and issued again.
 */
export function InvoiceControls({
    base,
    billing,
    subscriptions,
    currency,
}: {
    base: string;
    billing: OrganizationBilling;
    subscriptions: Subscription[];
    currency: string;
}) {
    const live = subscriptions.filter((subscription) =>
        ['active', 'trialing', 'past_due'].includes(subscription.status),
    );

    return (
        <div className="space-y-3">
            <div className="space-y-3">
                <h2 className="text-lg font-medium">Invoices</h2>
                {billing.invoices.length === 0 ? (
                    <p className="text-sm text-muted-foreground">None.</p>
                ) : (
                    <ul className="divide-y rounded-lg border text-sm">
                        {billing.invoices.map((invoice) => (
                            <li key={invoice.id} className="space-y-2 p-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge
                                        tone={invoiceTone[invoice.status]}
                                    >
                                        {humanize(invoice.status)}
                                    </StatusBadge>
                                    <span className="flex-1 font-medium">
                                        {invoice.number}
                                    </span>
                                    <span className="text-muted-foreground">
                                        due {formatDate(invoice.dueOn)}
                                    </span>
                                    <span className="tabular-nums">
                                        {formatMoney(
                                            invoice.totalMinor,
                                            invoice.currency,
                                        )}
                                        {invoice.balanceMinor > 0 &&
                                            invoice.balanceMinor !==
                                                invoice.totalMinor &&
                                            ` (${formatMoney(invoice.balanceMinor, invoice.currency)} owed)`}
                                    </span>
                                </div>

                                {invoice.payments.length > 0 && (
                                    <ul className="space-y-1 rounded-md bg-muted/40 p-2">
                                        {invoice.payments.map((payment) => (
                                            <li
                                                key={payment.id}
                                                className="flex flex-wrap items-center gap-2"
                                            >
                                                <StatusBadge
                                                    tone={
                                                        paymentTone[
                                                            payment.status
                                                        ]
                                                    }
                                                >
                                                    {humanize(payment.status)}
                                                </StatusBadge>
                                                <span className="flex-1">
                                                    {formatMoney(
                                                        payment.amountMinor,
                                                        invoice.currency,
                                                    )}{' '}
                                                    ·{' '}
                                                    {methodLabel[
                                                        payment.method
                                                    ] ?? payment.method}
                                                    {payment.reference &&
                                                        ` · ${payment.reference}`}{' '}
                                                    ·{' '}
                                                    {formatDate(payment.paidOn)}
                                                </span>
                                                {payment.status ===
                                                    'reported' && (
                                                    <>
                                                        <Form
                                                            action={`${base}/payments/${payment.id}/confirm`}
                                                            method="post"
                                                            options={{
                                                                preserveScroll: true,
                                                            }}
                                                        >
                                                            {({
                                                                processing,
                                                            }) => (
                                                                <Button
                                                                    type="submit"
                                                                    size="sm"
                                                                    disabled={
                                                                        processing
                                                                    }
                                                                >
                                                                    Confirm
                                                                </Button>
                                                            )}
                                                        </Form>
                                                        <ReasonForm
                                                            action={`${base}/payments/${payment.id}/reject`}
                                                            field="reason"
                                                            label="Reject"
                                                        />
                                                    </>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {invoice.balanceMinor > 0 && (
                                    <RecordPayment
                                        action={`${base}/invoices/${invoice.id}/payments`}
                                        id={invoice.id}
                                    />
                                )}
                                {invoice.voidable && (
                                    <ReasonForm
                                        action={`${base}/invoices/${invoice.id}/void`}
                                        field="reason"
                                        label="Void invoice"
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <Form
                action={`${base}/invoices`}
                method="post"
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="space-y-3 rounded-lg border p-4"
            >
                {({ errors, processing }) => (
                    <>
                        <h3 className="font-medium">Issue an invoice</h3>
                        <div className="grid gap-1.5">
                            <Label htmlFor="subscription_id">
                                Bill a subscription
                            </Label>
                            <select
                                id="subscription_id"
                                name="subscription_id"
                                className={selectClass}
                                defaultValue=""
                            >
                                <option value="">
                                    None: one-off charge below
                                </option>
                                {live.map((subscription) => (
                                    <option
                                        key={subscription.id}
                                        value={subscription.id}
                                    >
                                        {subscription.plans.join(', ')} (
                                        {subscription.status})
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.subscription_id} />
                        </div>
                        <div className="grid grid-cols-[1fr_8rem_5rem] gap-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="description">
                                    One-off description
                                </Label>
                                <Input id="description" name="description" />
                                <InputError message={errors.description} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="amount">Amount</Label>
                                <Input
                                    id="amount"
                                    name="amount"
                                    inputMode="decimal"
                                />
                                <InputError message={errors.amount} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="currency">Currency</Label>
                                <Input
                                    id="currency"
                                    name="currency"
                                    maxLength={3}
                                    defaultValue={currency}
                                />
                            </div>
                        </div>
                        <div className="grid grid-cols-3 gap-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="due_on">Due</Label>
                                <Input
                                    id="due_on"
                                    name="due_on"
                                    type="date"
                                    required
                                    defaultValue={dateIn(billing.dueDays)}
                                />
                                <InputError message={errors.due_on} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="period_start">
                                    Period from
                                </Label>
                                <Input
                                    id="period_start"
                                    name="period_start"
                                    type="date"
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="period_end">to</Label>
                                <Input
                                    id="period_end"
                                    name="period_end"
                                    type="date"
                                />
                                <InputError message={errors.period_end} />
                            </div>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="notes">Notes on the invoice</Label>
                            <Input id="notes" name="notes" />
                        </div>
                        <Button type="submit" size="sm" disabled={processing}>
                            Issue invoice
                        </Button>
                    </>
                )}
            </Form>
        </div>
    );
}

function RecordPayment({ action, id }: { action: string; id: string }) {
    return (
        <details className="text-sm">
            <summary className="cursor-pointer text-muted-foreground">
                Record a payment received
            </summary>
            <Form
                action={action}
                method="post"
                options={{ preserveScroll: true }}
                resetOnSuccess
                className="mt-2 flex flex-wrap items-end gap-2"
            >
                {({ errors, processing }) => (
                    <>
                        <div className="grid gap-1">
                            <Label htmlFor={`method-${id}`}>Method</Label>
                            <select
                                id={`method-${id}`}
                                name="method"
                                className={selectClass}
                            >
                                {methods.map((method) => (
                                    <option key={method} value={method}>
                                        {methodLabel[method]}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid w-28 gap-1">
                            <Label htmlFor={`amount-${id}`}>Amount</Label>
                            <Input
                                id={`amount-${id}`}
                                name="amount"
                                inputMode="decimal"
                                required
                            />
                        </div>
                        <div className="grid w-36 gap-1">
                            <Label htmlFor={`reference-${id}`}>Reference</Label>
                            <Input id={`reference-${id}`} name="reference" />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor={`paid_on-${id}`}>Paid on</Label>
                            <Input
                                id={`paid_on-${id}`}
                                name="paid_on"
                                type="date"
                                required
                                defaultValue={dateIn(0)}
                            />
                        </div>
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            disabled={processing}
                        >
                            Record
                        </Button>
                        <InputError
                            message={errors.amount ?? errors.paid_on}
                            className="w-full"
                        />
                    </>
                )}
            </Form>
        </details>
    );
}
