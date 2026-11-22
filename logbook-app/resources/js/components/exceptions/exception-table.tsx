import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Status } from '@/components/fields';
import type { ExceptionRow } from '@/lib/work';
import { show } from '@/routes/exceptions';

/** Severity colours (critical → low), the same as the dashboard's chart. */
export const SEVERITY_COLOUR: Record<ExceptionRow['severity'], string> = {
    critical: '#d03b3b',
    high: '#ec835a',
    medium: '#fab219',
    low: '#94a3b8',
};

const SOURCE: Record<ExceptionRow['source'], string> = {
    reading: 'Out-of-range reading',
    check: 'Flagged answer',
    manual: 'Reported',
};

/** "20 Nov, 08:00"; the year only when it is not this year. */
function when(value: string | null): string {
    if (!value) {
        return '—';
    }
    const d = new Date(value);

    return `${d.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        ...(d.getFullYear() !== new Date().getFullYear()
            ? { year: 'numeric' as const }
            : {}),
    })}, ${d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}`;
}

/** One line of text, cut short past `max`; the whole text on hover. */
function Clip({
    text,
    max,
    className = '',
}: {
    text: string;
    max: string;
    className?: string;
}) {
    return (
        <span className={`block truncate ${max} ${className}`} title={text}>
            {text}
        </span>
    );
}

function Th({
    children,
    className = '',
}: {
    children?: ReactNode;
    className?: string;
}) {
    return (
        <th
            className={`px-3 py-2.5 text-left font-medium whitespace-nowrap ${className}`}
        >
            {children}
        </th>
    );
}

/**
 * Exceptions, one line per row: a severity stripe at the left edge,
 * every detail in its own column, long text cut short with the full
 * text on hover.
 */
export function ExceptionTable({ rows }: { rows: ExceptionRow[] }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm whitespace-nowrap">
                <thead className="border-b text-xs text-muted-foreground">
                    <tr>
                        <Th className="pl-5">Reference</Th>
                        <Th>Exception</Th>
                        <Th>Severity</Th>
                        <Th>Status</Th>
                        <Th>Assigned to</Th>
                        <Th>Due</Th>
                        <Th>Where</Th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((e) => (
                        <tr key={e.id} className="hover:bg-muted">
                            <td
                                className="border-l-4 py-3 pr-3 pl-4 font-medium"
                                style={{
                                    borderLeftColor:
                                        SEVERITY_COLOUR[e.severity],
                                }}
                            >
                                <Link
                                    href={show(e.id)}
                                    className="text-primary hover:underline"
                                    title={`${SOURCE[e.source]} · raised ${when(e.createdAt)}`}
                                >
                                    {e.reference}
                                </Link>
                            </td>
                            <td className="px-3 py-3">
                                <Link
                                    href={show(e.id)}
                                    className="hover:underline"
                                >
                                    <Clip
                                        text={e.title}
                                        max="max-w-[15rem]"
                                        className="font-medium"
                                    />
                                </Link>
                            </td>
                            <td className="px-3 py-3">
                                <Status value={e.severity} />
                            </td>
                            <td className="px-3 py-3">
                                <span className="inline-flex items-center gap-1.5">
                                    <Status value={e.status} />
                                    {e.overdue && <Status value="overdue" />}
                                </span>
                            </td>
                            <td className="px-3 py-3">
                                {e.assignee ? (
                                    <Clip
                                        text={e.assignee.name}
                                        max="max-w-[9rem]"
                                    />
                                ) : (
                                    <span className="text-muted-foreground">
                                        Nobody yet
                                    </span>
                                )}
                            </td>
                            <td
                                className={`px-3 py-3 tabular-nums ${
                                    e.overdue
                                        ? 'font-medium text-[#c01c1c]'
                                        : ''
                                }`}
                            >
                                {when(e.dueAt)}
                            </td>
                            <td className="px-3 py-3 text-muted-foreground">
                                <Clip
                                    text={
                                        [e.sheet?.code, e.logbook]
                                            .filter(Boolean)
                                            .join(' · ') || '—'
                                    }
                                    max="max-w-[9rem]"
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
