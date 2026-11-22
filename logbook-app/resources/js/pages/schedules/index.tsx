import { Head, router, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    CircleCheck,
    ClipboardCheck,
    Clock,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@/components/page-header';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { Button } from '@miles/ui/components/ui/button';
import { OccurrenceTable } from '@/components/schedules/occurrence-table';
import { SelectField, Status, TextField } from '@/components/fields';
import {
    ChannelPicker,
    LifecycleActions,
    ReminderPicker,
    WeekdayPicker,
} from '@/components/work';
import type { Option } from '@/lib/logbook';
import type { Channel, OccurrenceRow, ScheduleRow } from '@/lib/work';
import { recurrenceLabel } from '@/lib/work';
import { end, index, pause, resume, store, update } from '@/routes/schedules';

type Props = {
    filters: {
        from: string;
        to: string;
        logbook_id: string;
        log_sheet_id: string;
        responsible_id: string;
        state: string;
    };
    figures: {
        expected: number;
        completed: number;
        late: number;
        missed: number;
        excused: number;
        awaitingReview: number;
        open: number;
        rate: number | null;
        outstandingReviews: number;
    };
    occurrences: OccurrenceRow[];
    schedules: ScheduleRow[];
    options: {
        logbooks: Option[];
        sheets: (Option & { logbook: string })[];
        members: Option[];
    };
    canManage: boolean;
};

const STATES = [
    { value: 'due', label: 'Due now' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'upcoming', label: 'Upcoming' },
    { value: 'awaiting_review', label: 'Awaiting review' },
    { value: 'completed', label: 'Completed' },
    { value: 'late', label: 'Late' },
    { value: 'missed', label: 'Missed' },
    { value: 'excused', label: 'Excused' },
];

const PRESETS = ['before:15', 'before:60', 'due', 'after:0', 'after:60'];

export default function Schedules({
    filters,
    figures,
    occurrences,
    schedules,
    options,
    canManage,
}: Props) {
    const go = (query: Partial<Props['filters']>) =>
        router.get(
            index().url,
            { ...filters, ...query },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Schedules" />
            <PageHeader
                title="Schedules"
                description="Scheduled inspections and readings: what is due, on time, late or missed."
            />
            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Completion rate"
                    value={
                        figures.rate === null
                            ? '—'
                            : `${Math.round(figures.rate * 100)}%`
                    }
                    hint={`${figures.completed + figures.late} of ${figures.expected} expected`}
                    icon={CircleCheck}
                    accent="emerald"
                />
                <StatCard
                    label="Missed"
                    value={figures.missed}
                    hint={`${figures.excused} excused`}
                    icon={TriangleAlert}
                    accent="red"
                />
                <StatCard
                    label="Late"
                    value={figures.late}
                    hint="Came in after the due time"
                    icon={Clock}
                    accent="amber"
                />
                <StatCard
                    label="Waiting on review"
                    value={figures.outstandingReviews}
                    hint={`${figures.open} due and not yet written`}
                    icon={ClipboardCheck}
                    accent="slate"
                />
            </div>

            <Panel>
                <PanelHeader
                    title={`Expected entries (${occurrences.length})`}
                />
                <PanelToolbar>
                    <div className="flex flex-wrap items-end gap-3">
                        <TextField
                            label="From"
                            type="date"
                            value={filters.from}
                            onChange={(from) => go({ from })}
                        />
                        <TextField
                            label="To"
                            type="date"
                            value={filters.to}
                            onChange={(to) => go({ to })}
                        />
                        <SelectField
                            label="Logbook"
                            value={filters.logbook_id}
                            onChange={(logbook_id) =>
                                go({ logbook_id, log_sheet_id: '' })
                            }
                            options={options.logbooks}
                            empty="All"
                            className="w-44"
                        />
                        <SelectField
                            label="Log sheet"
                            value={filters.log_sheet_id}
                            onChange={(log_sheet_id) => go({ log_sheet_id })}
                            options={options.sheets.filter(
                                (s) =>
                                    !filters.logbook_id ||
                                    s.logbook === filters.logbook_id,
                            )}
                            empty="All"
                            className="w-44"
                        />
                        <SelectField
                            label="Responsible"
                            value={filters.responsible_id}
                            onChange={(responsible_id) =>
                                go({ responsible_id })
                            }
                            options={options.members}
                            empty="Anyone"
                            className="w-44"
                        />
                        <SelectField
                            label="State"
                            value={filters.state}
                            onChange={(state) => go({ state })}
                            options={STATES}
                            empty="Any"
                            className="w-40"
                        />
                    </div>
                </PanelToolbar>
                {occurrences.length === 0 ? (
                    <EmptyState
                        icon={CalendarClock}
                        title="Nothing expected in this period"
                    >
                        Schedule a log sheet below, and expected entries appear
                        a day ahead.
                    </EmptyState>
                ) : (
                    <OccurrenceTable
                        occurrences={occurrences}
                        canManage={canManage}
                    />
                )}
            </Panel>

            <Panel className="mt-6">
                <PanelHeader title="Scheduled log sheets" />
                {schedules.length === 0 && !canManage && (
                    <p className="px-4 py-3 text-sm text-muted-foreground">
                        No sheet is scheduled yet.
                    </p>
                )}
                <ul className="divide-y">
                    {schedules.map((s) => (
                        <ScheduleItem
                            key={s.id}
                            schedule={s}
                            options={options}
                            canManage={canManage}
                        />
                    ))}
                </ul>
                {canManage && (
                    <PanelBody>
                        <NewSchedule options={options} />
                    </PanelBody>
                )}
            </Panel>
        </>
    );
}

function ScheduleItem({
    schedule: s,
    options,
    canManage,
}: {
    schedule: ScheduleRow;
    options: Props['options'];
    canManage: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="font-medium">
                        {s.sheet.code} · {s.sheet.name}
                    </p>
                    <p className="text-muted-foreground">
                        {recurrenceLabel(s.frequency, s.weekdays, s.dayOfMonth)}{' '}
                        · opens {s.opensTime}, due {s.dueTime}, late for{' '}
                        {s.lateMinutes} min ({s.timezone}) ·{' '}
                        {s.responsible?.name ?? 'anyone who writes on it'} ·{' '}
                        {s.completion === 'reviewed'
                            ? 'done once reviewed'
                            : 'done once submitted'}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Status value={s.state} />
                    {canManage && (
                        <LifecycleActions
                            state={s.state}
                            what="schedule"
                            urls={{
                                pause: pause(s.id).url,
                                resume: resume(s.id).url,
                                end: end(s.id).url,
                            }}
                        />
                    )}
                    {canManage && s.state !== 'ended' && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setOpen(!open)}
                        >
                            {open ? 'Close' : 'Change'}
                        </Button>
                    )}
                </div>
            </div>
            {open && (
                <div className="mt-3 rounded-md border p-4">
                    <ScheduleForm
                        url={update(s.id).url}
                        method="put"
                        initial={s}
                        members={options.members}
                        onDone={() => setOpen(false)}
                    />
                </div>
            )}
        </li>
    );
}

