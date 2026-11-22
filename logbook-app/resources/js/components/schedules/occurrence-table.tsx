import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ActionButton } from '@/components/actions';
import { Badge, Status } from '@/components/fields';
import type { OccurrenceRow } from '@/lib/work';
import { show as entry } from '@/routes/entries';
import { excuse } from '@/routes/occurrences';

const EXCUSABLE = ['missed', 'overdue', 'due', 'upcoming'];

/** "20 Nov, 08:00"; the year only when it is not this year. */
function when(value: string | null): string {
    if (!value) {
        return '—';
    }
    const d = new Date(value);
    const date = d.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        ...(d.getFullYear() !== new Date().getFullYear()
            ? { year: 'numeric' as const }
            : {}),
    });
    const time = d.toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
    });

    return `${date}, ${time}`;
}

/** The close time alone when it falls on the due day, else date and time. */
function until(due: string, closes: string | null): string {
    if (!closes) {
        return '—';
    }
    const a = new Date(due);
    const b = new Date(closes);

    return a.toDateString() === b.toDateString()
        ? b.toLocaleTimeString(undefined, {
              hour: '2-digit',
              minute: '2-digit',
          })
        : when(closes);
}

/** One line of text, cut short with "…" past `max`; the whole text on hover. */
function Clip({
    text,
    max = 'max-w-[14rem]',
    className = '',
}: {
    text: string;
    max?: string;
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
 * Expected entries, one line per row: every detail has its own column,
 * nothing wraps, long names are cut short with the full text on hover.
 */
export function OccurrenceTable({
    occurrences,
    canManage,
}: {
    occurrences: OccurrenceRow[];
    canManage: boolean;
}) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm whitespace-nowrap">
                <thead className="border-b text-xs text-muted-foreground">
                    <tr>
                        <Th>Code</Th>
                        <Th>Log sheet</Th>
                        <Th>State</Th>
                        <Th>Due</Th>
                        <Th>Late until</Th>
                        <Th>Responsible</Th>
                        <Th>Logbook</Th>
                        <Th>Entry</Th>
                        {canManage && <Th className="text-right" />}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {occurrences.map((o) => (
                        <tr key={o.id} className="hover:bg-muted">
                            <td className="px-3 py-3 font-medium">
                                {o.sheet.code}
                            </td>
                            <td className="px-3 py-3">
                                <Clip text={o.sheet.name} max="max-w-[13rem]" />
                            </td>
                            <td className="px-3 py-3">
                                <span className="inline-flex items-center gap-1.5">
                                    <Status value={o.state} />
                                    {o.state === 'awaiting_review' &&
                                        o.timeliness && (
                                            <Badge
                                                tone={
                                                    o.timeliness === 'on_time'
                                                        ? 'success'
                                                        : 'warning'
                                                }
                                            >
                                                {o.timeliness === 'on_time'
                                                    ? 'On time'
                                                    : 'Late'}
                                            </Badge>
                                        )}
                                </span>
                            </td>
                            <td className="px-3 py-3 tabular-nums">
                                {when(o.dueAt)}
                            </td>
                            <td className="px-3 py-3 text-muted-foreground tabular-nums">
                                {until(o.dueAt, o.closesAt)}
                            </td>
                            <td className="px-3 py-3">
                                <Clip
                                    text={o.responsible ?? 'Anyone'}
                                    max="max-w-[8.5rem]"
                                    className={
                                        o.responsible
                                            ? ''
                                            : 'text-muted-foreground'
                                    }
                                />
                            </td>
                            <td className="px-3 py-3 text-muted-foreground">
                                <Clip text={o.logbook} max="max-w-[8.5rem]" />
                            </td>
                            <td className="px-3 py-3">
                                {o.entry ? (
                                    <Link
                                        href={entry(o.entry.id)}
                                        className="font-medium text-primary hover:underline"
                                    >
                                        {o.entry.reference}
                                    </Link>
                                ) : o.excuseReason ? (
                                    <Clip
                                        text={o.excuseReason}
                                        max="max-w-[9rem]"
                                        className="text-muted-foreground italic"
                                    />
                                ) : (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </td>
                            {canManage && (
                                <td className="px-3 py-2 text-right">
                                    {EXCUSABLE.includes(o.state) && (
                                        <ActionButton
                                            url={excuse(o.id).url}
                                            label="Excuse"
                                            reason="Why is it excused?"
                                            variant="outline"
                                        />
                                    )}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
