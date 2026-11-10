import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, FolderKanban } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Meter } from '@/components/charts';
import { formatDate, statusLabel, statusTone } from '@/lib/status';

type Project = {
    id: string;
    code: string;
    name: string;
    beneficiary: string;
    grants: { reference: string; url: string }[];
    progress: {
        status: string | null;
        percent: number | null;
        nextMilestone: { name: string; dueOn: string } | null;
        milestonesReached: number;
        milestones: number;
    };
    tasks: { open: number; done: number; overdue: number } | null;
    late: boolean;
};

function NotShared() {
    return <span className="text-sm text-muted-foreground">Not shared</span>;
}

/**
 * How the projects a donor funds stand, as far as their grants
 * share it.
 */
export default function DonorProjects({ projects }: { projects: Project[] }) {
    return (
        <>
            <Head title="Projects" />
            <PageHeader
                title="Projects"
                description="The projects your grants fund. Each beneficiary chooses what every grant shares."
            />
            <Panel>
                {projects.length === 0 ? (
                    <EmptyState icon={FolderKanban} title="No projects yet">
                        Projects appear here once a beneficiary allocates a
                        shared grant to them.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Project</TableHead>
                                <TableHead>Beneficiary</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-40">Progress</TableHead>
                                <TableHead>Next milestone</TableHead>
                                <TableHead>Tasks</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {projects.map((project) => (
                                <TableRow key={project.id}>
                                    <TableCell>
                                        <span className="font-medium">
                                            {project.code} {project.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {project.grants.map(
                                                (grant, index) => (
                                                    <span key={grant.url}>
                                                        {index > 0 && ', '}
                                                        <Link
                                                            href={grant.url}
                                                            className="underline underline-offset-2"
                                                        >
                                                            {grant.reference}
                                                        </Link>
                                                    </span>
                                                ),
                                            )}
                                        </span>
                                    </TableCell>
                                    <TableCell>{project.beneficiary}</TableCell>
                                    <TableCell>
                                        {project.progress.status ? (
                                            <StatusBadge
                                                tone={statusTone(
                                                    project.progress.status,
                                                )}
                                            >
                                                {statusLabel(
                                                    project.progress.status,
                                                )}
                                            </StatusBadge>
                                        ) : (
                                            <NotShared />
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {project.progress.percent === null ? (
                                            <NotShared />
                                        ) : (
                                            <>
                                                <Meter
                                                    label={`${project.code} progress`}
                                                    percent={
                                                        project.progress.percent
                                                    }
                                                />
                                                <span className="mt-1 block text-xs text-muted-foreground tabular-nums">
                                                    {project.progress.percent}%
                                                    done
                                                </span>
                                            </>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {project.progress.status === null ? (
                                            <NotShared />
                                        ) : project.progress.nextMilestone ? (
                                            <>
                                                {
                                                    project.progress
                                                        .nextMilestone.name
                                                }
                                                <span
                                                    className={`block text-xs ${project.late ? 'text-destructive' : 'text-muted-foreground'}`}
                                                >
                                                    {project.late && (
                                                        <AlertTriangle
                                                            className="mr-1 inline size-3.5"
                                                            aria-hidden
                                                        />
                                                    )}
                                                    {project.late
                                                        ? 'Overdue since '
                                                        : 'Due '}
                                                    {formatDate(
                                                        project.progress
                                                            .nextMilestone
                                                            .dueOn,
                                                    )}
                                                </span>
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                {project.progress.milestones > 0
                                                    ? 'All reached'
                                                    : 'None set'}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {project.tasks ? (
                                            <span className="tabular-nums">
                                                {project.tasks.open} open,{' '}
                                                {project.tasks.done} done
                                                {project.tasks.overdue > 0 && (
                                                    <span className="block text-xs text-destructive">
                                                        {project.tasks.overdue}{' '}
                                                        overdue
                                                    </span>
                                                )}
                                            </span>
                                        ) : (
                                            <NotShared />
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