function NewSchedule({ options }: { options: Props['options'] }) {
    const [sheet, setSheet] = useState('');

    return (
        <div className="grid gap-4">
            <SelectField
                label="Schedule a log sheet"
                value={sheet}
                onChange={setSheet}
                options={options.sheets}
                empty="Choose a published log sheet…"
                className="max-w-md"
            />
            {sheet && (
                <ScheduleForm
                    key={sheet}
                    url={store(sheet).url}
                    method="post"
                    members={options.members}
                    onDone={() => setSheet('')}
                />
            )}
        </div>
    );
}

function ScheduleForm({
    url,
    method,
    initial,
    members,
    onDone,
}: {
    url: string;
    method: 'post' | 'put';
    initial?: ScheduleRow;
    members: Option[];
    onDone: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        frequency: initial?.frequency ?? 'daily',
        weekdays: initial?.weekdays ?? [1],
        day_of_month: String(initial?.dayOfMonth ?? 1),
        opens_time: initial?.opensTime ?? '07:00',
        due_time: initial?.dueTime ?? '09:00',
        late_minutes: String(initial?.lateMinutes ?? 240),
        timezone:
            initial?.timezone ??
            Intl.DateTimeFormat().resolvedOptions().timeZone,
        starts_on: initial?.startsOn ?? new Date().toISOString().slice(0, 10),
        ends_on: initial?.endsOn ?? '',
        responsible_id: initial?.responsible?.id ?? '',
        completion: initial?.completion ?? 'submitted',
        reminders: initial?.reminders ?? ['due', 'after:0'],
        reminder_channels: (initial?.reminderChannels ?? null) as
            | Channel[]
            | null,
    });
    const set = (patch: Partial<typeof data>) => setData({ ...data, ...patch });

    return (
        <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.visit(url, {
                    method,
                    data: {
                        ...data,
                        ends_on: data.ends_on || null,
                        responsible_id: data.responsible_id || null,
                    },
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <SelectField
                label="Expected"
                value={data.frequency}
                onChange={(frequency) =>
                    set({ frequency: frequency as typeof data.frequency })
                }
                options={[
                    { value: 'daily', label: 'Every day' },
                    { value: 'weekly', label: 'Every week' },
                    { value: 'monthly', label: 'Every month' },
                ]}
            />
            {data.frequency === 'weekly' && (
                <WeekdayPicker
                    value={data.weekdays}
                    onChange={(weekdays) => set({ weekdays })}
                    error={errors.weekdays}
                />
            )}
            {data.frequency === 'monthly' && (
                <TextField
                    label="Day of the month"
                    value={data.day_of_month}
                    onChange={(day_of_month) => set({ day_of_month })}
                    inputMode="numeric"
                    error={errors.day_of_month}
                />
            )}
            <TextField
                label="Opens at"
                type="time"
                value={data.opens_time}
                onChange={(opens_time) => set({ opens_time })}
                error={errors.opens_time}
            />
            <TextField
                label="Due at"
                type="time"
                value={data.due_time}
                onChange={(due_time) => set({ due_time })}
                error={errors.due_time}
            />
            <TextField
                label="Accepted late for (minutes)"
                value={data.late_minutes}
                onChange={(late_minutes) => set({ late_minutes })}
                inputMode="numeric"
                error={errors.late_minutes}
                hint="After this, a missing entry is missed."
            />
            <TextField
                label="Time zone"
                value={data.timezone}
                onChange={(timezone) => set({ timezone })}
                error={errors.timezone}
            />
            <TextField
                label="First day"
                type="date"
                value={data.starts_on}
                onChange={(starts_on) => set({ starts_on })}
                error={errors.starts_on}
            />
            <TextField
                label="Last day (optional)"
                type="date"
                value={data.ends_on}
                onChange={(ends_on) => set({ ends_on })}
                error={errors.ends_on}
            />
            <SelectField
                label="Responsible"
                value={data.responsible_id}
                onChange={(responsible_id) => set({ responsible_id })}
                options={members}
                empty="Anyone who writes on the sheet"
                error={errors.responsible_id}
            />
            <SelectField
                label="Done when the entry is"
                value={data.completion}
                onChange={(completion) =>
                    set({ completion: completion as typeof data.completion })
                }
                options={[
                    { value: 'submitted', label: 'Submitted' },
                    { value: 'reviewed', label: 'Reviewed' },
                ]}
                error={errors.completion}
            />
            <ReminderPicker
                value={data.reminders}
                presets={PRESETS}
                onChange={(reminders) => set({ reminders })}
                error={errors.reminders}
            />
            <ChannelPicker
                value={data.reminder_channels}
                onChange={(reminder_channels) => set({ reminder_channels })}
                error={errors.reminder_channels}
            />
            {initial && (
                <p className="text-xs text-muted-foreground sm:col-span-2">
                    Changes apply from now on. Expected entries already open or
                    settled keep their times.
                </p>
            )}
            {errors.schedule && (
                <p className="text-xs text-destructive sm:col-span-2">
                    {errors.schedule}
                </p>
            )}
            <div className="sm:col-span-2">
                <Button type="submit">
                    {initial ? 'Save schedule' : 'Schedule'}
                </Button>
            </div>
        </form>
    );
}
