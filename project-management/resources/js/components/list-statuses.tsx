import { Form, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, X } from 'lucide-react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Select } from '@/components/field';
import { statusLabel, TASK_STATUSES } from '@/lib/status';
import {
    destroy,
    move,
    store,
    update,
} from '@/routes/structure/lists/statuses';

export type ListStatus = {
    id: string;
    name: string;
    category: string;
    tasks: number;
};

const categories = TASK_STATUSES.map((value) => ({
    value,
    label: statusLabel(value),
}));

/**
 * The list's own names for steps of its work, each inside a fixed category,
 * which is what progress and the project board go by.
 */
export function ListStatusesPanel({
    listId,
    statuses,
}: {
    listId: string;
    statuses: ListStatus[];
}) {
    const inCategories = TASK_STATUSES.map((category) => ({
        category,
        items: statuses.filter((status) => status.category === category),
    })).filter((group) => group.items.length > 0);

    return (
        <Panel>
            <PanelHeader
                title="Statuses"
                description="Name the steps of this list's work. Each counts as one of the four categories on the project board."
            />
            {inCategories.map(({ category, items }) => (
                <div key={category} className="border-t first:border-t-0">
                    <p className="px-3 pt-2 text-xs font-medium text-muted-foreground">
                        {statusLabel(category)}
                    </p>
                    <ul>
                        {items.map((status, index) => (
                            <li key={status.id} className="px-3 py-1">
                                <Form
                                    {...update.form({
                                        list: listId,
                                        status: status.id,
                                    })}
                                    options={{ preserveScroll: true }}
                                    className="grid gap-1"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Input
                                                    name="name"
                                                    required
                                                    maxLength={64}
                                                    defaultValue={status.name}
                                                    aria-label={`Name of ${status.name}`}
                                                    className="min-w-36 flex-1"
                                                />
                                                <Select
                                                    name="category"
                                                    aria-label={`Category of ${status.name}`}
                                                    defaultValue={
                                                        status.category
                                                    }
                                                    options={categories}
                                                    className="w-32"
                                                />
                                                <span className="w-16 text-xs text-muted-foreground tabular-nums">
                                                    {status.tasks} task
                                                    {status.tasks === 1
                                                        ? ''
                                                        : 's'}
                                                </span>
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    Save
                                                </Button>
                                                {(
                                                    [
                                                        ['up', ArrowUp, 0],
                                                        [
                                                            'down',
                                                            ArrowDown,
                                                            items.length - 1,
                                                        ],
                                                    ] as const
                                                ).map(
                                                    ([
                                                        direction,
                                                        Icon,
                                                        end,
                                                    ]) => (
                                                        <Button
                                                            key={direction}
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            disabled={
                                                                index === end
                                                            }
                                                            aria-label={`Move ${status.name} ${direction}`}
                                                            onClick={() =>
                                                                router.post(
                                                                    move.url({
                                                                        list: listId,
                                                                        status: status.id,
                                                                    }),
                                                                    {
                                                                        direction,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <Icon className="size-4" />
                                                        </Button>
                                                    ),
                                                )}
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label={`Remove ${status.name}`}
                                                    onClick={() =>
                                                        router.delete(
                                                            destroy.url({
                                                                list: listId,
                                                                status: status.id,
                                                            }),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <X className="size-4" />
                                                </Button>
                                            </div>
                                            {(errors.name ||
                                                errors.category) && (
                                                <p className="text-sm text-destructive">
                                                    {errors.name ??
                                                        errors.category}
                                                </p>
                                            )}
                                        </>
                                    )}
                                </Form>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
            <PanelBody className={statuses.length > 0 ? 'border-t' : undefined}>
                <Form
                    {...store.form(listId)}
                    resetOnSuccess
                    options={{ preserveScroll: true }}
                    className="grid gap-1"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="flex flex-wrap gap-2">
                                <Input
                                    name="name"
                                    required
                                    maxLength={64}
                                    aria-label="New status name"
                                    placeholder="Add a status, e.g. Quality check"
                                    className="min-w-48 flex-1"
                                />
                                <Select
                                    name="category"
                                    aria-label="New status category"
                                    defaultValue="in_progress"
                                    options={categories}
                                    className="w-32"
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Add
                                </Button>
                            </div>
                            {(errors.name || errors.category) && (
                                <p className="text-sm text-destructive">
                                    {errors.name ?? errors.category}
                                </p>
                            )}
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}
