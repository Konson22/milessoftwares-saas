import { Link } from '@inertiajs/react';
import {
    Check,
    ClipboardCheck,
    FileText,
    RotateCcw,
    SquareCheckBig,
    Sun,
    TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import type { ActivityEvent } from '@/components/dashboard/types';
import { action, formatDateTime } from '@/lib/logbook';
import { show as entry } from '@/routes/entries';

const KINDS: {
    match: (action: string) => boolean;
    icon: LucideIcon;
    tone: string;
}[] = [
    {
        match: (a) => a.startsWith('exception.'),
        icon: TriangleAlert,
        tone: 'bg-[#fde6e6] text-[#c01c1c]',
    },
    {
        match: (a) => a === 'entry.reviewed' || a === 'task.verified',
        icon: Check,
        tone: 'bg-[#e2f4ee] text-[#0e8a6c]',
    },
    {
        match: (a) => a.startsWith('task.'),
        icon: SquareCheckBig,
        tone: 'bg-[#e2f4ee] text-[#0e8a6c]',
    },
    {
        match: (a) => a === 'entry.returned',
        icon: RotateCcw,
        tone: 'bg-[#fcf1cf] text-[#8a5a00]',
    },
    {
        match: (a) => a.startsWith('entry.'),
        icon: FileText,
        tone: 'bg-[#e3edfc] text-[#1d4fb8]',
    },
];

/** What happened lately, newest first, an icon per kind of event. */
export function ActivityFeed({
    events,
    limit = 7,
}: {
    events: ActivityEvent[];
    limit?: number;
}) {
    if (events.length === 0) {
        return (
            <EmptyState icon={Sun} title="No activity yet">
                Entries, reviews and published templates show here.
            </EmptyState>
        );
    }

    return (
        <ul className="divide-y">
            {events.slice(0, limit).map((e) => (
                <ActivityRow key={e.id} event={e} />
            ))}
        </ul>
    );
}

function ActivityRow({ event: e }: { event: ActivityEvent }) {
    const kind = KINDS.find((k) => k.match(e.action));
    const Icon = kind?.icon ?? ClipboardCheck;

    return (
        <li className="flex gap-3 px-4 py-3 text-sm">
            <span
                className={`grid size-8 shrink-0 place-items-center rounded-lg ${
                    kind?.tone ?? 'bg-[#eef2f6] text-[#475569]'
                }`}
            >
                <Icon className="size-4" aria-hidden />
            </span>
            <span className="min-w-0 flex-1">
                <span className="font-medium">{e.user ?? 'Miles Logbook'}</span>{' '}
                {action(e.action)}{' '}
                {e.subjectType === 'entry' && e.subjectId ? (
                    <Link
                        href={entry(e.subjectId)}
                        className="font-medium hover:underline"
                    >
                        {e.subject}
                    </Link>
                ) : (
                    <span className="font-medium">{e.subject}</span>
                )}
            </span>
            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                {timeOrDate(e.at)}
            </span>
        </li>
    );
}

/** Today: the time; earlier: the date and time. */
function timeOrDate(at: string): string {
    const d = new Date(at);

    return new Date().toDateString() === d.toDateString()
        ? d.toLocaleTimeString(undefined, {
              hour: '2-digit',
              minute: '2-digit',
          })
        : formatDateTime(at);
}
