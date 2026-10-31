import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Clock,
    FolderKanban,
    FolderPlus,
    ListChecks,
    Wallet,
} from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { Button } from '@miles/ui/components/ui/button';
import { MyTaskRow } from '@/components/my-task';
import type { MyTask } from '@/components/my-task';
import { Progress, Status } from '@/components/status';
import { useCan } from '@/lib/access';
import { formatDate, formatMinutes } from '@/lib/status';
import { useMoney } from '@/lib/use-money';
import { myWork } from '@/routes';
import { create, index as projects, show } from '@/routes/projects';

type Props = {
    portfolio: {
        active: number;
        open: number;
        atRisk: number;
        offTrack: number;
        overdueTasks: number;
        budgetMinor: number | null;
        actualMinor: number | null;
        projects: {
            id: string;
            code: string;
            name: string;
            manager: string | null;
            status: string;
            dueOn: string | null;
            progress: number;
            health: string;
            healthReasons: string[];
        }[];
    } | null;
    myTasks: MyTask[];
    myMinutesThisWeek: number;
};

/**
 * The portfolio at a glance, worst first, and the member's own work.
 */
export default function Home({ portfolio, myTasks, myMinutesThisWeek }: Props) {
    const { product, workspace, user } = usePage().props;
    const can = useCan();
    const money = useMoney();

    return (
        <>
            <Head title="Portfolio" />
            <PageHeader
                title={`${product.name} for ${workspace?.name ?? ''}`}
                description={`Welcome, ${user?.name ?? ''}.`}
                actions={
                    can('projects.manage') && (
                        <Button asChild>
                            <Link href={create()}>
                                <FolderPlus className="size-4" aria-hidden />
                                New project
                            </Link>
                        </Button>
                    )
                }
            />

            {portfolio && (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Open projects"
                        value={portfolio.open}
                        hint={`${portfolio.active} active`}
                        icon={FolderKanban}
                        href={projects()}
                    />
                    <StatCard
                        label="At risk or off track"
                        value={portfolio.atRisk + portfolio.offTrack}
                        hint={`${portfolio.offTrack} off track`}
                        icon={AlertTriangle}
                        accent={portfolio.offTrack > 0 ? 'red' : 'amber'}
                    />
                    <StatCard
                        label="Overdue tasks"
                        value={portfolio.overdueTasks}
                        icon={ListChecks}
                        accent={portfolio.overdueTasks > 0 ? 'red' : 'slate'}
                    />
                    {portfolio.budgetMinor !== null &&
                    portfolio.actualMinor !== null ? (
                        <StatCard
                            label="Spent of open budgets"
                            value={money(portfolio.actualMinor)}
                            hint={`of ${money(portfolio.budgetMinor)}`}
                            icon={Wallet}
                            accent="violet"
                        />
                    ) : (
                        <StatCard
                            label="My hours this week"
                            value={formatMinutes(myMinutesThisWeek)}
                            icon={Clock}
                            accent="emerald"
                            href={myWork()}
                        />
                    )}
                </div>
            )}

            <div className="mt-4 grid gap-4 lg:grid-cols-[3fr_2fr]">
                {portfolio && (
                    <Panel>
                        <PanelHeader
                            title="How projects stand"
                            description="Off track first, then at risk."
                        />
                        {portfolio.projects.length === 0 ? (
                            <EmptyState
                                icon={FolderKanban}
                                title="No open projects"
                            />
                        ) : (
                            <ul className="divide-y">
                                {portfolio.projects.map((project) => (
                                    <li key={project.id} className="px-3 py-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link
                                                href={show(project.id)}
                                                className="min-w-0 flex-1 text-sm font-medium hover:underline"
                                            >
                                                <span className="text-muted-foreground">
                                                    {project.code}
                                                </span>{' '}
                                                {project.name}
                                            </Link>
                                            <Status status={project.health} />
                                        </div>
                                        <Progress
                                            value={project.progress}
                                            className="mt-1.5"
                                        />
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {[
                                                project.manager,
                                                project.dueOn
                                                    ? `Due ${formatDate(project.dueOn)}`
                                                    : null,
                                                ...project.healthReasons,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                )}

                <Panel>
                    <PanelHeader
                        title="My open tasks"
                        description={`${formatMinutes(myMinutesThisWeek)} logged this week`}
                        actions={
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={myWork()}>My work →</Link>
                            </Button>
                        }
                    />
                    {myTasks.length === 0 ? (
                        <EmptyState
                            icon={ListChecks}
                            title="Nothing assigned to you"
                        />
                    ) : (
                        <ul className="divide-y">
                            {myTasks.map((item) => (
                                <MyTaskRow key={item.id} item={item} />
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}
