import { Head } from '@inertiajs/react';
import { AlertTriangle, CheckSquare } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
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
import { formatDate, statusLabel, statusTone } from '@/lib/status';

type Task = {
    id: string;
    number: string;
    title: string;
    status: string;
    dueOn: string | null;
    completedOn: string | null;
    project: string;
    beneficiary: string;
    overdue: boolean;
};

const FILTERS: { key: string; label: string; test: (t: Task) => boolean }[] = [
    { key: 'open', label: 'Open', test: (t) => t.status !== 'done' },
    { key: 'overdue', label: 'Overdue', test: (t) => t.overdue },
    { key: 'done', label: 'Done', test: (t) => t.status === 'done' },
    { key: 'all', label: 'All', test: () => true },
];

/**
 * The tasks of funded projects, from grants that share them.
 */
export default function DonorTasks({
    tasks,
    total,
}: {
    tasks: Task[];
    total: number;
}) {
    const [filter, setFilter] = useState('open');
    const chosen = FILTERS.find((item) => item.key === filter) ?? FILTERS[0];
    const shown = tasks.filter(chosen.test);

    return (
        <>
            <Head title="Tasks" />
            <PageHeader
                title="Tasks"
                description="Work on the projects you fund, where a grant shares its tasks."
            />

            {tasks.length === 0 ? (
                <Panel>
                    <EmptyState icon={CheckSquare} title="No tasks shared yet">
                        Each beneficiary chooses whether a grant shares its
                        projects&apos; tasks. You can ask them from the
                        grant&apos;s conversation.
                    </EmptyState>
                </Panel>
            ) : (
                <>
                    <div className="mb-3 flex flex-wrap gap-2">
                        {FILTERS.map((item) => (
                            <Button
                                key={item.key}
                                size="sm"
                                variant={
                                    item.key === filter ? 'default' : 'outline'
                                }
                                aria-pressed={item.key === filter}
                                onClick={() => setFilter(item.key)}
                            >
                                {item.label} ({tasks.filter(item.test).length})
                            </Button>
                        ))}
                    </div>
                    {total > tasks.length && (
                        <p className="mb-3 text-sm text-muted-foreground">
                            Showing the first {tasks.length} of {total} tasks.
                        </p>
                    )}
                    <Panel>
                        {shown.length === 0 ? (
                            <p className="px-4 py-3 text-sm text-muted-foreground">
                                No tasks here.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Task</TableHead>
                                        <TableHead>Project</TableHead>
                                        <TableHead>Beneficiary</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Due</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {shown.map((task) => (
                                        <TableRow key={task.id}>
                                            <TableCell>
                                                <span className="font-medium">
                                                    {task.title}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {task.number}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                {task.project}
                                            </TableCell>
                                            <TableCell>
                                                {task.beneficiary}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    tone={statusTone(
                                                        task.status,
                                                    )}
                                                >
                                                    {statusLabel(task.status)}
                                                </StatusBadge>
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {task.status === 'done' ? (
                                                    <span className="text-muted-foreground">
                                                        {task.completedOn
                                                            ? `Done ${formatDate(task.completedOn)}`
                                                            : 'Done'}
                                                    </span>
                                                ) : task.dueOn === null ? (
                                                    <span className="text-muted-foreground">
                                                        No date
                                                    </span>
                                                ) : task.overdue ? (
                                                    <span className="inline-flex items-center gap-1 text-destructive">
                                                        <AlertTriangle
                                                            className="size-3.5"
                                                            aria-hidden
                                                        />
                                                        Overdue since{' '}
                                                        {formatDate(task.dueOn)}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        {formatDate(task.dueOn)}
                                                    </span>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </Panel>
                </>
            )}
        </>
    );
}
