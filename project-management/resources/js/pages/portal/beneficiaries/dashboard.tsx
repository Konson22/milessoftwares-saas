import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Banknote,
    CheckSquare,
    ClipboardCheck,
    FileClock,
    FolderKanban,
    HandCoins,
    Handshake,
    Users,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Meter } from '@/components/charts';
import { useCan } from '@/lib/access';
import { formatMoney } from '@/lib/money';
import { formatDate, statusLabel, statusTone } from '@/lib/status';
import {
    index as beneficiaries,
    projects as fundedProjects,
    reports as donorReports,
    tasks as fundedTasks,
} from '@/routes/beneficiaries';

type Report = {
    key: string;
    status: string;
    title: string;
    grant: string;
    beneficiary: string;
    dueOn: string;
    submittedOn: string | null;
    statusLabel: string;
    overdue: boolean;
    url: string;
};

type Payment = {
    id: string;
    grant: string;
    beneficiary: string;
    currency: string;
    dueOn: string;
    amountMinor: number;
    overdue: boolean;
    url: string;
};

type Overview = {
    connected: number;
    invited: number;
    activeGrants: number;
    toDecideCount: number;
    owedCount: number;
    paymentsCount: number;
    funding: { currency: string; amountMinor: number; receivedMinor: number }[];
    projectCount: number;
    tasksShared: boolean;
    openTasks: number;
    overdueTasks: number;
    projects: {
        id: string;
        code: string;
        name: string;
        beneficiary: string;
        status: string | null;
        percent: number | null;
        tasksShared: boolean;
        openTasks: number;
        overdueTasks: number;
    }[];
    grants: {
        id: string;
        reference: string;
        title: string;
        beneficiary: string;
        currency: string;
        amountMinor: number;
        receivedMinor: number;
        url: string;
    }[];
    toDecide: Report[];
    owed: Report[];
    payments: Payment[];
};

function percentOf(part: number, whole: number): number {
    return whole > 0 ? Math.round((part / whole) * 100) : 0;
}

/** A date that has passed says so in words, not by color alone. */
function Due({ on, overdue }: { on: string; overdue: boolean }) {
    return overdue ? (
        <span className="inline-flex items-center gap-1 text-destructive">
            <AlertTriangle className="size-3.5" aria-hidden />
            Overdue since {formatDate(on)}
        </span>
    ) : (
        <span className="text-muted-foreground">Due {formatDate(on)}</span>
    );
}

/** One short list of what waits, or a line saying nothing does. */
function WorkList({
    title,
    description,
    total,
    empty,
    children,
}: {
    title: string;
    description: string;
    total: number;
    empty: string;
    children: ReactNode;
}) {
    return (
        <Panel>
            <PanelHeader title={title} description={description} />
            {total === 0 ? (
                <p className="px-4 py-3 text-sm text-muted-foreground">
                    {empty}
                </p>
            ) : (
                <ul className="divide-y">{children}</ul>
            )}
        </Panel>
    );
}

function Row({
    href,
    title,
    detail,
    aside,
}: {
    href: string;
    title: string;
    detail: ReactNode;
    aside: ReactNode;
}) {
    return (
        <li>
            <Link
                href={href}
                className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 px-4 py-2.5 text-sm hover:bg-accent"
            >
                <span className="min-w-0">
                    <span className="font-medium">{title}</span>
                    <span className="block text-xs text-muted-foreground">
                        {detail}
                    </span>
                </span>
                <span className="text-xs">{aside}</span>
            </Link>
        </li>
    );
}

/**
 * The funding dashboard: what a donor funds and what waits for it, across every
 * beneficiary, from what each grant shares.
 */
