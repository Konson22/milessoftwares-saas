import type { WritableSheet } from '@/lib/logbook';
import type { OccurrenceRow, TaskRow } from '@/lib/work';

/** The overview's props, as HomeController sends them. */

export type Week = {
    from: string;
    to: string;
    entries: number;
    entriesBefore: number;
    missed: number;
    missedBefore: number;
    tasksDue: number;
    byLogbook: { id: string; name: string; count: number }[];
};

export type Charts = {
    perDay: { date: string; entries: number }[];
    compliance: {
        onTime: number;
        late: number;
        missed: number;
        open: number;
        excused: number;
    };
    tasks: {
        overdue: number;
        pending: number;
        in_progress: number;
        returned: number;
        awaiting_verification: number;
    };
    severity: { critical: number; high: number; medium: number; low: number };
};

export type Operations = {
    myTasks: TaskRow[];
    overdueTasks: number;
    toVerify: number | null;
    due: OccurrenceRow[];
    missedThisWeek: number;
    openExceptions: number;
    criticalExceptions: number;
    upcoming: { id: string; title: string; dueAt: string }[];
    myMissed: OccurrenceRow[];
    returned: {
        id: string;
        reference: string | null;
        sheet: string;
        at: string;
    }[];
};

export type Figures = {
    logbooks: number;
    sheets: number;
    entries: number;
    today: number;
    pending: number;
    reviewed: number;
    returned: number;
    outOfRange: number;
};

export type ActivityEvent = {
    id: string;
    action: string;
    subject: string;
    subjectType: string;
    subjectId: string | null;
    user: string | null;
    at: string;
};

export type QuickSheet = WritableSheet & { count?: number };
