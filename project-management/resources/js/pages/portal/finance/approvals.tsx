import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ClipboardCheck } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { cn } from '@miles/ui/lib/utils';
import { PayForm } from '@/components/pay-form';
import { formatMoney } from '@/lib/money';
import { formatDateTime } from '@/lib/status';
import { budget } from '@/routes/projects';
import { approve as approveVersion } from '@/routes/projects/budget/versions';
import {
    approve,
    index as expenses,
    pay,
    reject,
} from '@/routes/projects/expenses';

type Spending = {
    id: string;
    projectId: string;
    project: string;
    currency: string;
    category: string;
    description: string;
    supplier: string | null;
    amountMinor: number;
    grant: string | null;
    requestedBy: string;
    requestedAt: string;
    documents: number;
    mine: boolean;
};

type Props = {
    budgets:
        | {
              id: string;
              projectId: string;
              project: string;
              currency: string;
              number: number;
              totalMinor: number;
              note: string | null;
              submittedBy: string | null;
              submittedAt: string | null;
              mine: boolean;
          }[]
        | null;
    requests:
        | (Spending & {
              availableMinor: number | null;
              approvalsRequired: number;
              approvedBy: string[];
              approvedByMe: boolean;
          })[]
        | null;
    payments: Spending[] | null;
    today: string;
};

/**
 * What waits on finance across the organization. Each queue shows only to
 * those who may act on it; nobody acts on what they submitted themselves.
 */