export default function DonorDashboard({
    overview,
}: {
    overview: Overview | null;
}) {
    const { organization } = usePage().props;
    const can = useCan();
    const name = organization?.name ?? 'Your organization';

    if (overview === null) {
        return (
            <>
                <Head title="Dashboard" />
                <PageHeader title={name} />
                <Panel>
                    <EmptyState
                        icon={HandCoins}
                        title="Nothing to show you yet"
                    >
                        Your role does not include grants. Ask an administrator
                        of {name} for access.
                    </EmptyState>
                </Panel>
            </>
        );
    }

    return (
        <>
            <Head title="Dashboard" />
            <PageHeader
                title={name}
                description="What you fund and what waits for you, from what your beneficiaries share."
            />

            {overview.connected === 0 && (
                <Panel className="mb-3">
                    <EmptyState
                        icon={Handshake}
                        title={
                            overview.invited > 0
                                ? 'Waiting for your invitations to be accepted'
                                : 'Invite the organizations you fund'
                        }
                        action={
                            can('organization.manage') && (
                                <Button asChild>
                                    <Link href={beneficiaries()}>
                                        {overview.invited > 0
                                            ? 'See your invitations'
                                            : 'Invite an organization'}
                                    </Link>
                                </Button>
                            )
                        }
                    >
                        Each one runs its own workspace and chooses what every
                        grant shares with you. It all comes together here.
                    </EmptyState>
                </Panel>
            )}

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <StatCard
                    label="Beneficiaries"
                    value={overview.connected}
                    hint={
                        overview.invited > 0
                            ? `${overview.invited} invited`
                            : 'Connected'
                    }
                    icon={Users}
                    href={beneficiaries()}
                />
                <StatCard
                    label="Active grants shared"
                    value={overview.activeGrants}
                    icon={HandCoins}
                    accent="violet"
                />
                <StatCard
                    label="Projects funded"
                    value={overview.projectCount}
                    icon={FolderKanban}
                    href={fundedProjects()}
                />
                <StatCard
                    label="Open tasks"
                    value={overview.tasksShared ? overview.openTasks : '—'}
                    hint={
                        !overview.tasksShared
                            ? 'None shared yet'
                            : overview.overdueTasks > 0
                              ? `${overview.overdueTasks} overdue`
                              : 'None overdue'
                    }
                    icon={CheckSquare}
                    accent={overview.overdueTasks > 0 ? 'red' : 'slate'}
                    href={fundedTasks()}
                />
                <StatCard
                    label="Reports to decide"
                    value={overview.toDecideCount}
                    hint={
                        overview.toDecideCount > 0
                            ? 'Waiting for you'
                            : 'None waiting'
                    }
                    icon={ClipboardCheck}
                    href={donorReports()}
                    accent={overview.toDecideCount > 0 ? 'amber' : 'slate'}
                />
                <StatCard
                    label="Reports owed to you"
                    value={overview.owedCount}
                    hint={
                        overview.owed.some((report) => report.overdue)
                            ? 'Some overdue'
                            : 'None overdue'
                    }
                    icon={FileClock}
                    href={donorReports()}
                    accent={
                        overview.owed.some((report) => report.overdue)
                            ? 'red'
                            : 'slate'
                    }
                />
            </div>

            <div className="mt-3 grid gap-3 lg:grid-cols-2">
                <WorkList
                    title="Reports to decide"
                    description={`Submitted to you: accept them or ask for a revision.${overview.toDecideCount > overview.toDecide.length ? ` The oldest ${overview.toDecide.length} of ${overview.toDecideCount}.` : ''}`}
                    total={overview.toDecideCount}
                    empty="No report is waiting for your decision."
                >
                    {overview.toDecide.map((report) => (
                        <Row
                            key={report.key}
                            href={report.url}
                            title={report.title}
                            detail={`${report.beneficiary}, ${report.grant}`}
                            aside={
                                <span className="text-muted-foreground">
                                    {report.submittedOn
                                        ? `Submitted ${formatDate(report.submittedOn)}`
                                        : report.statusLabel}
                                </span>
                            }
                        />
                    ))}
                </WorkList>

                <WorkList
                    title="Reports owed to you"
                    description={`Still to come from your beneficiaries.${overview.owedCount > overview.owed.length ? ` The next ${overview.owed.length} of ${overview.owedCount}.` : ''}`}
                    total={overview.owedCount}
                    empty="No report is outstanding."
                >
                    {overview.owed.map((report) => (
                        <Row
                            key={report.key}
                            href={report.url}
                            title={report.title}
                            detail={`${report.beneficiary}, ${report.grant}${report.status === 'due' ? '' : `, ${report.statusLabel.toLowerCase()}`}`}
                            aside={
                                <Due
                                    on={report.dueOn}
                                    overdue={report.overdue}
                                />
                            }
                        />
                    ))}
                </WorkList>
            </div>

            <div className="mt-3 grid gap-3 lg:grid-cols-3">
                <div className="grid content-start gap-3 lg:col-span-2">
                    <Panel>
                        <PanelHeader
                            title="Active grants"
                            description="Amounts in each grant's own currency."
                        />
                        {overview.grants.length === 0 ? (
                            <p className="px-4 py-3 text-sm text-muted-foreground">
                                No active grant has been shared with you yet.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Grant</TableHead>
                                        <TableHead>Beneficiary</TableHead>
                                        <TableHead className="w-48">
                                            Paid
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {overview.grants.map((grant) => {
                                        const percent = percentOf(
                                            grant.receivedMinor,
                                            grant.amountMinor,
                                        );

                                        return (
                                            <TableRow key={grant.id}>
                                                <TableCell>
                                                    <Link
                                                        href={grant.url}
                                                        className="font-medium underline-offset-2 hover:underline"
                                                    >
                                                        {grant.reference}
                                                    </Link>
                                                    <span className="block text-xs text-muted-foreground">
                                                        {grant.title}
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    {grant.beneficiary}
                                                </TableCell>
                                                <TableCell>
                                                    <Meter
                                                        label={`${grant.reference} paid`}
                                                        percent={percent}
                                                    />
                                                    <span className="mt-1 block text-xs text-muted-foreground tabular-nums">
                                                        {percent}%,{' '}
                                                        {formatMoney(
                                                            grant.receivedMinor,
                                                            grant.currency,
                                                        )}
                                                    </span>
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatMoney(
                                                        grant.amountMinor,
                                                        grant.currency,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        )}
                    </Panel>

                    <Panel>
                        <PanelHeader
                            title="Projects"
                            description={`How the projects you fund stand.${overview.projectCount > overview.projects.length ? ` The first ${overview.projects.length} of ${overview.projectCount}.` : ''}`}
                            actions={
                                overview.projectCount > 0 && (
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={fundedProjects()}>
                                            See all
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                        {overview.projects.length === 0 ? (
                            <p className="px-4 py-3 text-sm text-muted-foreground">
                                No project is funded by a shared grant yet.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {overview.projects.map((project) => (
                                    <li
                                        key={project.id}
                                        className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-4 py-2.5 text-sm"
                                    >
                                        <span className="min-w-0">
                                            <span className="font-medium">
                                                {project.code} {project.name}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {project.beneficiary}
                                                {project.overdueTasks > 0 &&
                                                    `, ${project.overdueTasks} overdue ${project.overdueTasks === 1 ? 'task' : 'tasks'}`}
                                            </span>
                                        </span>
                                        {project.status ? (
                                            <span className="flex items-center gap-2 text-xs text-muted-foreground tabular-nums">
                                                {project.percent}%
                                                <StatusBadge
                                                    tone={statusTone(
                                                        project.status,
                                                    )}
                                                >
                                                    {statusLabel(
                                                        project.status,
                                                    )}
                                                </StatusBadge>
                                            </span>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                Status not shared
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                <div className="grid content-start gap-3">
                    <Panel>
                        <PanelHeader
                            title="Funding"
                            description="Active grants, by currency."
                        />
                        {overview.funding.length === 0 ? (
                            <p className="px-4 py-3 text-sm text-muted-foreground">
                                Nothing yet.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {overview.funding.map((line) => (
                                    <li
                                        key={line.currency}
                                        className="px-4 py-3"
                                    >
                                        <p className="text-sm font-medium tabular-nums">
                                            {formatMoney(
                                                line.amountMinor,
                                                line.currency,
                                            )}
                                        </p>
                                        <div className="mt-1.5">
                                            <Meter
                                                label={`${line.currency} paid`}
                                                percent={percentOf(
                                                    line.receivedMinor,
                                                    line.amountMinor,
                                                )}
                                            />
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                                            {formatMoney(
                                                line.receivedMinor,
                                                line.currency,
                                            )}{' '}
                                            paid
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <WorkList
                        title="Payments expected"
                        description={`Scheduled and not yet received.${overview.paymentsCount > overview.payments.length ? ` The next ${overview.payments.length} of ${overview.paymentsCount}.` : ''}`}
                        total={overview.paymentsCount}
                        empty="No payment is scheduled."
                    >
                        {overview.payments.map((payment) => (
                            <Row
                                key={payment.id}
                                href={payment.url}
                                title={formatMoney(
                                    payment.amountMinor,
                                    payment.currency,
                                )}
                                detail={`${payment.beneficiary}, ${payment.grant}`}
                                aside={
                                    <span className="inline-flex items-center gap-1">
                                        <Banknote
                                            className="size-3.5 text-muted-foreground"
                                            aria-hidden
                                        />
                                        <Due
                                            on={payment.dueOn}
                                            overdue={payment.overdue}
                                        />
                                    </span>
                                }
                            />
                        ))}
                    </WorkList>
                </div>
            </div>
        </>
    );
}
