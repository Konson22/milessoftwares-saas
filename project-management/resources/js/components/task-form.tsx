import { Form } from '@inertiajs/react';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { store as addToList } from '@/routes/lists/tasks';
import { store, update } from '@/routes/projects/tasks';
import { update as updateWork } from '@/routes/work/tasks';
import type { Option, TaskChoices } from '@/types';

export type EditableTask = {
    id: string;
    title: string;
    description: string | null;
    priority: string;
    assigneeId: string | null;
    milestoneId: string | null;
    startOn: string | null;
    dueOn: string | null;
    estimateHours: number | null;
};

/**
 * Adding a task to a project (in a given column of the board, or a given
 * list), or changing one. With several lists to choose from, the task can
 * be put in, or moved to, another of the project's lists. With no project,
 * it is a task in a list outside projects, which has no milestones.
 */
export function TaskForm({
    projectId,
    task,
    status,
    listId,
    lists = [],
    choices,
    onDone,
}: {
    projectId?: string;
    task?: EditableTask;
    status?: string;
    listId?: string;
    lists?: Option[];
    choices: TaskChoices;
    onDone?: () => void;
}) {
    const action = projectId
        ? task
            ? update.form({ project: projectId, task: task.id })
            : store.form({ project: projectId })
        : task
          ? updateWork.form(task.id)
          : addToList.form(listId ?? '');
    const id = (name: string) => `${task?.id ?? 'new'}-${name}`;

    return (
        <Form
            {...action}
            resetOnSuccess={!task}
            onSuccess={onDone}
            options={{ preserveScroll: true }}
            className="grid gap-3 sm:grid-cols-2"
        >
            {({ errors, processing }) => (
                <>
                    {status && (
                        <input type="hidden" name="status" value={status} />
                    )}
                    {listId && lists.length < 2 && (
                        <input type="hidden" name="list_id" value={listId} />
                    )}
                    <Field
                        id={id('title')}
                        label="Title"
                        error={errors.title}
                        className="sm:col-span-2"
                    >
                        <Input
                            id={id('title')}
                            name="title"
                            required
                            defaultValue={task?.title}
                            {...invalid(id('title'), errors.title)}
                        />
                    </Field>
                    <Field
                        id={id('description')}
                        label="Description"
                        error={errors.description}
                        className="sm:col-span-2"
                    >
                        <textarea
                            id={id('description')}
                            name="description"
                            rows={3}
                            defaultValue={task?.description ?? ''}
                            className="w-full border border-input bg-card px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                    </Field>
                    <Field
                        id={id('assignee_id')}
                        label="Assignee"
                        error={errors.assignee_id}
                    >
                        <Select
                            id={id('assignee_id')}
                            name="assignee_id"
                            defaultValue={task?.assigneeId ?? ''}
                            placeholder="Nobody yet"
                            options={choices.people}
                        />
                    </Field>
                    <Field
                        id={id('priority')}
                        label="Priority"
                        error={errors.priority}
                    >
                        <Select
                            id={id('priority')}
                            name="priority"
                            defaultValue={task?.priority ?? 'medium'}
                            options={choices.priorities}
                        />
                    </Field>
                    <Field
                        id={id('start_on')}
                        label="Start"
                        error={errors.start_on}
                    >
                        <Input
                            id={id('start_on')}
                            name="start_on"
                            type="date"
                            defaultValue={task?.startOn ?? ''}
                        />
                    </Field>
                    <Field id={id('due_on')} label="Due" error={errors.due_on}>
                        <Input
                            id={id('due_on')}
                            name="due_on"
                            type="date"
                            defaultValue={task?.dueOn ?? ''}
                            {...invalid(id('due_on'), errors.due_on)}
                        />
                    </Field>
                    <Field
                        id={id('estimate_hours')}
                        label="Estimate (hours)"
                        error={errors.estimate_hours}
                        hint={
                            projectId
                                ? "Weights the project's progress."
                                : undefined
                        }
                    >
                        <Input
                            id={id('estimate_hours')}
                            name="estimate_hours"
                            inputMode="decimal"
                            defaultValue={task?.estimateHours ?? ''}
                            {...invalid(
                                id('estimate_hours'),
                                errors.estimate_hours,
                            )}
                        />
                    </Field>
                    {projectId && (
                        <Field
                            id={id('milestone_id')}
                            label="Milestone"
                            error={errors.milestone_id}
                        >
                            <Select
                                id={id('milestone_id')}
                                name="milestone_id"
                                defaultValue={task?.milestoneId ?? ''}
                                placeholder="None"
                                options={choices.milestones}
                            />
                        </Field>
                    )}
                    {lists.length > 1 && (
                        <Field
                            id={id('list_id')}
                            label="List"
                            error={errors.list_id}
                        >
                            <Select
                                id={id('list_id')}
                                name="list_id"
                                defaultValue={listId ?? ''}
                                options={lists}
                            />
                        </Field>
                    )}
                    <div className="flex gap-2 sm:col-span-2">
                        <Button type="submit" disabled={processing}>
                            {task ? 'Save task' : 'Add task'}
                        </Button>
                        {onDone && (
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={onDone}
                            >
                                Cancel
                            </Button>
                        )}
                    </div>
                </>
            )}
        </Form>
    );
}
