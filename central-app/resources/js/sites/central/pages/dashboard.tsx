import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    AppWindow,
    ArrowRight,
    Banknote,
    Building2,
    Clock,
    CreditCard,
    GitBranch,
    MailPlus,
    Plus,
    Puzzle,
    ReceiptText,
    Users,
} from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge } from '@miles/ui/components/status-badge';
import type { Tone } from '@miles/ui/components/status-badge';
import OrganizationController from '@/actions/App/Sites/Central/Http/Controllers/Dashboard/OrganizationController';
import { Button } from '@/shared/components/ui/button';
import { dashboard } from '@/routes';
import {
    billing as organizationBilling,
    plans as organizationPlans,
    settings as organizationSettings,
    subscription as organizationSubscription,
} from '@/routes/organization';
import { show as showInvoice } from '@/routes/organization/invoices';
import { create as createOrganization } from '@/routes/organizations';
import { appIcon } from '@/shared/lib/app-icons';
import {
    formatDate,
    formatMoney,
    humanize,
    intervalLabel,
    invoiceTone,
    methodLabel,
    paymentStatusLabel,
    paymentTone,
    subscriptionTone,
} from '@/shared/lib/billing';

type Organization = {
    id: string;
    displayName: string;
    status: 'active' | 'suspended';
    workspaceUrl: string | null;
    isOwner: boolean;
    isCurrent: boolean;
};

type Billing = {
    balances: { currency: string; amountMinor: number }[];
    openInvoices: number;
    overdueInvoices: number;
    nextDue: {
        id: string;
        number: string;
        dueOn: string;
        balanceMinor: number;
        currency: string;
        overdue: boolean;
    } | null;
    paymentsAwaitingConfirmation: number;
    pendingRequests: number;
    liveSubscriptions: number;
    trialEndsAt: string | null;
    recentInvoices: {
        id: string;
        number: string;
        status: string;
        currency: string;
        totalMinor: number;
        dueOn: string;
    }[];
    subscriptions: {
        id: string;
        status: string;
        trialEndsAt: string | null;
        renewsAt: string | null;
        plans: {
            name: string;
            priceMinor: number;
            currency: string;
            interval: string;
        }[];
    }[];
    recentPayments: {
        id: string;
        invoiceId: string;
        invoiceNumber: string;
        status: string;
        method: string;
        amountMinor: number;
        currency: string;
        paidOn: string;
    }[];
    modules: Module[];
};

type Module = {
    code: string;
    name: string;
    tagline: string | null;
    description: string | null;
    state:
        | 'installed'
        | 'requested'
        | 'available'
        | 'on_request'
        | 'coming_soon';
};

type Current = {
    displayName: string;
    workspaceUrl: string | null;
    applications: {
        code: string;
        name: string;
        description: string | null;
        href: string;
    }[];
    counts: {
        members: number;
        branches: number;
        departments: number;
        pendingInvitations: number;
    };
    isOwner: boolean;
    canManageAccess: boolean;
    billing: Billing | null;
};

type Props = {
    organizations: Organization[];
    current: Current | null;
};

function greeting(): string {
    const hour = new Date().getHours();

    return hour < 12
        ? 'Good morning'
        : hour < 18
          ? 'Good afternoon'
          : 'Good evening';
}

function daysUntil(date: string): number {
    const [year, month, day] = date.split('-').map(Number);
    const target = new Date(year, month - 1, day).getTime();
    const today = new Date().setHours(0, 0, 0, 0);

    return Math.round((target - today) / 86_400_000);
}

