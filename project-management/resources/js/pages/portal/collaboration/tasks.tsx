import { Link } from '@inertiajs/react';
import { ListChecks } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import type { WorkFrame } from '@/components/partner-work';
import { WorkPage } from '@/components/partner-work';
import { Status } from '@/components/status';
import { formatDate } from '@/lib/status';

export type SharedTaskRow = {
    id: string;
    number: string;
    title: string;
    status: string;
    statusLabel: string;
    priority: string;
    dueOn: string | null;
    overdue: boolean;
    milestone: string | null;
    shared: boolean;
    givenTo: string | null;
    url: string;
};

/**
 * Shared project tasks: on the owner's side, every task shared or given to a
 * partner; on a partner's side, those it may see.
 */
export default function SharedTasks({
    frame,
    tasks,
}: {
    frame: WorkFrame;
    tasks: SharedTaskRow[];
}) {
    const owner = frame.side === 'owner';

    return (
        <WorkPage frame={frame} title="Shared tasks">
            <Panel>
                <PanelHeader
                    title="Shared tasks"
                    description={
                        owner
                            ? 'Tasks you shared with the organizations taking part, or gave to one of them. Share a task from its own page. Only you mark one done, once you have reviewed it.'
                            : 'Tasks the project owner shared with you, or gave to your organization. Move yours along and send it for review when it is done.'
                    }
                />
                {tasks.length === 0 ? (
                    <EmptyState icon={ListChecks} title="No shared tasks">
                        {owner
                            ? 'Open a task and choose who it is shared with.'
                            : 'Nothing has been shared with you yet.'}
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Task</TableHead>
                                <TableHead>Who works on it</TableHead>
                                <TableHead>Due</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tasks.map((task) => (
                                <TableRow key={task.id}>
                                    <TableCell>
                                        <Link
                                            href={task.url}
                                            className="font-medium hover:underline"
                                        >
                                            {task.number} · {task.title}
                                        </Link>
                                        {task.milestone && (
                                            <div className="text-xs text-muted-foreground">
                                                {task.milestone}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {task.givenTo ??
                                            (task.shared
                                                ? 'Shared with all'
                                                : '—')}
                                    </TableCell>
                                    <TableCell
                                        className={`text-sm ${task.overdue ? 'text-destructive' : ''}`}
                                    >
                                        {task.dueOn
                                            ? formatDate(task.dueOn)
                                            : '—'}
                                        {task.overdue && ' · overdue'}
                                    </TableCell>
                                    <TableCell>
                                        <Status status={task.status} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </WorkPage>
    );
}
