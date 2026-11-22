import { Head, router } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@/components/page-header';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Pagination } from '@/components/entries-table';
import { Check, SelectField } from '@/components/fields';
import type { Paginated } from '@/lib/logbook';
import { formatDateTime } from '@/lib/logbook';
import type { NotificationRow } from '@/lib/work';
import { index, open, readAll, settings } from '@/routes/notifications';

type Category = NotificationRow['category'];

type Props = {
    filters: { category: string; unread: boolean };
    notifications: Paginated<NotificationRow>;
    email: Record<Category, boolean>;
    push: Record<Category, boolean>;
    /** This person's phones registered for push. */
    phones: number;
    /** Whether the workspace sends push at all yet. */
    pushEnabled: boolean;
};

const CATEGORIES: { value: Category; label: string }[] = [
    { value: 'task', label: 'Tasks' },
    { value: 'entry', label: 'Entries and reviews' },
    { value: 'schedule', label: 'Scheduled entries' },
    { value: 'exception', label: 'Exceptions' },
];

export default function Notifications({
    filters,
    notifications,
    email,
    push,
    phones,
    pushEnabled,
}: Props) {
    const [choices, setChoices] = useState(email);
    const [pushChoices, setPushChoices] = useState(push);
    const go = (query: Record<string, string | boolean>) =>
        router.get(
            index().url,
            {
                ...filters,
                ...query,
                unread: (query.unread ?? filters.unread) ? 1 : undefined,
            },
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Notifications" />
            <PageHeader
                title="Notifications"
                description="Tasks, reminders, reviews, returned entries, missed schedules and exceptions."
                actions={
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.post(
                                readAll().url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Mark all read
                    </Button>
                }
            />
            <div className="grid gap-6 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <PanelToolbar>
                        <div className="flex flex-wrap items-end gap-3">
                            <SelectField
                                label="Show"
                                value={filters.category}
                                onChange={(category) => go({ category })}
                                options={CATEGORIES}
                                empty="Everything"
                                className="w-52"
                            />
                            <div className="pb-2">
                                <Check
                                    label="Unread only"
                                    checked={filters.unread}
                                    onChange={(unread) => go({ unread })}
                                />
                            </div>
                        </div>
                    </PanelToolbar>
                    {notifications.data.length === 0 ? (
                        <EmptyState icon={Bell} title="Nothing here">
                            You are told here when something needs you.
                        </EmptyState>
                    ) : (
                        <>
                            <ul className="divide-y">
                                {notifications.data.map((n) => (
                                    <li key={n.id}>
                                        <a
                                            href={open(n.id).url}
                                            className="flex gap-3 px-4 py-3 text-sm hover:bg-muted"
                                        >
                                            <span
                                                aria-label={
                                                    n.read ? 'Read' : 'Unread'
                                                }
                                                className={`mt-1.5 size-2 shrink-0 rounded-full ${
                                                    n.read
                                                        ? 'bg-slate-300'
                                                        : 'bg-primary'
                                                }`}
                                            />
                                            <span>
                                                <span
                                                    className={
                                                        n.read
                                                            ? ''
                                                            : 'font-semibold'
                                                    }
                                                >
                                                    {n.title}
                                                </span>
                                                {n.body && (
                                                    <span className="block text-muted-foreground">
                                                        {n.body}
                                                    </span>
                                                )}
                                                <span className="block text-xs text-muted-foreground">
                                                    {formatDateTime(n.at)}
                                                </span>
                                            </span>
                                        </a>
                                    </li>
                                ))}
                            </ul>
                            <Pagination page={notifications} />
                        </>
                    )}
                </Panel>
                <Panel className="self-start">
                    <PanelHeader title="Tell me about" />
                    <PanelBody className="grid gap-3">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-xs text-muted-foreground">
                                    <th className="pb-1 font-medium">
                                        Category
                                    </th>
                                    <th className="pb-1 text-center font-medium">
                                        Email
                                    </th>
                                    <th className="pb-1 text-center font-medium">
                                        Phone
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {CATEGORIES.map((c) => (
                                    <tr key={c.value}>
                                        <td className="py-1">{c.label}</td>
                                        <td className="py-1 text-center">
                                            <input
                                                type="checkbox"
                                                aria-label={`Email: ${c.label}`}
                                                checked={choices[c.value]}
                                                onChange={(e) =>
                                                    setChoices({
                                                        ...choices,
                                                        [c.value]:
                                                            e.target.checked,
                                                    })
                                                }
                                                className="size-4"
                                            />
                                        </td>
                                        <td className="py-1 text-center">
                                            <input
                                                type="checkbox"
                                                aria-label={`Phone: ${c.label}`}
                                                checked={pushChoices[c.value]}
                                                onChange={(e) =>
                                                    setPushChoices({
                                                        ...pushChoices,
                                                        [c.value]:
                                                            e.target.checked,
                                                    })
                                                }
                                                className="size-4"
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <p className="text-xs text-muted-foreground">
                            Everything appears under the bell. Only important
                            notices are emailed: a task given to you, overdue
                            work, a returned entry, a missed schedule, an
                            exception assigned to you. Phone notifications go to
                            the Miles Logbook app
                            {!pushEnabled
                                ? ' once they are switched on for this workspace.'
                                : phones > 0
                                  ? ` (${phones === 1 ? 'one phone' : `${phones} phones`} signed in).`
                                  : ': sign in on the app to receive them.'}
                        </p>
                        <div>
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.put(
                                        settings().url,
                                        { email: choices, push: pushChoices },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Save
                            </Button>
                        </div>
                    </PanelBody>
                </Panel>
            </div>
        </>
    );
}