export default function Dashboard({ organizations, current }: Props) {
    const { auth } = usePage().props;
    const firstName = auth.user?.name.split(' ')[0];

    return (
        <>
            <Head title="Dashboard" />

            <PageHeader
                title={`${greeting()}${firstName ? `, ${firstName}` : ''}`}
                description={
                    current
                        ? `Here is how ${current.displayName} is doing.`
                        : 'Your Miles Softwares account.'
                }
                actions={
                    current && (
                        <>
                            {current.canManageAccess &&
                                current.workspaceUrl && (
                                    <Button asChild variant="outline">
                                        <a
                                            href={`${current.workspaceUrl}/manage/invitations`}
                                        >
                                            <MailPlus /> Invite people
                                        </a>
                                    </Button>
                                )}
                            {current.workspaceUrl && (
                                <Button asChild>
                                    <a href={current.workspaceUrl}>
                                        Open workspace <ArrowRight />
                                    </a>
                                </Button>
                            )}
                        </>
                    )
                }
            />

            <div className="flex flex-col gap-3">
                {current?.billing && (
                    <BillingAlerts billing={current.billing} />
                )}

                {current && <Stats current={current} />}

                {current && (
                    <div className="grid gap-3 lg:grid-cols-3">
                        <Applications
                            current={current}
                            className={
                                current.billing
                                    ? 'lg:col-span-2'
                                    : 'lg:col-span-3'
                            }
                        />
                        {current.billing && (
                            <BillingPanel billing={current.billing} />
                        )}
                    </div>
                )}

                {current?.billing && (
                    <div className="grid gap-3 lg:grid-cols-2">
                        <SubscriptionsPanel billing={current.billing} />
                        <PaymentsPanel billing={current.billing} />
                    </div>
                )}

                {current?.billing && (
                    <ModulesPanel modules={current.billing.modules} />
                )}

                <Organizations organizations={organizations} />
            </div>
        </>
    );
}

function BillingAlerts({ billing }: { billing: Billing }) {
    const alerts: {
        tone: 'danger' | 'warning' | 'info';
        icon: typeof AlertTriangle;
        text: string;
        href: string;
        cta: string;
    }[] = [];

    if (billing.overdueInvoices > 0 && billing.nextDue) {
        alerts.push({
            tone: 'danger',
            icon: AlertTriangle,
            text:
                billing.overdueInvoices === 1
                    ? `Invoice ${billing.nextDue.number} was due on ${formatDate(billing.nextDue.dueOn)}. Pay it to keep your applications running.`
                    : `${billing.overdueInvoices} invoices are overdue. Pay them to keep your applications running.`,
            href: organizationBilling().url,
            cta: 'View billing',
        });
    }

    if (billing.trialEndsAt) {
        const days = daysUntil(billing.trialEndsAt);

        if (days <= 14) {
            alerts.push({
                tone: 'warning',
                icon: Clock,
                text:
                    days <= 0
                        ? 'Your trial ends today. Choose a plan to keep access.'
                        : `Your trial ends in ${days} day${days === 1 ? '' : 's'} (${formatDate(billing.trialEndsAt)}). Choose a plan to keep access.`,
                href: organizationPlans().url,
                cta: 'See plans',
            });
        }
    }

    if (billing.paymentsAwaitingConfirmation > 0) {
        alerts.push({
            tone: 'info',
            icon: ReceiptText,
            text: `${billing.paymentsAwaitingConfirmation} reported payment${billing.paymentsAwaitingConfirmation === 1 ? ' is' : 's are'} waiting for confirmation by Miles Global Softwares.`,
            href: organizationBilling().url,
            cta: 'Details',
        });
    }

    if (alerts.length === 0) {
        return null;
    }

    const tones = {
        danger: 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200',
        warning:
            'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
        info: 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-200',
    };

    return (
        <div className="flex flex-col gap-2">
            {alerts.map((alert) => (
                <div
                    key={alert.text}
                    role="status"
                    className={`flex flex-wrap items-center gap-3 rounded-lg border px-4 py-3 text-sm ${tones[alert.tone]}`}
                >
                    <alert.icon className="size-4 shrink-0" aria-hidden />
                    <p className="min-w-0 flex-1">{alert.text}</p>
                    <Link
                        href={alert.href}
                        className="font-medium underline underline-offset-4"
                    >
                        {alert.cta}
                    </Link>
                </div>
            ))}
        </div>
    );
}