export default function Approvals({
    budgets,
    requests,
    payments,
    today,
}: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const error = errors.budget ?? errors.expense;

    return (
        <>
            <Head title="Approvals" />
            <PageHeader
                title="Approvals"
                description="Budgets to approve, spending to decide, approved spending to pay."
            />

            {error && (
                <p
                    role="alert"
                    className="mb-4 border border-destructive/40 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                >
                    {error}
                </p>
            )}

            <div className="grid gap-4">
                {budgets && (
                    <Panel>
                        <PanelHeader
                            title="Budgets awaiting approval"
                            description="To return one with a reason, open its budget."
                        />
                        {budgets.length === 0 ? (
                            <Empty title="No budget is waiting" />
                        ) : (
                            <ul className="divide-y">
                                {budgets.map((version) => (
                                    <li
                                        key={version.id}
                                        className="flex flex-wrap items-center gap-3 px-3 py-2 text-sm"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <Link
                                                href={budget({
                                                    project: version.projectId,
                                                })}
                                                className="font-medium hover:underline"
                                            >
                                                {version.project}
                                            </Link>
                                            <p className="text-xs text-muted-foreground">
                                                {[
                                                    `Version ${version.number}`,
                                                    version.submittedBy &&
                                                        `by ${version.submittedBy}`,
                                                    version.submittedAt &&
                                                        formatDateTime(
                                                            version.submittedAt,
                                                        ),
                                                    version.note,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </div>
                                        <span className="tabular-nums">
                                            {formatMoney(
                                                version.totalMinor,
                                                version.currency,
                                            )}
                                        </span>
                                        {version.mine ? (
                                            <span className="text-xs text-muted-foreground">
                                                Yours: someone else approves it
                                            </span>
                                        ) : (
                                            <Form
                                                {...approveVersion.form({
                                                    project: version.projectId,
                                                    budgetVersion: version.id,
                                                })}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {({ processing }) => (
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        disabled={processing}
                                                    >
                                                        Approve
                                                    </Button>
                                                )}
                                            </Form>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                )}

                {requests && (
                    <Panel>
                        <PanelHeader
                            title="Spending requests"
                            description="Approving commits the money: it must fit what is left of the approved budget, and of the grant charged."
                        />
                        {requests.length === 0 ? (
                            <Empty title="No request is waiting" />
                        ) : (
                            <ul className="divide-y">
                                {requests.map((request) => {
                                    const ids = {
                                        project: request.projectId,
                                        expense: request.id,
                                    };
                                    const short =
                                        request.availableMinor !== null &&
                                        request.amountMinor >
                                            request.availableMinor;

                                    return (
                                        <li
                                            key={request.id}
                                            className="grid gap-2 px-3 py-3 text-sm"
                                        >
                                            <SpendingSummary
                                                spending={request}
                                            />
                                            <p
                                                className={cn(
                                                    'text-xs',
                                                    short ||
                                                        request.availableMinor ===
                                                            null
                                                        ? 'text-destructive'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {request.availableMinor === null
                                                    ? 'The project has no approved budget yet.'
                                                    : `${formatMoney(request.availableMinor, request.currency)} left in ${request.category}.`}
                                            </p>
                                            {request.approvalsRequired > 1 && (
                                                <p className="text-xs text-muted-foreground">
                                                    Needs{' '}
                                                    {request.approvalsRequired}{' '}
                                                    approvers:{' '}
                                                    {request.approvedBy
                                                        .length === 0
                                                        ? 'none yet.'
                                                        : `${request.approvedBy.length} so far (${request.approvedBy.join(', ')}).`}
                                                </p>
                                            )}
                                            {request.approvedByMe ? (
                                                <p className="text-xs text-muted-foreground">
                                                    You approved it: it waits
                                                    for another approver.
                                                </p>
                                            ) : request.mine ? (
                                                <p className="text-xs text-muted-foreground">
                                                    Yours: someone else decides
                                                    it.
                                                </p>
                                            ) : (
                                                <div className="flex flex-wrap items-end gap-2">
                                                    <Form
                                                        {...approve.form(ids)}
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Approve
                                                            </Button>
                                                        )}
                                                    </Form>
                                                    <Form
                                                        {...reject.form(ids)}
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                        className="flex flex-1 flex-wrap items-center gap-2"
                                                    >
                                                        {({ processing }) => (
                                                            <>
                                                                <Input
                                                                    name="reason"
                                                                    required
                                                                    aria-label="Reason for rejecting"
                                                                    placeholder="Reason for rejecting"
                                                                    className="h-8 max-w-72"
                                                                />
                                                                <Button
                                                                    type="submit"
                                                                    size="sm"
                                                                    variant="outline"
                                                                    disabled={
                                                                        processing
                                                                    }
                                                                >
                                                                    Reject
                                                                </Button>
                                                            </>
                                                        )}
                                                    </Form>
                                                </div>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </Panel>
                )}

                {payments && (
                    <Panel>
                        <PanelHeader
                            title="To pay"
                            description="Approved spending, committed until it is paid."
                        />
                        {payments.length === 0 ? (
                            <Empty title="Nothing to pay" />
                        ) : (
                            <ul className="divide-y">
                                {payments.map((payment) => (
                                    <li
                                        key={payment.id}
                                        className="grid gap-2 px-3 py-3 text-sm"
                                    >
                                        <SpendingSummary spending={payment} />
                                        {payment.mine ? (
                                            <p className="text-xs text-muted-foreground">
                                                Yours: someone else pays it.
                                            </p>
                                        ) : (
                                            <PayForm
                                                form={pay.form({
                                                    project: payment.projectId,
                                                    expense: payment.id,
                                                })}
                                                id={payment.id}
                                                today={today}
                                            />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                )}
            </div>
        </>
    );
}

function SpendingSummary({ spending }: { spending: Spending }) {
    return (
        <div className="flex flex-wrap items-start gap-2">
            <div className="min-w-0 flex-1">
                <p className="font-medium">{spending.description}</p>
                <p className="text-xs text-muted-foreground">
                    <Link
                        href={expenses({ project: spending.projectId })}
                        className="hover:underline"
                    >
                        {spending.project}
                    </Link>
                    {' · '}
                    {[
                        spending.category,
                        spending.supplier,
                        spending.grant && `grant ${spending.grant}`,
                        `by ${spending.requestedBy}, ${formatDateTime(spending.requestedAt)}`,
                        spending.documents === 1
                            ? '1 document'
                            : `${spending.documents} documents`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            </div>
            <span className="tabular-nums">
                {formatMoney(spending.amountMinor, spending.currency)}
            </span>
        </div>
    );
}

function Empty({ title }: { title: string }) {
    return <EmptyState icon={ClipboardCheck} title={title} />;
}
