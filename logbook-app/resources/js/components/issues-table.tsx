import { Link } from '@inertiajs/react';
import {
    BookOpen,
    Building2,
    CircleAlert,
    CircleCheck,
    Eye,
    Filter,
    ShieldAlert,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { index as exceptions } from '@/routes/exceptions';

export type IssueRow = {
    id: string;
    name: string;
    department?: string;
    logbooks?: number;
    entries: number;
    returned: number;
    awaiting: number;
    exceptions: number;
    open: number;
    serious: number;
    missed: number;
    late: number;
    overdue: number;
    score: number;
    level: 'investigate' | 'watch' | 'clear';
};

export type Issues = {
    departments: IssueRow[];
    logbooks: IssueRow[];
    people: IssueRow[];
};

type View = keyof Issues;

const VIEWS: {
    key: View;
    label: string;
    icon: typeof Building2;
    first: string;
}[] = [
    {
        key: 'departments',
        label: 'Departments',
        icon: Building2,
        first: 'Department',
    },
    { key: 'logbooks', label: 'Logbooks', icon: BookOpen, first: 'Logbook' },
    { key: 'people', label: 'People', icon: Users, first: 'Person' },
];

/* Solid status colours with an icon and a word each: never colour alone. */
const LEVELS = {
    investigate: {
        label: 'Investigate',
        icon: ShieldAlert,
        colour: '#a10d19',
        soft: '#fbe5e7',
    },
    watch: {
        label: 'Watch',
        icon: Eye,
        colour: '#8a4b00',
        soft: '#fcefdc',
    },
    clear: {
        label: 'Clear',
        icon: CircleCheck,
        colour: '#0b650a',
        soft: '#e6f2e6',
    },
} as const;

const cell = (n: number) =>
    n === 0 ? (
        <span className="text-[#b0b6bd]">–</span>
    ) : (
        <span className="font-semibold text-foreground">
            {n.toLocaleString()}
        </span>
    );

/**
 * Where something may need investigating: per department, logbook or
 * person, worst first. Serious open exceptions and missed entries mark a
 * row "Investigate"; any other open issue marks it "Watch".
 */
export function IssuesTable({
    issues,
    onLogbook,
}: {
    issues: Issues;
    /** Narrow the whole report to one logbook. */
    onLogbook: (id: string) => void;
}) {
    const [view, setView] = useState<View>(
        issues.departments.length > 1 ? 'departments' : 'logbooks',
    );
    const rows = issues[view];
    const current = VIEWS.find((v) => v.key === view)!;
    const flagged = (level: IssueRow['level']) =>
        issues.logbooks.filter((r) => r.level === level).length;
    const investigate = flagged('investigate');
    const watch = flagged('watch');

    return (
        <section className="overflow-hidden rounded-lg border bg-card shadow-[0_1px_2px_#e3e3e3]">
            <header className="flex flex-wrap items-center gap-3 border-b border-[#ebebeb] px-4 py-3">
                <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-[#fbe5e7] text-[#a10d19]">
                    <CircleAlert className="size-4" />
                </span>
                <div className="grid min-w-0 flex-1">
                    <h2 className="text-sm font-semibold">Needs attention</h2>
                    <p className="text-xs text-muted-foreground">
                        {investigate === 0 && watch === 0
                            ? 'No open issues in this period.'
                            : `${investigate} ${investigate === 1 ? 'logbook' : 'logbooks'} to investigate · ${watch} to watch`}
                    </p>
                </div>
                <div
                    className="flex gap-1 rounded-lg bg-[#f1f3f5] p-1"
                    role="tablist"
                    aria-label="Group by"
                >
                    {VIEWS.map((v) => (
                        <button
                            key={v.key}
                            type="button"
                            role="tab"
                            aria-selected={view === v.key}
                            onClick={() => setView(v.key)}
                            className={`flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium ${
                                view === v.key
                                    ? 'bg-card text-foreground shadow-[0_1px_2px_#d5d9de]'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            <v.icon className="size-3.5" />
                            {v.label}
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {issues[v.key].length}
                            </span>
                        </button>
                    ))}
                </div>
            </header>

            {rows.length === 0 ? (
                <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                    Nothing recorded in this period.
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[56rem] text-sm">
                        <thead>
                            <tr className="border-b border-[#ebebeb] bg-[#f8f9fa] text-left text-xs text-muted-foreground">
                                <th className="px-4 py-2 font-medium">
                                    {current.first}
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <Num title="Submitted entries">Entries</Num>
                                <Num title="Returned for correction">
                                    Returned
                                </Num>
                                <Num title="Still awaiting review">Waiting</Num>
                                <Num title="Open exceptions (of all raised); high or critical in brackets">
                                    Open exceptions
                                </Num>
                                <Num title="Expected entries never made">
                                    Missed
                                </Num>
                                <Num title="Expected entries made late">
                                    Late
                                </Num>
                                <Num title="Tasks past their due time, still open">
                                    Overdue tasks
                                </Num>
                                <th className="w-px px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[#f0f0f0]">
                            {rows.map((r) => {
                                const level = LEVELS[r.level];
                                return (
                                    <tr
                                        key={r.id}
                                        className="hover:bg-[#f8f9fa]"
                                    >
                                        <td className="max-w-[16rem] px-4 py-2.5">
                                            <span className="block truncate font-medium">
                                                {r.name}
                                            </span>
                                            {r.department && (
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {r.department}
                                                </span>
                                            )}
                                            {r.logbooks !== undefined && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {r.logbooks}{' '}
                                                    {r.logbooks === 1
                                                        ? 'logbook'
                                                        : 'logbooks'}{' '}
                                                    with activity
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <span
                                                className="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold"
                                                style={{
                                                    backgroundColor: level.soft,
                                                    color: level.colour,
                                                }}
                                            >
                                                <level.icon className="size-3.5" />
                                                {level.label}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2.5 text-right text-muted-foreground tabular-nums">
                                            {r.entries.toLocaleString()}
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums">
                                            {cell(r.returned)}
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums">
                                            {cell(r.awaiting)}
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums">
                                            {cell(r.open)}
                                            {r.serious > 0 && (
                                                <span className="ml-1 text-xs font-semibold text-[#a10d19]">
                                                    ({r.serious} serious)
                                                </span>
                                            )}
                                            {r.exceptions > r.open && (
                                                <span className="block text-xs text-muted-foreground">
                                                    of {r.exceptions} raised
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums">
                                            {cell(r.missed)}
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums">
                                            {cell(r.late)}
                                        </td>
                                        <td className="px-3 py-2.5 text-right tabular-nums">
                                            {cell(r.overdue)}
                                        </td>
                                        <td className="px-3 py-2.5 text-right whitespace-nowrap">
                                            {view === 'logbooks' && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        onLogbook(r.id)
                                                    }
                                                    className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary hover:bg-[#e3f3ee]"
                                                    aria-label={`Show only ${r.name} in this report`}
                                                >
                                                    <Filter className="size-3.5" />
                                                    Look closer
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            <footer className="flex flex-wrap items-center justify-between gap-2 border-t border-[#ebebeb] px-4 py-2.5 text-xs text-muted-foreground">
                <span>
                    People are counted for entries they wrote, exceptions on
                    those entries, expected entries they were responsible for
                    and tasks assigned to them.
                </span>
                <Link
                    href={exceptions()}
                    className="font-medium text-primary hover:underline"
                >
                    Open exceptions →
                </Link>
            </footer>
        </section>
    );
}

function Num({ title, children }: { title: string; children: string }) {
    return (
        <th className="px-3 py-2 text-right font-medium" title={title}>
            {children}
        </th>
    );
}