function Stats({ current }: { current: Current }) {
    const balance = current.billing?.balances ?? [];

    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {current.billing ? (
                <>
                    <StatCard
                        label="Balance due"
                        icon={ReceiptText}
                        accent={
                            current.billing.overdueInvoices > 0
                                ? 'red'
                                : 'emerald'
                        }
                        value={
                            balance.length === 0
                                ? 'Nothing due'
                                : balance
                                      .map((entry) =>
                                          formatMoney(
                                              entry.amountMinor,
                                              entry.currency,
                                          ),
                                      )
                                      .join(' · ')
                        }
                        hint={
                            current.billing.nextDue
                                ? `Next due ${formatDate(current.billing.nextDue.dueOn)}`
                                : 'All invoices are paid'
                        }
                        href={organizationBilling()}
                    />
                    <StatCard
                        label="Subscriptions"
                        icon={CreditCard}
                        accent="violet"
                        value={current.billing.liveSubscriptions}
                        hint={
                            current.billing.pendingRequests > 0
                                ? `${current.billing.pendingRequests} request${current.billing.pendingRequests === 1 ? '' : 's'} pending`
                                : `${current.applications.length} application${current.applications.length === 1 ? '' : 's'} in use`
                        }
                        href={organizationSubscription()}
                    />
                </>
            ) : (
                <>
                    <StatCard
                        label="Branches"
                        icon={GitBranch}
                        accent="violet"
                        value={current.counts.branches}
                    />
                    <StatCard
                        label="Departments"
                        icon={Building2}
                        accent="slate"
                        value={current.counts.departments}
                    />
                </>
            )}
            <StatCard
                label="Members"
                icon={Users}
                value={current.counts.members}
                hint={
                    current.billing
                        ? `${current.counts.branches} branch${current.counts.branches === 1 ? '' : 'es'} · ${current.counts.departments} department${current.counts.departments === 1 ? '' : 's'}`
                        : undefined
                }
            />
            <StatCard
                label="Pending invitations"
                icon={MailPlus}
                accent="amber"
                value={current.counts.pendingInvitations}
            />
        </div>
    );
}

