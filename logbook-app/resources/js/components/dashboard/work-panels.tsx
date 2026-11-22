import { Link } from '@inertiajs/react';
import { NotebookPen, Plus, RotateCcw } from 'lucide-react';
import type { ReactNode } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import type { Operations, QuickSheet } from '@/components/dashboard/types';
import { Due, TaskTable } from '@/components/work';
import { formatDateTime } from '@/lib/logbook';
import { create, index as entries, show as entry } from '@/routes/entries';
import { index as tasksPage } from '@/routes/tasks';

/** A link at the right of a panel's title. */
export function PanelLink({
    href,
    children,
}: {
    href: string;
    children: ReactNode;
}) {
    return (
        <Link
            href={href}
            className="text-sm font-medium text-primary hover:underline"
        >
            {children}
        </Link>
    );
}

/** A plain line when a list is empty. */
function Nothing({ children }: { children: ReactNode }) {
    return (
        <p className="px-4 py-6 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}

export function MyTasksPanel({ tasks }: { tasks: Operations['myTasks'] }) {
    return (
        <Panel>
            <PanelHeader
                title="My tasks"
                actions={
                    <PanelLink href={tasksPage().url}>View all tasks</PanelLink>
                }
            />
            {tasks.length > 0 ? (
                <TaskTable tasks={tasks} showAssignee={false} />
            ) : (
                <Nothing>Nothing given to you right now.</Nothing>
            )}
        </Panel>
    );
}

/** Expected entries due today that wait on the member: each opens the form. */
export function DueTodayPanel({ due }: { due: Operations['due'] }) {
    return (
        <Panel>
            <PanelHeader title={`Due today (${due.length})`} />
            {due.length > 0 ? (
                <ul className="divide-y">
                    {due.map((o) => (
                        <li key={o.id}>
                            <Link
                                href={create({
                                    query: {
                                        sheet: o.sheet.id,
                                        occurrence: o.id,
                                    },
                                })}
                                className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm hover:bg-muted"
                            >
                                <span className="min-w-0">
                                    <span className="block truncate font-medium">
                                        {o.sheet.name}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {o.sheet.code} ·{' '}
                                        <Due
                                            at={o.dueAt}
                                            overdue={o.state === 'overdue'}
                                        />
                                    </span>
                                </span>
                                <Plus
                                    className="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden
                                />
                            </Link>
                        </li>
                    ))}
                </ul>
            ) : (
                <Nothing>No scheduled entry waits on you today.</Nothing>
            )}
        </Panel>
    );
}

/** The sheets the member writes on most (or can write on), one click from a new entry. */
export function QuickEntryPanel({
    sheets,
    drafts,
}: {
    sheets: QuickSheet[];
    drafts: number;
}) {
    return (
        <Panel>
            <PanelHeader title="Quick entry" />
            {drafts > 0 && (
                <Link
                    href={entries()}
                    className="flex items-center gap-2 border-b bg-[#fcf6e3] px-4 py-2.5 text-sm hover:bg-[#f8eecf]"
                >
                    <RotateCcw
                        className="size-4 shrink-0 text-[#8a5a00]"
                        aria-hidden
                    />
                    {drafts} of your entries {drafts === 1 ? 'is' : 'are'} a
                    draft or returned for correction.
                </Link>
            )}
            {sheets.length === 0 ? (
                <EmptyState icon={NotebookPen} title="Nothing to write on yet">
                    A manager publishes log sheets in Logbooks; they appear here
                    when you can write on them.
                </EmptyState>
            ) : (
                <ul className="divide-y">
                    {sheets.map((sheet) => (
                        <li key={sheet.id}>
                            <Link
                                href={create({ query: { sheet: sheet.id } })}
                                className="flex items-center justify-between gap-3 px-4 py-2.5 hover:bg-muted"
                            >
                                <span className="min-w-0">
                                    <span className="block truncate text-sm font-medium">
                                        {sheet.name}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {sheet.code} · {sheet.logbook}
                                    </span>
                                </span>
                                <Plus
                                    className="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden
                                />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

/**
 * The member's own follow-up: recurring work coming up, expected entries
 * they let pass this week and entries returned to them. Shown only when
 * one of them has something.
 */
export function FollowUpPanels({
    upcoming,
    missed,
    returned,
}: {
    upcoming: Operations['upcoming'];
    missed: Operations['myMissed'];
    returned: Operations['returned'];
}) {
    if (upcoming.length + missed.length + returned.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-5 lg:grid-cols-3">
            <ListPanel
                title="Coming up"
                empty="No recurring task of yours is running."
                items={upcoming.map((u) => ({
                    key: u.id,
                    title: u.title,
                    meta: `Recurring · next due ${formatDateTime(u.dueAt)}`,
                }))}
            />
            <ListPanel
                title="Missed this week"
                empty="Nothing missed."
                items={missed.map((o) => ({
                    key: o.id,
                    title: `${o.sheet.code} · ${o.sheet.name}`,
                    meta: `Was due ${formatDateTime(o.dueAt)}`,
                }))}
            />
            <ListPanel
                title="Returned to you"
                empty="No entry is waiting on your corrections."
                items={returned.map((e) => ({
                    key: e.id,
                    title: e.reference ?? 'Entry',
                    meta: `${e.sheet} · returned ${formatDateTime(e.at)}`,
                    href: entry(e.id).url,
                }))}
            />
        </div>
    );
}

function ListPanel({
    title,
    empty,
    items,
}: {
    title: string;
    empty: string;
    items: { key: string; title: string; meta: string; href?: string }[];
}) {
    return (
        <Panel>
            <PanelHeader title={title} />
            {items.length > 0 ? (
                <ul className="divide-y">
                    {items.map((i) => (
                        <li key={i.key} className="px-4 py-2.5 text-sm">
                            {i.href ? (
                                <Link
                                    href={i.href}
                                    className="font-medium hover:underline"
                                >
                                    {i.title}
                                </Link>
                            ) : (
                                <span className="font-medium">{i.title}</span>
                            )}
                            <span className="block text-xs text-muted-foreground">
                                {i.meta}
                            </span>
                        </li>
                    ))}
                </ul>
            ) : (
                <Nothing>{empty}</Nothing>
            )}
        </Panel>
    );
}
