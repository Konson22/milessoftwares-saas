import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Building2,
    CalendarDays,
    ChevronRight,
    ClipboardCheck,
    FilePlus2,
    ListTodo,
    Lock,
    NotebookPen,
    Plus,
    Settings,
    Sparkles,
    TriangleAlert,
    UserPlus,
    Users,
    X,
} from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { ActionButton } from '@/components/actions';
import { useConfirm } from '@/components/dialogs';
import { Check, SelectField, Status, TextField } from '@/components/fields';
import { LogbookForm, type DepartmentOption } from '@/components/logbook-form';
import { LogbookIcon } from '@/components/logbook-icon';
import { PageHeader } from '@/components/page-header';
import { useCan } from '@/lib/access';
import type { Option } from '@/lib/logbook';
import { formatDateTime, ROLE_LABELS } from '@/lib/logbook';
import { create as newEntry } from '@/routes/entries';
import { index as exceptionsIndex } from '@/routes/exceptions';
import { archive, restore, update } from '@/routes/logbooks';
import * as accessRoutes from '@/routes/logbooks/access';
import { index as reportsIndex } from '@/routes/reports';
import { index as reviewsIndex } from '@/routes/reviews';
import {
    show as sheetShow,
    starters,
    store as storeSheet,
} from '@/routes/sheets';
import { index as tasksIndex } from '@/routes/tasks';

type Sheet = {
    id: string;
    name: string;
    code: string;
    description: string | null;
    status: string;
    reviewRequired: boolean;
    version: number | null;
    hasDraft: boolean;
    entries: number;
    lastEntryAt: string | null;
    canWrite: boolean;
};

type Props = {
    logbook: {
        id: string;
        name: string;
        description: string | null;
        icon: string | null;
        restricted: boolean;
        status: string;
        createdAt: string;
        creator: string;
        departmentId: string | null;
        department: string | null;
    };
    sheets: Sheet[];
    /** The logbook's access list. Not `access`: that shared prop holds the member's own permissions. */
    grants:
        | {
              id: string;
              user: string;
              email: string;
              role: string;
              sheet: string | null;
          }[]
        | null;
    members: Option[];
    canManage: boolean;
    departments: DepartmentOption[];
    canFileUnderWorkspace: boolean;
    hasEntries: boolean;
    stats: {
        awaiting: number;
        returned: number;
        lastWeek: number;
        openExceptions: number;
        seriousExceptions: number;
        overdueTasks: number;
    };
};

/* Solid tints for role chips (no transparency). */
const ROLE_TONES: Record<string, { bg: string; fg: string }> = {
    contributor: { bg: '#e3f3ee', fg: '#0a5c48' },
    reviewer: { bg: '#e8eefb', fg: '#1d4396' },
    viewer: { bg: '#eef0f2', fg: '#3d4651' },
};