function Applications({
    current,
    className,
}: {
    current: Current;
    className?: string;
}) {
    return (
        <Panel className={className} aria-labelledby="applications">
            <PanelHeader
                title={<span id="applications">Applications</span>}
                description="Open an application in your workspace."
                actions={
                    current.isOwner && (
                        <Button asChild size="sm" variant="ghost">
                            <Link href={organizationPlans()}>
                                Add applications
                            </Link>
                        </Button>
                    )
                }
            />
            {current.applications.length === 0 ? (
                <EmptyState
                    icon={AppWindow}
                    title="No applications yet"
                    action={
                        current.isOwner && (
                            <Button asChild size="sm">
                                <Link href={organizationPlans()}>
                                    Browse plans
                                </Link>
                            </Button>
                        )
                    }
                >
                    {current.isOwner
                        ? 'Choose a plan to start using Miles Softwares applications.'
                        : 'No applications have been given to you yet. Ask an administrator for access.'}
                </EmptyState>
            ) : (
                <ul className="grid gap-3 p-4 sm:grid-cols-2">
                    {current.applications.map((application) => (
                        <li key={application.code}>
                            <a
                                href={application.href}
                                className="group flex h-full items-start gap-3 rounded-lg border p-3 transition-colors hover:border-primary/40 hover:bg-muted/40"
                            >
                                <span className="grid size-9 shrink-0 place-items-center rounded-md bg-primary/10 text-primary">
                                    <AppWindow className="size-4" aria-hidden />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block font-medium">
                                        {application.name}
                                    </span>
                                    {application.description && (
                                        <span className="line-clamp-2 block text-sm text-muted-foreground">
                                            {application.description}
                                        </span>
                                    )}
                                </span>
                                <ArrowRight
                                    className="mt-1 size-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
                                    aria-hidden
                                />
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

function BillingPanel({ billing }: { billing: Billing }) {
    return (
        <Panel aria-labelledby="billing">
            <PanelHeader
                title={<span id="billing">Recent invoices</span>}
                actions={
                    <Button asChild size="sm" variant="ghost">
                        <Link href={organizationBilling()}>View all</Link>
                    </Button>
                }
            />
            {billing.recentInvoices.length === 0 ? (
                <EmptyState icon={ReceiptText} title="No invoices yet">
                    Invoices from Miles Global Softwares will appear here.
                </EmptyState>
            ) : (
                <ul className="divide-y">
                    {billing.recentInvoices.map((invoice) => (
                        <li key={invoice.id}>
                            <Link
                                href={showInvoice(invoice.id)}
                                className="flex items-center gap-3 px-4 py-3 text-sm hover:bg-muted/40"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block font-medium">
                                        {invoice.number}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        Due {formatDate(invoice.dueOn)}
                                    </span>
                                </span>
                                <span className="text-right">
                                    <span className="block font-medium tabular-nums">
                                        {formatMoney(
                                            invoice.totalMinor,
                                            invoice.currency,
                                        )}
                                    </span>
                                    <StatusBadge
                                        tone={invoiceTone[invoice.status]}
                                    >
                                        {humanize(invoice.status)}
                                    </StatusBadge>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
            <div className="border-t px-4 py-3">
                <Link
                    href={organizationSettings()}
                    className="text-sm text-muted-foreground hover:text-foreground"
                >
                    Organization settings →
                </Link>
            </div>
        </Panel>
    );
}

function SubscriptionsPanel({ billing }: { billing: Billing }) {
    return (
        <Panel aria-labelledby="subscriptions">
            <PanelHeader
                title={<span id="subscriptions">Subscriptions</span>}
                description="Plans you are subscribed to."
                actions={
                    <Button asChild size="sm" variant="ghost">
                        <Link href={organizationSubscription()}>Manage</Link>
                    </Button>
                }
            />
            {billing.subscriptions.length === 0 ? (
                <EmptyState
                    icon={CreditCard}
                    title="No subscriptions yet"
                    action={
                        <Button asChild size="sm">
                            <Link href={organizationPlans()}>Browse plans</Link>
                        </Button>
                    }
                >
                    Choose a plan to start using Miles Softwares applications.
                </EmptyState>
            ) : (
                <ul className="divide-y">
                    {billing.subscriptions.map((subscription) => (
                        <li
                            key={subscription.id}
                            className="flex items-start gap-3 px-4 py-3 text-sm"
                        >
                            <span className="min-w-0 flex-1">
                                <span className="block font-medium">
                                    {subscription.plans
                                        .map((plan) => plan.name)
                                        .join(', ') || 'Subscription'}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {subscription.status === 'trialing' &&
                                    subscription.trialEndsAt
                                        ? `Trial ends ${formatDate(subscription.trialEndsAt)}`
                                        : subscription.renewsAt
                                          ? `Renews ${formatDate(subscription.renewsAt)}`
                                          : 'No renewal date'}
                                </span>
                            </span>
                            <span className="text-right">
                                {subscription.plans.map((plan) => (
                                    <span
                                        key={plan.name}
                                        className="block font-medium tabular-nums"
                                    >
                                        {formatMoney(
                                            plan.priceMinor,
                                            plan.currency,
                                        )}{' '}
                                        <span className="text-xs font-normal text-muted-foreground">
                                            {intervalLabel[plan.interval] ??
                                                plan.interval}
                                        </span>
                                    </span>
                                ))}
                                <StatusBadge
                                    tone={subscriptionTone[subscription.status]}
                                >
                                    {humanize(subscription.status)}
                                </StatusBadge>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

function PaymentsPanel({ billing }: { billing: Billing }) {
    return (
        <Panel aria-labelledby="payments">
            <PanelHeader
                title={<span id="payments">Payments</span>}
                description="Payments you reported against invoices."
                actions={
                    <Button asChild size="sm" variant="ghost">
                        <Link href={organizationBilling()}>View all</Link>
                    </Button>
                }
            />
            {billing.recentPayments.length === 0 ? (
                <EmptyState icon={Banknote} title="No payments yet">
                    {billing.nextDue
                        ? `Paid invoice ${billing.nextDue.number}? Report the payment from the invoice.`
                        : 'Payments you report against invoices will appear here.'}
                </EmptyState>
            ) : (
                <ul className="divide-y">
                    {billing.recentPayments.map((payment) => (
                        <li key={payment.id}>
                            <Link
                                href={showInvoice(payment.invoiceId)}
                                className="flex items-center gap-3 px-4 py-3 text-sm hover:bg-muted/40"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block font-medium">
                                        {methodLabel[payment.method] ??
                                            humanize(payment.method)}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {payment.invoiceNumber} · Paid{' '}
                                        {formatDate(payment.paidOn)}
                                    </span>
                                </span>
                                <span className="text-right">
                                    <span className="block font-medium tabular-nums">
                                        {formatMoney(
                                            payment.amountMinor,
                                            payment.currency,
                                        )}
                                    </span>
                                    <StatusBadge
                                        tone={paymentTone[payment.status]}
                                    >
                                        {paymentStatusLabel[payment.status] ??
                                            humanize(payment.status)}
                                    </StatusBadge>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
            {billing.nextDue && (
                <div className="border-t px-4 py-3">
                    <Link
                        href={showInvoice(billing.nextDue.id)}
                        className="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Pay {billing.nextDue.number} (
                        {formatMoney(
                            billing.nextDue.balanceMinor,
                            billing.nextDue.currency,
                        )}
                        ) →
                    </Link>
                </div>
            )}
        </Panel>
    );
}

const moduleStates: Record<
    Module['state'],
    { label: string; tone: Tone; cta: string | null }
> = {
    installed: { label: 'Installed', tone: 'success', cta: null },
    requested: { label: 'Requested', tone: 'warning', cta: null },
    available: { label: 'Available', tone: 'info', cta: 'Add' },
    on_request: { label: 'On request', tone: 'neutral', cta: 'Ask us' },
    coming_soon: { label: 'Coming soon', tone: 'neutral', cta: null },
};

function ModulesPanel({ modules }: { modules: Module[] }) {
    // Installed first, then what can be added, then what is coming.
    const order: Module['state'][] = [
        'installed',
        'requested',
        'available',
        'on_request',
        'coming_soon',
    ];
    const sorted = [...modules].sort(
        (a, b) => order.indexOf(a.state) - order.indexOf(b.state),
    );

    return (
        <Panel aria-labelledby="modules">
            <PanelHeader
                title={<span id="modules">Modules</span>}
                description="Everything you can add to your workspace."
                actions={
                    <Button asChild size="sm" variant="ghost">
                        <Link href={organizationPlans()}>See plans</Link>
                    </Button>
                }
            />
            {sorted.length === 0 ? (
                <EmptyState icon={Puzzle} title="No modules listed yet">
                    New modules will appear here as they are released.
                </EmptyState>
            ) : (
                <ul className="grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-3">
                    {sorted.map((module) => {
                        const state = moduleStates[module.state];
                        const Icon = appIcon(module.code);

                        return (
                            <li
                                key={module.code}
                                className="flex h-full items-start gap-3 rounded-lg border p-3"
                            >
                                <span className="grid size-9 shrink-0 place-items-center rounded-md bg-primary/10 text-primary">
                                    <Icon className="size-4" aria-hidden />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="flex flex-wrap items-center gap-1.5">
                                        <span className="font-medium">
                                            {module.name}
                                        </span>
                                        <StatusBadge tone={state.tone}>
                                            {state.label}
                                        </StatusBadge>
                                    </span>
                                    {(module.tagline ?? module.description) && (
                                        <span className="mt-0.5 line-clamp-2 block text-sm text-muted-foreground">
                                            {module.tagline ??
                                                module.description}
                                        </span>
                                    )}
                                    {state.cta && (
                                        <Link
                                            href={organizationPlans()}
                                            className="mt-2 inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                                        >
                                            {state.cta}{' '}
                                            <ArrowRight
                                                className="size-3.5"
                                                aria-hidden
                                            />
                                        </Link>
                                    )}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}
        </Panel>
    );
}

function Organizations({ organizations }: { organizations: Organization[] }) {
    return (
        <Panel aria-labelledby="organizations">
            <PanelHeader
                title={<span id="organizations">Your organizations</span>}
                description="Workspaces you belong to. The current one is shown above."
                actions={
                    <Button asChild size="sm" variant="outline">
                        <Link href={createOrganization()}>
                            <Plus /> New organization
                        </Link>
                    </Button>
                }
            />
            {organizations.length === 0 ? (
                <EmptyState
                    icon={Building2}
                    title="You are not part of an organization yet"
                    action={
                        <Button asChild size="sm">
                            <Link href={createOrganization()}>
                                Create an organization
                            </Link>
                        </Button>
                    }
                >
                    Create one for your business, or ask a colleague to invite
                    you.
                </EmptyState>
            ) : (
                <ul className="divide-y">
                    {organizations.map((organization) => (
                        <li
                            key={organization.id}
                            className="flex flex-wrap items-center gap-3 px-4 py-3"
                        >
                            <span className="grid size-9 shrink-0 place-items-center rounded-md bg-muted font-semibold text-muted-foreground uppercase">
                                {organization.displayName.slice(0, 1)}
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate font-medium">
                                    {organization.displayName}
                                </p>
                                <div className="mt-0.5 flex flex-wrap gap-1">
                                    {organization.isCurrent && (
                                        <StatusBadge tone="info">
                                            Current
                                        </StatusBadge>
                                    )}
                                    {organization.isOwner && (
                                        <StatusBadge>Owner</StatusBadge>
                                    )}
                                    {organization.status === 'suspended' && (
                                        <StatusBadge tone="danger">
                                            Suspended
                                        </StatusBadge>
                                    )}
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                {!organization.isCurrent && (
                                    <Form
                                        {...OrganizationController.switch.form(
                                            organization.id,
                                        )}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Make current
                                            </Button>
                                        )}
                                    </Form>
                                )}
                                {organization.workspaceUrl && (
                                    <Button asChild size="sm" variant="ghost">
                                        <a href={organization.workspaceUrl}>
                                            Open workspace
                                        </a>
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
