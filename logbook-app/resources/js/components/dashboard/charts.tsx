import { CHART, STATUS } from '@/components/dashboard/chart-kit';
import { BarList, simpleRows } from '@/components/dashboard/bar-list';
import type { Charts, Week } from '@/components/dashboard/types';

/** Submitted entries per logbook over the week, busiest first. */
export function EntriesByLogbookChart({ rows }: { rows: Week['byLogbook'] }) {
    return (
        <BarList
            caption="Entries submitted per logbook in the last 7 days"
            empty="No entries were submitted in the last 7 days."
            rows={simpleRows(
                [...rows]
                    .sort((x, y) => y.count - x.count)
                    .map((r) => ({
                        label: r.name,
                        value: r.count,
                        colour: CHART.series,
                    })),
            )}
        />
    );
}

export type BarRow = { label: string; value: number; colour: string };

/** A short list of horizontal bars, the value at the end of each label. */
export function HorizontalBars({
    rows,
    caption,
}: {
    rows: BarRow[];
    caption: string;
}) {
    return <BarList rows={simpleRows(rows)} caption={caption} />;
}

/** Open work by status; overdue in the critical colour. */
export function TaskStatusChart({ data }: { data: Charts['tasks'] }) {
    return (
        <HorizontalBars
            caption="Open tasks by status"
            rows={[
                {
                    label: 'Overdue',
                    value: data.overdue,
                    colour: STATUS.critical,
                },
                { label: 'To do', value: data.pending, colour: CHART.series },
                {
                    label: 'In progress',
                    value: data.in_progress,
                    colour: CHART.series,
                },
                {
                    label: 'Returned',
                    value: data.returned,
                    colour: CHART.series,
                },
                {
                    label: 'Awaiting verification',
                    value: data.awaiting_verification,
                    colour: CHART.series,
                },
            ]}
        />
    );
}

/** Open exceptions by severity, in the severity's own colour. */
export function SeverityChart({ data }: { data: Charts['severity'] }) {
    return (
        <HorizontalBars
            caption="Open exceptions by severity"
            rows={[
                {
                    label: 'Critical',
                    value: data.critical,
                    colour: STATUS.critical,
                },
                { label: 'High', value: data.high, colour: STATUS.serious },
                { label: 'Medium', value: data.medium, colour: STATUS.warning },
                { label: 'Low', value: data.low, colour: STATUS.neutral },
            ]}
        />
    );
}
