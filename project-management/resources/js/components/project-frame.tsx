import { Form, Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CalendarDays,
    ChartGantt,
    Clock,
    GitPullRequestArrow,
    Layers,
    LayoutDashboard,
    ListChecks,
    PackageCheck,
    Pencil,
    Receipt,
    ShieldAlert,
    SquareKanban,
    UserRound,
    Users,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { cn } from '@miles/ui/lib/utils';
import { Select } from '@/components/field';
import { Progress, Status } from '@/components/status';
import { formatDate, PROJECT_STATUSES, statusLabel } from '@/lib/status';
import {
    board,
    budget,
    edit,
    show,
    status as setStatus,
    team,
    time,
    timeline,
} from '@/routes/projects';
import { index as changes } from '@/routes/projects/changes';
import { index as deliverables } from '@/routes/projects/deliverables';
import { index as expenses } from '@/routes/projects/expenses';
import { index as risks } from '@/routes/projects/risks';
import { index as tasks } from '@/routes/projects/tasks';
import type { ProjectHeader } from '@/types';

/**
 * Every page of a project: its name and how it stands, the tabs, then the
 * page itself.
 */
export function ProjectFrame({
    project,
    title,
    actions,
    children,
}: {
    project: ProjectHeader;
    /** The page within the project, for the browser tab. */
    title: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    const { url } = usePage();
    const nav = useRef<HTMLElement>(null);

    // On a narrow screen the tabs scroll sideways: keep the current one in view.
    useEffect(() => {
        const active = nav.current?.querySelector<HTMLElement>(
            '[aria-current="page"]',
        );

        if (nav.current && active) {
            nav.current.scrollLeft =
                active.offsetLeft - nav.current.offsetLeft - 8;
        }
    }, [url]);
    const tabs = [
        {
            label: 'Overview',
            icon: LayoutDashboard,
            href: show({ project: project.id }),
        },
        {
            label: 'Board',
            icon: SquareKanban,
            href: board({ project: project.id }),
        },
        {
            label: 'Tasks',
            icon: ListChecks,
            href: tasks({ project: project.id }),
        },
        {
            label: 'Timeline',
            icon: ChartGantt,
            href: timeline({ project: project.id }),
        },
        {
            label: 'Team',
            icon: Users,
            href: team({ project: project.id }),
        },
        {
            label: 'Time',
            icon: Clock,
            href: time({ project: project.id }),
        },
        ...(project.can.finance
            ? [
                  {
                      label: 'Budget',
                      icon: Wallet,
                      href: budget({ project: project.id }),
                  },
              ]
            : []),
        ...(project.can.finance || project.can.requestSpending
            ? [
                  {
                      label: 'Spending',
                      icon: Receipt,
                      href: expenses({ project: project.id }),
                  },
              ]
            : []),
        {
            label: 'Risks',
            icon: ShieldAlert,
            href: risks({ project: project.id }),
        },
        {
            label: 'Deliverables',
            icon: PackageCheck,
            href: deliverables({ project: project.id }),
        },
        {
            label: 'Changes',
            icon: GitPullRequestArrow,
            href: changes({ project: project.id }),
        },
    ];
    const path = url.split('?')[0];
    const current = tabs.reduce<(typeof tabs)[number] | null>(
        (best, tab) =>
            path.startsWith(tab.href.url) &&
            tab.href.url.length > (best?.href.url.length ?? 0)
                ? tab
                : best,
        null,
    );

    const details = [
        { icon: Building2, text: project.client },
        { icon: Layers, text: project.programme },
        {
            icon: UserRound,
            text: project.manager ? `Managed by ${project.manager}` : null,
        },
        {
            icon: CalendarDays,
            text: project.dueOn ? `Due ${formatDate(project.dueOn)}` : null,
        },
    ].filter((detail) => detail.text);
    const reasons = project.open ? project.healthReasons : [];

    return (
        <>
            <Head title={`${title} · ${project.code}`} />
            <header className="mb-4 border bg-card">
                <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3 px-4 pt-3.5 pb-3">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span className="bg-muted px-1.5 py-0.5 text-xs font-semibold text-muted-foreground tabular-nums">
                                {project.code}
                            </span>
                            <h1 className="text-xl font-semibold tracking-tight">
                                {project.name}
                            </h1>
                            <Status status={project.status} />
                            {project.open && <Status status={project.health} />}
                        </div>
                        {details.length > 0 && (
                            <ul className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                                {details.map(({ icon: Icon, text }) => (
                                    <li
                                        key={text}
                                        className="flex items-center gap-1.5"
                                    >
                                        <Icon
                                            className="size-3.5 shrink-0"
                                            aria-hidden
                                        />
                                        {text}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {project.can.manage && (
                            <>
                                <Form
                                    {...setStatus.form({ project: project.id })}
                                    className="flex items-center"
                                >
                                    {({ processing }) => (
                                        <Select
                                            name="status"
                                            aria-label="Project status"
                                            defaultValue={project.status}
                                            disabled={processing}
                                            options={PROJECT_STATUSES.map(
                                                (value) => ({
                                                    value,
                                                    label: statusLabel(value),
                                                }),
                                            )}
                                            onChange={(event) =>
                                                event.currentTarget.form?.requestSubmit()
                                            }
                                            className="w-32 text-sm"
                                        />
                                    )}
                                </Form>
                                <Button variant="outline" asChild>
                                    <Link href={edit({ project: project.id })}>
                                        <Pencil
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Edit
                                    </Link>
                                </Button>
                            </>
                        )}
                        {actions}
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-x-6 gap-y-1.5 px-4 pb-3">
                    <Progress
                        value={project.progress}
                        className="w-full max-w-72"
                    />
                    {reasons.length > 0 && (
                        <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                            <AlertTriangle
                                className="size-3.5 shrink-0 text-[var(--viz-warning)]"
                                aria-hidden
                            />
                            {reasons.join(' · ')}
                        </p>
                    )}
                </div>

                <nav
                    ref={nav}
                    aria-label="Project"
                    className="flex [scrollbar-width:none] gap-0.5 overflow-x-auto overflow-y-hidden border-t px-2"
                >
                    {tabs.map(({ label, icon: Icon, href }) => {
                        const active = label === current?.label;

                        return (
                            <Link
                                key={label}
                                href={href}
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'flex shrink-0 items-center gap-1.5 border-b-2 px-2.5 py-2 text-sm whitespace-nowrap transition-colors focus-visible:bg-muted focus-visible:outline-none',
                                    active
                                        ? 'border-primary font-medium text-foreground'
                                        : 'border-transparent text-muted-foreground hover:bg-muted/60 hover:text-foreground',
                                )}
                            >
                                <Icon
                                    className={cn(
                                        'size-4',
                                        active && 'text-primary',
                                    )}
                                    aria-hidden
                                />
                                {label}
                            </Link>
                        );
                    })}
                </nav>
            </header>

            {children}
        </>
    );
}
