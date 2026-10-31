import { Link, router } from '@inertiajs/react';
import { Lock, MessageSquare, Plus } from 'lucide-react';
import { useState } from 'react';
import type { DragEvent } from 'react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { cn } from '@miles/ui/lib/utils';
import { ProjectFrame } from '@/components/project-frame';
import { Priority } from '@/components/status';
import { TaskFilters } from '@/components/task-filters';
import type { TaskFilterValues } from '@/components/task-filters';
import { TaskForm } from '@/components/task-form';
import { formatDay } from '@/lib/status';
import { board } from '@/routes/projects';
import { move, show } from '@/routes/projects/tasks';
import type { ProjectHeader, TaskCard, TaskChoices } from '@/types';

type Props = TaskChoices & {
    project: ProjectHeader;
    columns: { status: string; label: string; tasks: TaskCard[] }[];
    filters: TaskFilterValues;
};

/**
 * The Kanban board: drag a card to another column, or within one to
 * reorder it. Done refuses tasks still waiting on others.
 */
export default function Board({
    project,
    columns,
    filters,
    people,
    milestones,
    priorities,
}: Props) {
    const choices = { people, milestones, priorities };
    const editable = project.can.tasks && project.open;
    const [dragging, setDragging] = useState<string | null>(null);
    const [over, setOver] = useState<{ status: string; index: number } | null>(
        null,
    );
    const [adding, setAdding] = useState<string | null>(null);

    const drop = (status: string, index: number) => {
        const id = dragging;
        setDragging(null);
        setOver(null);

        if (!id) {
            return;
        }

        router.post(
            move.url({ project: project.id, task: id }),
            { status, position: index },
            { preserveScroll: true },
        );
    };

    const dragOver = (event: DragEvent, status: string, index: number) => {
        if (!dragging) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        setOver({ status, index });
    };

    return (
        <ProjectFrame project={project} title="Board">
            <div className="mb-3">
                <TaskFilters
                    action={board.form(project.id)}
                    filters={filters}
                    choices={choices}
                />
            </div>

            {adding && (
                <Panel className="mb-4 max-w-3xl">
                    <PanelHeader
                        title={`New task in ${columns.find((column) => column.status === adding)?.label}`}
                    />
                    <PanelBody>
                        <TaskForm
                            projectId={project.id}
                            status={adding}
                            choices={choices}
                            onDone={() => setAdding(null)}
                        />
                    </PanelBody>
                </Panel>
            )}

            <div className="grid gap-3 overflow-x-auto pb-2 md:grid-cols-4">
                {columns.map((column) => (
                    <section
                        key={column.status}
                        aria-label={column.label}
                        className={cn(
                            'flex min-h-40 min-w-60 flex-col border bg-muted/40',
                            over?.status === column.status &&
                                'ring-2 ring-primary/40',
                        )}
                        onDragOver={(event) =>
                            dragOver(event, column.status, column.tasks.length)
                        }
                        onDrop={(event) => {
                            event.preventDefault();
                            drop(
                                column.status,
                                over?.status === column.status
                                    ? over.index
                                    : column.tasks.length,
                            );
                        }}
                    >
                        <header className="flex items-center justify-between px-3 py-2">
                            <h2 className="text-sm font-semibold">
                                {column.label}{' '}
                                <span className="font-normal text-muted-foreground">
                                    {column.tasks.length}
                                </span>
                            </h2>
                            {editable && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-label={`Add a task to ${column.label}`}
                                    onClick={() => setAdding(column.status)}
                                >
                                    <Plus className="size-4" />
                                </Button>
                            )}
                        </header>
                        <ol className="flex flex-1 flex-col gap-2 px-2 pb-2">
                            {column.tasks.map((card, index) => (
                                <li
                                    key={card.id}
                                    draggable={editable}
                                    onDragStart={(event) => {
                                        event.dataTransfer.effectAllowed =
                                            'move';
                                        setDragging(card.id);
                                    }}
                                    onDragEnd={() => {
                                        setDragging(null);
                                        setOver(null);
                                    }}
                                    onDragOver={(event) =>
                                        dragOver(event, column.status, index)
                                    }
                                    className={cn(
                                        'border bg-card p-2 text-sm shadow-xs',
                                        editable && 'cursor-grab',
                                        dragging === card.id && 'opacity-50',
                                        over?.status === column.status &&
                                            over.index === index &&
                                            dragging !== card.id &&
                                            'border-t-2 border-t-primary',
                                    )}
                                >
                                    <Link
                                        href={show({
                                            project: project.id,
                                            task: card.id,
                                        })}
                                        className="block hover:underline"
                                    >
                                        <span className="text-xs text-muted-foreground">
                                            {card.number}
                                        </span>{' '}
                                        {card.title}
                                    </Link>
                                    <div className="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                                        <Priority priority={card.priority} />
                                        {card.blocked && (
                                            <span
                                                className="inline-flex items-center gap-0.5 text-amber-700"
                                                title="Waiting on other tasks"
                                            >
                                                <Lock className="size-3" />
                                                Blocked
                                            </span>
                                        )}
                                        {card.dueOn && (
                                            <span
                                                className={
                                                    card.overdue
                                                        ? 'font-medium text-destructive'
                                                        : undefined
                                                }
                                            >
                                                {formatDay(card.dueOn)}
                                            </span>
                                        )}
                                        {card.comments > 0 && (
                                            <span className="inline-flex items-center gap-0.5">
                                                <MessageSquare className="size-3" />
                                                {card.comments}
                                            </span>
                                        )}
                                        <span className="ml-auto truncate">
                                            {card.assignee ?? 'Unassigned'}
                                        </span>
                                    </div>
                                    {editable && (
                                        // Without drag and drop (keyboards, touch): move by status.
                                        <select
                                            aria-label={`Move ${card.number}`}
                                            value={card.status}
                                            onChange={(event) =>
                                                router.post(
                                                    move.url({
                                                        project: project.id,
                                                        task: card.id,
                                                    }),
                                                    {
                                                        status: event.target
                                                            .value,
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className="mt-2 w-full border border-input bg-card px-1 py-0.5 text-xs md:sr-only md:focus:not-sr-only"
                                        >
                                            {columns.map((target) => (
                                                <option
                                                    key={target.status}
                                                    value={target.status}
                                                >
                                                    {target.label}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </section>
                ))}
            </div>
        </ProjectFrame>
    );
}
