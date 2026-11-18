import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    ArrowUpRight,
    Building2,
    Clock,
    GitBranch,
    MailPlus,
    Plus,
    ReceiptText,
    Settings,
    ShieldCheck,
    Sparkles,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import type { Tone } from '@miles/ui/components/status-badge';
import { Button } from '@/shared/components/ui/button';
import { appIcon } from '@/shared/lib/app-icons';
import {
    formatDate,
    formatMoney,
    humanize,
    invoiceTone,
} from '@/shared/lib/billing';
import { workspaceRoutes } from '@/sites/central/lib/workspace-routes';

/*
 * The workspace home for administrators: the applications first (the reason
 * people come here), then the team and, for the owner, what is owed, and
 * what else the business could add. Subscriptions and payments have their
 * own pages under billing.
 */

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
    trialEndsAt: string | null;
    recentInvoices: {
        id: string;
        number: string;
        status: string;
        currency: string;
        totalMinor: number;
        dueOn: string;
    }[];
    /** Free trials running now, soonest end first. */
    trials: {
        code: string;
        name: string;
        endsAt: string | null;
        daysLeft: number | null;
    }[];
    modules: Module[];
};

type Module = {
    code: string;
    name: string;
    tagline: string | null;
    description: string | null;
    state:
        | 'trialing'
        | 'trialable'
        | 'installed'
        | 'requested'
        | 'available'
        | 'on_request'
        | 'coming_soon';
    /** Name of the installed product this one works best with. */
    pairsWith: string | null;
    /** How closely it pairs (lower is closer); null when unrelated. */
    rank: number | null;
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

const plural = (n: number, one: string, many = `${one}s`) =>
    `${n} ${n === 1 ? one : many}`;

export default function Dashboard({ organizations, current }: Props) {
    const { auth } = usePage().props;
    const firstName = auth.user?.name.split(' ')[0];

    return (
        <>
            <Head title="Home" />

            <div className="flex flex-col gap-4">
                <Welcome
                    title={`${greeting()}${firstName ? `, ${firstName}` : ''}`}
                    current={current}
                />

                {current?.billing && (
                    <BillingAlerts billing={current.billing} />
                )}

                {current && (
                    <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
                        <div className="flex min-w-0 flex-col gap-4">
                            <Applications current={current} />
                            {current.billing && (
                                <Recommendations
                                    modules={current.billing.modules}
                                />
                            )}
                        </div>
                        <aside className="flex flex-col gap-4">
                            <Team current={current} />
                            {current.billing && (
                                <BillingCard billing={current.billing} />
                            )}
                            <Businesses organizations={organizations} />
                        </aside>
                    </div>
                )}

                {!current && <Businesses organizations={organizations} />}
            </div>
        </>
    );
}

/** The greeting, the business, and the two things an administrator does most. */
function Welcome({
    title,
    current,
}: {
    title: string;
    current: Current | null;
}) {
    return (
        <section className="rounded-xl border bg-card p-5 sm:p-6">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <div className="min-w-0">
                    <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">
                        {title}
                    </h1>
                    <p className="mt-1 text-muted-foreground">
                        {current ? (
                            <>
                                You are in{' '}
                                <span className="font-medium text-foreground">
                                    {current.displayName}
                                </span>
                                {current.applications.length > 0 &&
                                    ` · ${plural(current.applications.length, 'application')}`}
                            </>
                        ) : (
                            'Your Tatook account.'
                        )}
                    </p>
                </div>
                {current && (
                    <div className="flex flex-wrap gap-2">
                        {current.canManageAccess && (
                            <Button asChild>
                                <Link href="/manage/invitations">
                                    <MailPlus /> Invite people
                                </Link>
                            </Button>
                        )}
                        {current.isOwner && (
                            <Button asChild variant="outline">
                                <Link href={workspaceRoutes.settings}>
                                    <Settings /> Settings
                                </Link>
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </section>
    );
}

function BillingAlerts({ billing }: { billing: Billing }) {
    const alerts: {
        tone: 'danger' | 'warning' | 'info';
        icon: LucideIcon;
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
            href: workspaceRoutes.billing,
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
                        : `Your trial ends in ${plural(days, 'day')} (${formatDate(billing.trialEndsAt)}). Choose a plan to keep access.`,
                href: workspaceRoutes.plans,
                cta: 'See plans',
            });
        }
    }

    if (billing.paymentsAwaitingConfirmation > 0) {
        alerts.push({
            tone: 'info',
            icon: ReceiptText,
            text: `${billing.paymentsAwaitingConfirmation} reported payment${billing.paymentsAwaitingConfirmation === 1 ? ' is' : 's are'} waiting for confirmation by Tatook.`,
            href: workspaceRoutes.billing,
            cta: 'Details',
        });
    }

    if (alerts.length === 0) {
        return null;
    }

    const tones = {
        danger: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200',
        warning:
            'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200',
        info: 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200',
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

/** The launcher: one large tile per application, a trial's days on its tile. */
function Applications({ current }: { current: Current }) {
    const trials = new Map(
        (current.billing?.trials ?? []).map((trial) => [trial.code, trial]),
    );

    return (
        <section aria-labelledby="applications">
            <div className="mb-3 flex items-end justify-between gap-3">
                <div>
                    <h2
                        id="applications"
                        className="text-lg font-semibold tracking-tight"
                    >
                        Your applications
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Everything opens in this workspace, with the same
                        sign-in.
                    </p>
                </div>
            </div>

            {current.applications.length === 0 ? (
                <Panel>
                    <EmptyState
                        icon={Sparkles}
                        title="No applications yet"
                        action={
                            current.isOwner && (
                                <Button asChild size="sm">
                                    <Link href={workspaceRoutes.plans}>
                                        Browse plans
                                    </Link>
                                </Button>
                            )
                        }
                    >
                        {current.isOwner
                            ? 'Choose a plan or start a free trial to begin.'
                            : 'No applications have been given to you yet. Ask an administrator for access.'}
                    </EmptyState>
                </Panel>
            ) : (
                <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {current.applications.map((application) => {
                        const Icon = appIcon(application.code);
                        const trial = trials.get(application.code);

                        return (
                            <li key={application.code}>
                                <a
                                    href={application.href}
                                    className="group flex h-full flex-col gap-3 rounded-xl border bg-card p-4 transition hover:-translate-y-0.5 hover:border-primary hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    <span className="flex items-start justify-between gap-2">
                                        <span className="grid size-11 place-items-center rounded-lg bg-primary text-primary-foreground shadow-sm">
                                            <Icon
                                                className="size-5"
                                                aria-hidden
                                            />
                                        </span>
                                        <ArrowUpRight
                                            className="size-4 text-muted-foreground transition group-hover:text-primary"
                                            aria-hidden
                                        />
                                    </span>
                                    <span className="min-w-0">
                                        <span className="block font-semibold">
                                            {application.name}
                                        </span>
                                        {application.description && (
                                            <span className="mt-0.5 line-clamp-2 block text-sm text-muted-foreground">
                                                {application.description}
                                            </span>
                                        )}
                                    </span>
                                    {trial && trial.daysLeft !== null && (
                                        <span className="mt-auto">
                                            <StatusBadge
                                                tone={
                                                    trial.daysLeft <= 7
                                                        ? 'warning'
                                                        : 'info'
                                                }
                                            >
                                                {trial.daysLeft === 0
                                                    ? 'Free trial ends today'
                                                    : `Free trial · ${plural(trial.daysLeft, 'day')} left`}
                                            </StatusBadge>
                                        </span>
                                    )}
                                </a>
                            </li>
                        );
                    })}
                    {current.isOwner && (
                        <li>
                            <Link
                                href={workspaceRoutes.plans}
                                className="flex h-full min-h-32 flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-4 text-sm text-muted-foreground transition hover:border-primary hover:text-primary"
                            >
                                <Plus className="size-5" aria-hidden />
                                Add an application
                            </Link>
                        </li>
                    )}
                </ul>
            )}
        </section>
    );
}

/** The people side of the business, each figure leading to its page. */
function Team({ current }: { current: Current }) {
    const figures: {
        label: string;
        value: number;
        icon: LucideIcon;
        href: string;
    }[] = [
        {
            label: 'Members',
            value: current.counts.members,
            icon: Users,
            href: '/members',
        },
        {
            label: 'Invitations waiting',
            value: current.counts.pendingInvitations,
            icon: MailPlus,
            href: '/manage/invitations',
        },
        {
            label: 'Branches',
            value: current.counts.branches,
            icon: GitBranch,
            href: '/manage/structure',
        },
        {
            label: 'Departments',
            value: current.counts.departments,
            icon: Building2,
            href: '/manage/structure',
        },
    ];

    return (
        <Panel aria-labelledby="team">
            <PanelHeader title={<span id="team">Your team</span>} />
            <ul className="grid grid-cols-2 gap-px border-t bg-border">
                {figures.map((figure) => (
                    <li key={figure.label} className="bg-card">
                        <Link
                            href={figure.href}
                            className="flex flex-col gap-1 p-4 hover:bg-muted"
                        >
                            <figure.icon
                                className="size-4 text-muted-foreground"
                                aria-hidden
                            />
                            <span className="text-2xl font-semibold tabular-nums">
                                {figure.value}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {figure.label}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
            {current.canManageAccess && (
                <div className="border-t px-4 py-3">
                    <Link
                        href="/manage/access"
                        className="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                    >
                        <ShieldCheck className="size-4" aria-hidden /> Who can
                        use what
                    </Link>
                </div>
            )}
        </Panel>
    );
}

/** What is owed, for the owner: the balance, the next due date, the last invoices. */
function BillingCard({ billing }: { billing: Billing }) {
    const overdue = billing.overdueInvoices > 0;

    return (
        <Panel aria-labelledby="billing">
            <PanelHeader
                title={<span id="billing">Billing</span>}
                actions={
                    <Button asChild size="sm" variant="ghost">
                        <Link href={workspaceRoutes.billing}>View all</Link>
                    </Button>
                }
            />
            <div className="border-t px-4 py-4">
                <p className="text-xs text-muted-foreground">Balance due</p>
                <p
                    className={`text-2xl font-semibold tabular-nums ${overdue ? 'text-red-700 dark:text-red-300' : ''}`}
                >
                    {billing.balances.length === 0
                        ? 'Nothing due'
                        : billing.balances
                              .map((entry) =>
                                  formatMoney(
                                      entry.amountMinor,
                                      entry.currency,
                                  ),
                              )
                              .join(' · ')}
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {billing.nextDue
                        ? `${overdue ? 'Overdue since' : 'Next due'} ${formatDate(billing.nextDue.dueOn)}`
                        : 'All invoices are paid'}
                </p>
                {billing.nextDue && (
                    <Button asChild size="sm" className="mt-3 w-full">
                        <Link
                            href={workspaceRoutes.invoice(billing.nextDue.id)}
                        >
                            Pay {billing.nextDue.number}
                        </Link>
                    </Button>
                )}
            </div>
            {billing.recentInvoices.length > 0 && (
                <ul className="divide-y border-t">
                    {billing.recentInvoices.map((invoice) => (
                        <li key={invoice.id}>
                            <Link
                                href={workspaceRoutes.invoice(invoice.id)}
                                className="flex items-center gap-3 px-4 py-2.5 text-sm hover:bg-muted"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium">
                                        {invoice.number}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        Due {formatDate(invoice.dueOn)}
                                    </span>
                                </span>
                                <span className="text-right">
                                    <span className="block tabular-nums">
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
        </Panel>
    );
}

const moduleStates: Record<
    Module['state'],
    { label: string; tone: Tone; cta: string | null }
> = {
    trialing: { label: 'On free trial', tone: 'info', cta: null },
    trialable: { label: 'Free trial', tone: 'success', cta: 'Try it free' },
    installed: { label: 'Installed', tone: 'success', cta: null },
    requested: { label: 'Requested', tone: 'warning', cta: null },
    available: { label: 'Available', tone: 'info', cta: 'Add' },
    on_request: { label: 'On request', tone: 'neutral', cta: 'Ask us' },
    coming_soon: { label: 'Coming soon', tone: 'neutral', cta: null },
};

/**
 * What the owner could add next: what can be added today first, those
 * that work with an installed application ahead. Six at most; the plans
 * page has the rest.
 */
function Recommendations({ modules }: { modules: Module[] }) {
    const order: Module['state'][] = [
        'trialable',
        'available',
        'on_request',
        'requested',
        'coming_soon',
    ];
    const sorted = modules
        .filter(
            (module) =>
                module.state !== 'installed' && module.state !== 'trialing',
        )
        .map((module, index) => ({ module, index }))
        .sort(
            (a, b) =>
                order.indexOf(a.module.state) - order.indexOf(b.module.state) ||
                (a.module.rank ?? Infinity) - (b.module.rank ?? Infinity) ||
                a.index - b.index,
        )
        .map(({ module }) => module)
        .slice(0, 6);

    if (sorted.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="recommendations">
            <div className="mb-3 flex items-end justify-between gap-3">
                <div>
                    <h2
                        id="recommendations"
                        className="text-lg font-semibold tracking-tight"
                    >
                        Grow your workspace
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Applications that work with what you already use.
                    </p>
                </div>
                <Link
                    href={workspaceRoutes.plans}
                    className="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-primary hover:underline"
                >
                    See all <ArrowRight className="size-3.5" aria-hidden />
                </Link>
            </div>
            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {sorted.map((module) => {
                    const state = moduleStates[module.state];
                    const Icon = appIcon(module.code);
                    const body = (
                        <>
                            <span className="flex items-start justify-between gap-2">
                                <span className="grid size-9 place-items-center rounded-lg bg-muted text-foreground">
                                    <Icon className="size-4" aria-hidden />
                                </span>
                                <StatusBadge tone={state.tone}>
                                    {state.label}
                                </StatusBadge>
                            </span>
                            <span className="min-w-0">
                                <span className="block font-medium">
                                    {module.name}
                                </span>
                                {(module.tagline ?? module.description) && (
                                    <span className="mt-0.5 line-clamp-2 block text-sm text-muted-foreground">
                                        {module.tagline ?? module.description}
                                    </span>
                                )}
                            </span>
                            <span className="mt-auto flex items-center justify-between gap-2 text-xs">
                                <span className="text-muted-foreground">
                                    {module.pairsWith &&
                                        `Works with ${module.pairsWith}`}
                                </span>
                                {state.cta && (
                                    <span className="inline-flex items-center gap-1 font-medium text-primary">
                                        {state.cta}
                                        <ArrowRight
                                            className="size-3.5"
                                            aria-hidden
                                        />
                                    </span>
                                )}
                            </span>
                        </>
                    );
                    const className =
                        'flex h-full flex-col gap-3 rounded-xl border bg-card p-4';

                    return (
                        <li key={module.code}>
                            {state.cta ? (
                                <Link
                                    href={
                                        module.state === 'trialable'
                                            ? workspaceRoutes.trial(module.code)
                                            : workspaceRoutes.plans
                                    }
                                    className={`${className} transition hover:border-primary hover:shadow-sm`}
                                >
                                    {body}
                                </Link>
                            ) : (
                                <div className={className}>{body}</div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

/** The member's businesses, to switch between them. */
function Businesses({ organizations }: { organizations: Organization[] }) {
    const { platform } = usePage().props;

    return (
        <Panel aria-labelledby="businesses">
            <PanelHeader
                title={<span id="businesses">Your businesses</span>}
                actions={
                    <Button asChild size="sm" variant="ghost">
                        <a href={platform.newBusinessUrl}>
                            <Plus /> New
                        </a>
                    </Button>
                }
            />
            <ul className="divide-y border-t">
                {organizations.map((organization) => {
                    const row = (
                        <>
                            <span
                                className={`grid size-8 shrink-0 place-items-center rounded-md text-sm font-semibold uppercase ${organization.isCurrent ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'}`}
                            >
                                {organization.displayName.slice(0, 1)}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-medium">
                                    {organization.displayName}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {[
                                        organization.isCurrent &&
                                            'You are here',
                                        organization.isOwner && 'Owner',
                                        organization.status === 'suspended' &&
                                            'Suspended',
                                    ]
                                        .filter(Boolean)
                                        .join(' · ') || 'Member'}
                                </span>
                            </span>
                        </>
                    );

                    return (
                        <li key={organization.id}>
                            {!organization.isCurrent &&
                            organization.workspaceUrl ? (
                                <a
                                    href={organization.workspaceUrl}
                                    className="group flex items-center gap-3 px-4 py-2.5 hover:bg-muted"
                                >
                                    {row}
                                    <ArrowRight
                                        className="size-4 text-muted-foreground transition group-hover:text-primary"
                                        aria-hidden
                                    />
                                </a>
                            ) : (
                                <div className="flex items-center gap-3 px-4 py-2.5">
                                    {row}
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </Panel>
    );
}