const initials = (name: string) =>
    name
        .split(/\s+/)
        .map((w) => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();

/**
 * One logbook: what is happening in it (recent entries, waiting reviews,
 * open exceptions, overdue tasks), its log sheets, and who works on it.
 */
export default function LogbookShow({
    logbook,
    sheets,
    grants,
    members,
    canManage,
    departments,
    canFileUnderWorkspace,
    stats,
}: Props) {
    const [editing, setEditing] = useState(false);
    const [adding, setAdding] = useState(false);
    const can = useCan();
    const active = logbook.status === 'active';
    const totalEntries = sheets.reduce((a, s) => a + s.entries, 0);
    const writable = sheets.filter((s) => s.canWrite);
    const showAside = grants !== null;

    return (
        <>
            <Head title={logbook.name} />
            <PageHeader
                title={logbook.name}
                actions={
                    <>
                        {can('logbook.reports.view') && (
                            <Button asChild variant="outline">
                                <Link
                                    href={reportsIndex({
                                        query: { logbook: logbook.id },
                                    })}
                                >
                                    <BarChart3 /> Report
                                </Link>
                            </Button>
                        )}
                        {canManage && (
                            <Button
                                variant={editing ? 'secondary' : 'outline'}
                                onClick={() => setEditing(!editing)}
                                aria-expanded={editing}
                            >
                                <Settings /> Settings
                            </Button>
                        )}
                        {writable.length === 1 && (
                            <Button asChild>
                                <Link
                                    href={newEntry({
                                        query: { sheet: writable[0].id },
                                    })}
                                >
                                    <NotebookPen /> New entry
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid gap-5">
                {/* Who and what this logbook is. */}
                <section className="flex flex-col gap-4 rounded-lg border bg-card p-5 shadow-[0_1px_2px_#e3e3e3] sm:flex-row sm:items-start">
                    <span className="grid size-14 shrink-0 place-items-center rounded-xl bg-[#e3f3ee] text-[#0a5c48]">
                        <LogbookIcon icon={logbook.icon} className="size-7" />
                    </span>
                    <div className="grid min-w-0 flex-1 gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <Status value={logbook.status} />
                            <Chip icon={Building2}>
                                {logbook.department ?? 'Whole workspace'}
                            </Chip>
                            {logbook.restricted && (
                                <Chip icon={Lock}>Members only</Chip>
                            )}
                        </div>
                        <p
                            className={`text-sm ${logbook.description ? 'text-foreground' : 'text-muted-foreground'}`}
                        >
                            {logbook.description ?? 'No description yet.'}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Created by {logbook.creator} ·{' '}
                            {formatDateTime(logbook.createdAt)}
                        </p>
                    </div>
                    <dl className="grid shrink-0 grid-cols-3 gap-px overflow-hidden rounded-lg border bg-border text-center">
                        <Fact label="Log sheets" value={sheets.length} />
                        <Fact label="Entries" value={totalEntries} />
                        <Fact
                            label="Members"
                            value={grants === null ? '—' : grants.length}
                        />
                    </dl>
                </section>

                {/* What may need someone's attention. */}
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat
                        icon={CalendarDays}
                        label="Entries in the last 7 days"
                        value={stats.lastWeek}
                        tone="neutral"
                    />
                    <Stat
                        icon={ClipboardCheck}
                        label="Waiting for review"
                        value={stats.awaiting}
                        note={
                            stats.returned > 0
                                ? `${stats.returned} returned for correction`
                                : undefined
                        }
                        tone={stats.awaiting > 0 ? 'watch' : 'clear'}
                        href={
                            can('logbook.entries.review')
                                ? reviewsIndex().url
                                : undefined
                        }
                    />
                    <Stat
                        icon={TriangleAlert}
                        label="Open exceptions"
                        value={stats.openExceptions}
                        note={
                            stats.seriousExceptions > 0
                                ? `${stats.seriousExceptions} high or critical`
                                : undefined
                        }
                        tone={
                            stats.seriousExceptions > 0
                                ? 'alert'
                                : stats.openExceptions > 0
                                  ? 'watch'
                                  : 'clear'
                        }
                        href={exceptionsIndex().url}
                    />
                    <Stat
                        icon={ListTodo}
                        label="Overdue tasks"
                        value={stats.overdueTasks}
                        tone={stats.overdueTasks > 0 ? 'alert' : 'clear'}
                        href={tasksIndex().url}
                    />
                </div>

                {editing && canManage && (
                    <Card
                        title="Logbook settings"
                        actions={
                            <>
                                {active ? (
                                    <ActionButton
                                        url={archive(logbook.id).url}
                                        label="Archive logbook"
                                        variant="outline"
                                        confirm="Archive this logbook? Its records are kept and stay readable; nobody can write new entries."
                                    />
                                ) : (
                                    <ActionButton
                                        url={restore(logbook.id).url}
                                        label="Restore logbook"
                                        variant="outline"
                                    />
                                )}
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Close settings"
                                    onClick={() => setEditing(false)}
                                >
                                    <X />
                                </Button>
                            </>
                        }
                    >
                        <div className="p-4">
                            <LogbookForm
                                initial={logbook}
                                departments={departments}
                                canFileUnderWorkspace={canFileUnderWorkspace}
                                url={update(logbook.id).url}
                                method="put"
                                submitLabel="Save"
                                onDone={() => setEditing(false)}
                            />
                        </div>
                    </Card>
                )}

                <div
                    className={`grid gap-5 ${showAside ? 'lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start' : ''}`}
                >
                    <Card
                        title="Log sheets"
                        count={sheets.length}
                        actions={
                            canManage &&
                            active && (
                                <>
                                    <Button asChild variant="ghost" size="sm">
                                        <Link href={starters(logbook.id)}>
                                            <Sparkles /> From a template
                                        </Link>
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant={
                                            adding ? 'secondary' : 'default'
                                        }
                                        onClick={() => setAdding(!adding)}
                                        aria-expanded={adding}
                                    >
                                        <Plus /> New log sheet
                                    </Button>
                                </>
                            )
                        }
                    >
                        {adding && (
                            <NewSheet
                                logbookId={logbook.id}
                                onCancel={() => setAdding(false)}
                            />
                        )}
                        {sheets.length === 0 ? (
                            <div className="grid justify-items-center gap-3 px-6 py-10 text-center">
                                <span className="grid size-12 place-items-center rounded-xl bg-[#eef0f2] text-[#3d4651]">
                                    <FilePlus2 className="size-6" />
                                </span>
                                <p className="text-sm font-semibold">
                                    No log sheets yet
                                </p>
                                <p className="max-w-sm text-sm text-muted-foreground">
                                    A log sheet is the form people fill in: a
                                    daily activity log, a generator log, a
                                    vehicle log.
                                </p>
                                {canManage && active && (
                                    <Button asChild>
                                        <Link href={starters(logbook.id)}>
                                            <Sparkles /> Start from a template
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <ul className="divide-y divide-[#ebebeb]">
                                {sheets.map((s) => (
                                    <SheetRow
                                        key={s.id}
                                        sheet={s}
                                        canManage={canManage}
                                    />
                                ))}
                            </ul>
                        )}
                    </Card>

                    {grants !== null && (
                        <MembersCard
                            logbookId={logbook.id}
                            access={grants}
                            members={members}
                            sheets={sheets.map((s) => ({
                                value: s.id,
                                label: `${s.code} · ${s.name}`,
                            }))}
                        />
                    )}
                </div>
            </div>
        </>
    );
}

function Chip({
    icon: Icon,
    children,
}: {
    icon: ComponentType<{ className?: string }>;
    children: ReactNode;
}) {
    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-[#f1f3f5] px-2 py-0.5 text-xs font-medium text-[#3d4651]">
            <Icon className="size-3" />
            {children}
        </span>
    );
}

function Fact({ label, value }: { label: string; value: number | string }) {
    return (
        <div className="bg-card px-4 py-2.5">
            <dd className="text-xl font-semibold tabular-nums">
                {typeof value === 'number' ? value.toLocaleString() : value}
            </dd>
            <dt className="text-[11px] text-muted-foreground">{label}</dt>
        </div>
    );
}

const TONES = {
    neutral: { bg: '#eef0f2', fg: '#3d4651' },
    clear: { bg: '#e6f2e6', fg: '#0b650a' },
    watch: { bg: '#fcefdc', fg: '#8a4b00' },
    alert: { bg: '#fbe5e7', fg: '#a10d19' },
} as const;

function Stat({
    icon: Icon,
    label,
    value,
    note,
    tone,
    href,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    value: number;
    note?: string;
    tone: keyof typeof TONES;
    href?: string;
}) {
    const t = TONES[tone];
    const body = (
        <>
            <span
                className="grid size-10 shrink-0 place-items-center rounded-lg"
                style={{ backgroundColor: t.bg, color: t.fg }}
            >
                <Icon className="size-5" />
            </span>
            <span className="grid min-w-0 flex-1">
                <span className="truncate text-xs font-medium text-muted-foreground">
                    {label}
                </span>
                <span className="text-2xl leading-tight font-semibold tabular-nums">
                    {value.toLocaleString()}
                </span>
                <span className="truncate text-xs text-muted-foreground">
                    {note ?? (value === 0 ? 'Nothing waiting' : ' ')}
                </span>
            </span>
            {href && (
                <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
            )}
        </>
    );
    const className =
        'flex items-center gap-3 rounded-lg border bg-card p-4 shadow-[0_1px_2px_#e3e3e3]';

    return href ? (
        <Link href={href} className={`${className} hover:border-[#b9c0c7]`}>
            {body}
        </Link>
    ) : (
        <div className={className}>{body}</div>
    );
}

function Card({
    title,
    count,
    actions,
    children,
}: {
    title: string;
    count?: number;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="overflow-hidden rounded-lg border bg-card shadow-[0_1px_2px_#e3e3e3]">
            <header className="flex flex-wrap items-center justify-between gap-2 border-b border-[#ebebeb] px-4 py-3">
                <h2 className="text-sm font-semibold">
                    {title}
                    {count !== undefined && (
                        <span className="ml-1.5 font-normal text-muted-foreground tabular-nums">
                            {count}
                        </span>
                    )}
                </h2>
                {actions && (
                    <div className="flex items-center gap-2">{actions}</div>
                )}
            </header>
            {children}
        </section>
    );
}

function SheetRow({
    sheet: s,
    canManage,
}: {
    sheet: Sheet;
    canManage: boolean;
}) {
    return (
        <li className="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center">
            <Link
                href={sheetShow(s.id)}
                className="group flex min-w-0 flex-1 items-center gap-3"
            >
                <span className="grid h-10 min-w-12 shrink-0 place-items-center rounded-lg bg-[#16202c] px-2 font-mono text-xs font-semibold text-white">
                    {s.code}
                </span>
                <span className="grid min-w-0 gap-0.5">
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="truncate font-medium group-hover:underline">
                            {s.name}
                        </span>
                        {s.status !== 'active' && <Status value={s.status} />}
                    </span>
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                        <span>
                            {s.version ? `v${s.version}` : 'Not published'}
                        </span>
                        {s.hasDraft && canManage && (
                            <span className="font-medium text-[#8a4b00]">
                                draft changes
                            </span>
                        )}
                        <span aria-hidden>·</span>
                        <span>
                            {s.reviewRequired ? 'Reviewed' : 'No review step'}
                        </span>
                        {s.description && (
                            <>
                                <span aria-hidden>·</span>
                                <span className="truncate">
                                    {s.description}
                                </span>
                            </>
                        )}
                    </span>
                </span>
            </Link>
            <div className="flex items-center gap-4 sm:justify-end">
                <span className="grid text-right">
                    <span className="text-sm font-semibold tabular-nums">
                        {s.entries.toLocaleString()}{' '}
                        <span className="font-normal text-muted-foreground">
                            {s.entries === 1 ? 'entry' : 'entries'}
                        </span>
                    </span>
                    <span className="text-xs whitespace-nowrap text-muted-foreground">
                        {s.lastEntryAt
                            ? `Last ${formatDateTime(s.lastEntryAt)}`
                            : 'Nothing written yet'}
                    </span>
                </span>
                {s.canWrite && (
                    <Button asChild size="sm" variant="outline">
                        <Link href={newEntry({ query: { sheet: s.id } })}>
                            <NotebookPen /> New entry
                        </Link>
                    </Button>
                )}
            </div>
        </li>
    );
}

function NewSheet({
    logbookId,
    onCancel,
}: {
    logbookId: string;
    onCancel: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        name: '',
        code: '',
        review_required: true,
    });
    const [busy, setBusy] = useState(false);

    return (
        <form
            className="grid gap-4 border-b border-[#ebebeb] bg-[#f8f9fa] p-4"
            onSubmit={(e) => {
                e.preventDefault();
                setBusy(true);
                router.post(storeSheet(logbookId).url, data, {
                    onFinish: () => setBusy(false),
                });
            }}
        >
            <div>
                <p className="text-sm font-semibold">New log sheet</p>
                <p className="text-xs text-muted-foreground">
                    Name it and give it a short code; you add its fields next.
                </p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <TextField
                    label="Name"
                    value={data.name}
                    onChange={(name) => setData({ ...data, name })}
                    error={errors.name}
                    placeholder="Generator Operation Log"
                    required
                />
                <TextField
                    label="Code"
                    value={data.code}
                    onChange={(code) =>
                        setData({ ...data, code: code.toUpperCase() })
                    }
                    error={errors.code}
                    hint="Starts every reference: GEN-2026-000001"
                    placeholder="GEN"
                    required
                />
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <Check
                    label="Entries are reviewed by a supervisor"
                    checked={data.review_required}
                    onChange={(review_required) =>
                        setData({ ...data, review_required })
                    }
                />
                <div className="flex gap-2">
                    <Button type="button" variant="ghost" onClick={onCancel}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={busy}>
                        Create and add fields
                    </Button>
                </div>
            </div>
        </form>
    );
}

function MembersCard({
    logbookId,
    access,
    members,
    sheets,
}: {
    logbookId: string;
    access: NonNullable<Props['grants']>;
    members: Option[];
    sheets: Option[];
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const ask = useConfirm();
    const [open, setOpen] = useState(access.length === 0);
    const [data, setData] = useState({
        user_id: '',
        role: 'contributor',
        log_sheet_id: '',
    });
    const [busy, setBusy] = useState(false);

    return (
        <Card
            title="Members"
            count={access.length}
            actions={
                !open && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setOpen(true)}
                    >
                        <UserPlus /> Add
                    </Button>
                )
            }
        >
            {open && (
                <form
                    className="grid gap-3 border-b border-[#ebebeb] bg-[#f8f9fa] p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        setBusy(true);
                        router.post(accessRoutes.store(logbookId).url, data, {
                            preserveScroll: true,
                            onSuccess: () => setData({ ...data, user_id: '' }),
                            onFinish: () => setBusy(false),
                        });
                    }}
                >
                    <SelectField
                        label="Person"
                        value={data.user_id}
                        onChange={(user_id) => setData({ ...data, user_id })}
                        options={members}
                        empty="Choose…"
                        error={errors.user_id}
                        hint="People who have opened Logbook in this workspace."
                    />
                    <div className="grid grid-cols-2 gap-3">
                        <SelectField
                            label="As"
                            value={data.role}
                            onChange={(role) => setData({ ...data, role })}
                            options={Object.entries(ROLE_LABELS).map(
                                ([value, label]) => ({ value, label }),
                            )}
                            error={errors.role}
                        />
                        <SelectField
                            label="On"
                            value={data.log_sheet_id}
                            onChange={(log_sheet_id) =>
                                setData({ ...data, log_sheet_id })
                            }
                            options={sheets}
                            empty="Whole logbook"
                            error={errors.log_sheet_id}
                        />
                    </div>
                    <div className="flex justify-end gap-2">
                        {access.length > 0 && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setOpen(false)}
                            >
                                Done
                            </Button>
                        )}
                        <Button
                            type="submit"
                            size="sm"
                            disabled={busy || data.user_id === ''}
                        >
                            <UserPlus /> Add member
                        </Button>
                    </div>
                </form>
            )}

            {access.length === 0 ? (
                <div className="grid justify-items-center gap-2 px-4 py-8 text-center">
                    <Users className="size-6 text-muted-foreground" />
                    <p className="text-sm text-muted-foreground">
                        Nobody is a member yet, so only managers see this
                        logbook.
                    </p>
                </div>
            ) : (
                <ul className="max-h-[28rem] divide-y divide-[#f0f0f0] overflow-y-auto">
                    {access.map((g) => {
                        const tone = ROLE_TONES[g.role] ?? ROLE_TONES.viewer;
                        return (
                            <li
                                key={g.id}
                                className="flex items-center gap-3 px-4 py-2.5"
                            >
                                <span className="grid size-8 shrink-0 place-items-center rounded-full bg-[#eef0f2] text-[11px] font-semibold text-[#3d4651]">
                                    {initials(g.user)}
                                </span>
                                <span className="grid min-w-0 flex-1">
                                    <span className="truncate text-sm font-medium">
                                        {g.user}
                                    </span>
                                    <span className="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
                                        <span
                                            className="shrink-0 rounded px-1.5 py-px text-[11px] font-semibold"
                                            style={{
                                                backgroundColor: tone.bg,
                                                color: tone.fg,
                                            }}
                                        >
                                            {ROLE_LABELS[g.role] ?? g.role}
                                        </span>
                                        <span className="truncate">
                                            {g.sheet ?? 'Whole logbook'}
                                        </span>
                                    </span>
                                </span>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-8"
                                    aria-label={`Remove ${g.user}`}
                                    onClick={async () => {
                                        if (
                                            await ask({
                                                title: `Remove ${g.user} from this logbook?`,
                                                message:
                                                    'They no longer reach it. Their entries stay.',
                                                confirmLabel: 'Remove',
                                                destructive: true,
                                            })
                                        ) {
                                            router.delete(
                                                accessRoutes.destroy(g.id).url,
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                >
                                    <X />
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            )}
            <p className="border-t border-[#ebebeb] px-4 py-2.5 text-xs text-muted-foreground">
                Managers of this logbook's department always reach it.
            </p>
        </Card>
    );
}
