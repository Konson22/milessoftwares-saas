import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
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
    const tabs = [
        { label: 'Overview', href: show(project.id) },
        { label: 'Board', href: board(project.id) },
        { label: 'Tasks', href: tasks(project.id) },
        { label: 'Timeline', href: timeline(project.id) },
        { label: 'Team', href: team(project.id) },
        { label: 'Time', href: time(project.id) },
        ...(project.can.finance
            ? [{ label: 'Budget', href: budget(project.id) }]
            : []),
        { label: 'Risks', href: risks(project.id) },
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

    return (
        <>
            <Head title={`${title} · ${project.code}`} />
            <PageHeader
                title={
                    <span>
                        <span className="text-muted-foreground">
                            {project.code}
                        </span>{' '}
                        {project.name}
                    </span>
                }
                description={[
                    project.client,
                    project.manager ? `Managed by ${project.manager}` : null,
                    project.dueOn ? `Due ${formatDate(project.dueOn)}` : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                badges={
                    <>
                        <Status status={project.status} />
                        {project.open && <Status status={project.health} />}
                    </>
                }
                actions={
                    <>
                        {actions}
                        {project.can.manage && (
                            <>
                                <Form
                                    {...setStatus.form(project.id)}
                                    className="flex items-center gap-1"
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
                                            className="h-7 w-32 text-xs"
                                        />
                                    )}
                                </Form>
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(project.id)}>
                                        <Pencil
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Edit
                                    </Link>
                                </Button>
                            </>
                        )}
                    </>
                }
            />
            <Progress value={project.progress} className="mb-3 max-w-md" />
            {project.open && project.healthReasons.length > 0 && (
                <p className="mb-3 text-sm text-muted-foreground">
                    {project.healthReasons.join(' · ')}
                </p>
            )}

            <nav
                aria-label="Project"
                className="mb-4 flex gap-1 overflow-x-auto border-b"
            >
                {tabs.map((tab) => (
                    <Link
                        key={tab.label}
                        href={tab.href}
                        aria-current={tab === current ? 'page' : undefined}
                        className={cn(
                            '-mb-px border-b-2 px-3 py-2 text-sm whitespace-nowrap',
                            tab === current
                                ? 'border-primary font-medium text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {tab.label}
                    </Link>
                ))}
            </nav>

            {children}
        </>
    );
}
