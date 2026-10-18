import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@/shared/components/ui/button';
import { ReasonForm } from '@/sites/admin/components/reason-form';
import { formatDate, formatMoney, methodLabel } from '@/shared/lib/billing';

type Props = {
    payments: {
        id: string;
        organizationId: string;
        organization: string | null;
        invoiceNumber: string;
        method: string;
        amountMinor: number;
        currency: string;
        reference: string | null;
        paidOn: string;
        note: string | null;
        reportedBy: string | null;
    }[];
    requests: {
        id: string;
        organizationId: string;
        organization: string | null;
        type: 'subscribe' | 'cancel';
        plan: string | null;
        note: string | null;
        requestedBy: string | null;
        requestedAt: string | null;
    }[];
    overdue: {
        id: string;
        organizationId: string;
        organization: string | null;
        number: string;
        balanceMinor: number;
        currency: string;
        dueOn: string;
    }[];
    can: { decideRequests: boolean };
};

const organizationUrl = (id: string) => `/organizations/${id}`;

/**
 * What waits on billing staff: payments customers reported, plan requests,
 * and invoices past their due date.
 */
export default function BillingQueue({
    payments,
    requests,
    overdue,
    can,
}: Props) {
    return (
        <>
            <Head title="Billing" />

            <PageHeader
                title="Billing"
                description="Confirm payments only once they show in the bank or mobile money statement."
            />

            <div className="space-y-3">
                <Panel>
                    <PanelHeader
                        title="Payments awaiting confirmation"
                        description="Reported by customers, oldest first."
                    />
                    {payments.length === 0 ? (
                        <p className="p-4 text-sm text-muted-foreground">
                            Nothing to confirm.
                        </p>
                    ) : (
                        <ul className="divide-y text-sm">
                            {payments.map((payment) => (
                                <li
                                    key={payment.id}
                                    className="flex flex-wrap items-start gap-4 p-4"
                                >
                                    <div className="min-w-0 flex-1 space-y-0.5">
                                        <p>
                                            <Link
                                                href={organizationUrl(
                                                    payment.organizationId,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {payment.organization}
                                            </Link>{' '}
                                            · {payment.invoiceNumber}
                                        </p>
                                        <p className="font-semibold tabular-nums">
                                            {formatMoney(
                                                payment.amountMinor,
                                                payment.currency,
                                            )}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {methodLabel[payment.method]} · ref{' '}
                                            <span className="font-mono text-foreground">
                                                {payment.reference ?? '—'}
                                            </span>{' '}
                                            · paid {formatDate(payment.paidOn)}
                                            {payment.reportedBy &&
                                                ` · by ${payment.reportedBy}`}
                                        </p>
                                        {payment.note && (
                                            <p className="text-muted-foreground">
                                                “{payment.note}”
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap items-start gap-2">
                                        <Form
                                            action={`${organizationUrl(payment.organizationId)}/payments/${payment.id}/confirm`}
                                            method="post"
                                            options={{ preserveScroll: true }}
                                        >
                                            {({ processing }) => (
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    Confirm
                                                </Button>
                                            )}
                                        </Form>
                                        <ReasonForm
                                            action={`${organizationUrl(payment.organizationId)}/payments/${payment.id}/reject`}
                                            field="reason"
                                            label="Reject"
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Plan requests"
                        description="Approving a plan starts it at once; approving a cancellation ends access."
                    />
                    {requests.length === 0 ? (
                        <p className="p-4 text-sm text-muted-foreground">
                            No requests.
                        </p>
                    ) : (
                        <ul className="divide-y text-sm">
                            {requests.map((request) => (
                                <li
                                    key={request.id}
                                    className="flex flex-wrap items-start gap-4 p-4"
                                >
                                    <div className="min-w-0 flex-1 space-y-0.5">
                                        <p>
                                            <Link
                                                href={organizationUrl(
                                                    request.organizationId,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {request.organization}
                                            </Link>{' '}
                                            <StatusBadge
                                                tone={
                                                    request.type === 'cancel'
                                                        ? 'danger'
                                                        : 'info'
                                                }
                                            >
                                                {request.type === 'cancel'
                                                    ? 'Cancel'
                                                    : `Subscribe: ${request.plan}`}
                                            </StatusBadge>
                                        </p>
                                        <p className="text-muted-foreground">
                                            {formatDate(request.requestedAt)}
                                            {request.requestedBy &&
                                                ` · ${request.requestedBy}`}
                                        </p>
                                        {request.note && (
                                            <p className="text-muted-foreground">
                                                “{request.note}”
                                            </p>
                                        )}
                                    </div>
                                    {can.decideRequests ? (
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link
                                                href={`${organizationUrl(request.organizationId)}#plan-requests`}
                                            >
                                                Review
                                            </Link>
                                        </Button>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">
                                            Needs entitlements.manage
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel>
                    <PanelHeader title="Overdue invoices" />
                    {overdue.length === 0 ? (
                        <p className="p-4 text-sm text-muted-foreground">
                            None overdue.
                        </p>
                    ) : (
                        <ul className="divide-y text-sm">
                            {overdue.map((invoice) => (
                                <li
                                    key={invoice.id}
                                    className="flex flex-wrap items-center gap-3 px-4 py-3"
                                >
                                    <Link
                                        href={organizationUrl(
                                            invoice.organizationId,
                                        )}
                                        className="min-w-0 flex-1 hover:underline"
                                    >
                                        <span className="font-medium">
                                            {invoice.organization}
                                        </span>{' '}
                                        · {invoice.number}
                                    </Link>
                                    <span className="text-muted-foreground">
                                        due {formatDate(invoice.dueOn)}
                                    </span>
                                    <span className="font-medium tabular-nums">
                                        {formatMoney(
                                            invoice.balanceMinor,
                                            invoice.currency,
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}
