import { Link } from '@inertiajs/react';
import { ListChecks, Lock, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { ProjectFrame } from '@/components/project-frame';
import { Priority, Status } from '@/components/status';
import { TaskFilters } from '@/components/task-filters';
import type { TaskFilterValues } from '@/components/task-filters';
import { TaskForm } from '@/components/task-form';
import { formatDate } from '@/lib/status';
import { index, show } from '@/routes/projects/tasks';
import type { ProjectHeader, TaskCard, TaskChoices } from '@/types';

type Props = TaskChoices & {
    project: ProjectHeader;
    tasks: TaskCard[];
    filters: TaskFilterValues;
};

/**
 * Every task of the project in one list: open first, most urgent first.
 */
export default function Tasks({
    project,
    tasks,
    filters,
    people,
    milestones,
    priorities,
}: Props) {
    const choices = { people, milestones, priorities };
    const [adding, setAdding] = useState(false);
    const add =
        project.can.tasks && project.open ? (
            <Button onClick={() => setAdding(true)}>
                <Plus className="size-4" aria-hidden />
                Add a task
            </Button>
        ) : undefined;

    return (
        <ProjectFrame project={project} title="Tasks" actions={add}>
            {adding && (
                <Panel className="mb-4 max-w-3xl">
                    <PanelHeader title="New task" />
                    <PanelBody>
                        <TaskForm
                            projectId={project.id}
                            choices={choices}
                            onDone={() => setAdding(false)}
                        />
                    </PanelBody>
                </Panel>
            )}

            <Panel>
                <PanelToolbar>
                    <TaskFilters
                        action={index.form(project.id)}
                        filters={filters}
                        choices={choices}
                    />
                </PanelToolbar>
                {tasks.length === 0 ? (
                    <EmptyState
                        icon={ListChecks}
                        title="No tasks here"
                        action={add}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Task</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Priority</TableHead>
                                <TableHead>Assignee</TableHead>
                                <TableHead>Milestone</TableHead>
                                <TableHead>Due</TableHead>
                                <TableHead className="text-right">
                                    Estimate
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tasks.map((task) => (
                                <TableRow key={task.id}>
                                    <TableCell>
                                        <Link
                                            href={show({
                                                project: project.id,
                                                task: task.id,
                                            })}
                                            className="hover:underline"
                                        >
                                            <span className="text-muted-foreground">
                                                {task.number}
                                            </span>{' '}
                                            {task.title}
                                        </Link>
                                        {task.blocked && (
                                            <Lock
                                                className="ml-1 inline size-3 text-amber-700"
                                                aria-label="Blocked"
                                            />
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <Status status={task.status} />
                                    </TableCell>
                                    <TableCell>
                                        <Priority priority={task.priority} />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {task.assignee ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {task.milestone ?? '—'}
                                    </TableCell>
                                    <TableCell
                                        className={
                                            task.overdue
                                                ? 'font-medium text-destructive'
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        {task.dueOn
                                            ? formatDate(task.dueOn)
                                            : '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {task.estimateHours !== null
                                            ? `${task.estimateHours} h`
                                            : '—'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </ProjectFrame>
    );
}
