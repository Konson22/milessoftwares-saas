import { Form, Head, Link } from '@inertiajs/react';
import { FolderKanban, FolderPlus } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Select } from '@/components/field';
import { Progress, Status } from '@/components/status';
import { formatMoney } from '@/lib/money';
import { formatDate, PROJECT_STATUSES, statusLabel } from '@/lib/status';
import { create, index, show } from '@/routes/projects';

type Project = {
    id: string;
    code: string;
    name: string;
    client: string | null;
    manager: string | null;
    status: string;
    dueOn: string | null;
    progress: number;
    health: string | null;
    overdueTasks: number;
    budgetMinor: number | null;
    actualMinor: number | null;
    currency: string;
};

type Props = {
    projects: Project[];
    filters: { search: string; status: string };
    canCreate: boolean;
};

/**
 * Projects with how each stands; open ones unless a status is chosen.
 */
export default function Projects({ projects, filters, canCreate }: Props) {
    const finance = projects.some((project) => project.budgetMinor !== null);
    const add = canCreate ? (
        <Button asChild>
            <Link href={create()}>
                <FolderPlus className="size-4" aria-hidden />
                New project
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Projects" />
            <PageHeader
                title="Projects"
                description="Progress, health and spend, soonest due first."
                actions={add}
            />

            <Panel>
                <PanelToolbar>
                    <Form {...index.form()} className="flex flex-wrap gap-2">
                        <SearchField
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Code, name or client"
                            aria-label="Search projects"
                        />
                        <Select
                            name="status"
                            aria-label="Status"
                            defaultValue={filters.status}
                            placeholder="Open"
                            options={PROJECT_STATUSES.map((value) => ({
                                value,
                                label: statusLabel(value),
                            }))}
                            className="w-36"
                        />
                        <Button type="submit" variant="outline">
                            Filter
                        </Button>
                    </Form>
                </PanelToolbar>

                {projects.length === 0 ? (
                    <EmptyState
                        icon={FolderKanban}
                        title="No projects here"
                        action={add}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Project</TableHead>
                                <TableHead>Manager</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-48">Progress</TableHead>
                                <TableHead>Due</TableHead>
                                {finance && (
                                    <TableHead className="text-right">
                                        Spent / budget
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {projects.map((project) => (
                                <TableRow key={project.id}>
                                    <TableCell>
                                        <Link
                                            href={show(project.id)}
                                            className="font-medium hover:underline"
                                        >
                                            <span className="text-muted-foreground">
                                                {project.code}
                                            </span>{' '}
                                            {project.name}
                                        </Link>
                                        {project.client && (
                                            <p className="text-xs text-muted-foreground">
                                                {project.client}
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {project.manager ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap gap-1">
                                            <Status status={project.status} />
                                            {project.health && (
                                                <Status
                                                    status={project.health}
                                                />
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Progress value={project.progress} />
                                        {project.overdueTasks > 0 && (
                                            <p className="text-xs text-destructive">
                                                {project.overdueTasks} overdue
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {project.dueOn
                                            ? formatDate(project.dueOn)
                                            : '—'}
                                    </TableCell>
                                    {finance && (
                                        <TableCell
                                            className={
                                                project.budgetMinor !== null &&
                                                project.budgetMinor > 0 &&
                                                (project.actualMinor ?? 0) >
                                                    project.budgetMinor
                                                    ? 'text-right text-destructive tabular-nums'
                                                    : 'text-right tabular-nums'
                                            }
                                        >
                                            {formatMoney(
                                                project.actualMinor ?? 0,
                                                project.currency,
                                            )}
                                            <span className="text-muted-foreground">
                                                {' / '}
                                                {project.budgetMinor
                                                    ? formatMoney(
                                                          project.budgetMinor,
                                                          project.currency,
                                                      )
                                                    : '—'}
                                            </span>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
