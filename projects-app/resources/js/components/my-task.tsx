import { Link } from '@inertiajs/react';
import { Priority, Status } from '@/components/status';
import { formatDate } from '@/lib/status';
import { show as task } from '@/routes/projects/tasks';

export type MyTask = {
    id: string;
    projectId: string;
    project: string;
    number: string;
    title: string;
    status: string;
    priority: string;
    dueOn: string | null;
    overdue: boolean;
};

/**
 * One of the member's tasks, linking to it.
 */
export function MyTaskRow({ item }: { item: MyTask }) {
    return (
        <li className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
            <Link
                href={task({ project: item.projectId, task: item.id })}
                className="min-w-0 flex-1 hover:underline"
            >
                <span className="text-muted-foreground">{item.number}</span>{' '}
                {item.title}
            </Link>
            <Priority priority={item.priority} />
            <Status status={item.status} />
            {item.dueOn && (
                <span
                    className={
                        item.overdue
                            ? 'text-xs font-medium text-destructive'
                            : 'text-xs text-muted-foreground'
                    }
                >
                    {item.overdue ? 'Overdue · ' : 'Due '}
                    {formatDate(item.dueOn)}
                </span>
            )}
        </li>
    );
}
