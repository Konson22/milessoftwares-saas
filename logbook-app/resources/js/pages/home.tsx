import { Head, Link } from '@inertiajs/react';
import {
    CalendarDays,
    ClipboardCheck,
    FileText,
    ListTodo,
    Plus,
    TriangleAlert,
} from 'lucide-react';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { Button } from '@miles/ui/components/ui/button';
import { ActivityFeed } from '@/components/dashboard/activity-feed';
import { ChartCard } from '@/components/dashboard/chart-card';
import { ComplianceChart } from '@/components/dashboard/compliance';
import { EntriesPerDayChart } from '@/components/dashboard/entries-per-day';
import {
    EntriesByLogbookChart,
    SeverityChart,
    TaskStatusChart,
} from '@/components/dashboard/charts';
import { formatRange, Trend } from '@/components/dashboard/trend';
import type {
    ActivityEvent,
    Charts,
    Figures,
    Operations,
    QuickSheet,
    Week,
} from '@/components/dashboard/types';
import {
    DueTodayPanel,
    FollowUpPanels,
    MyTasksPanel,
    PanelLink,
    QuickEntryPanel,
} from '@/components/dashboard/work-panels';
import { PageHeader } from '@/components/page-header';
import type { WritableSheet } from '@/lib/logbook';
import { create, index as entries } from '@/routes/entries';
import { index as exceptionsPage } from '@/routes/exceptions';
import { index as reviews } from '@/routes/reviews';
import { index as schedulesPage } from '@/routes/schedules';
import { index as tasksPage } from '@/routes/tasks';

type Props = {
    week: Week;
    charts: Charts;
    operations: Operations;
    figures: Figures;
    myDrafts: number;
    awaitingMyReview: number | null;
    frequentSheets: QuickSheet[];
    writableSheets: WritableSheet[];
    activity: ActivityEvent[];
};

/**
 * The operations dashboard: four headline figures, then the charts
 * (entries over time, how scheduled entries went, entries per logbook),
 * the recent activity, open work, and the member's own follow-up.
 */
export default function Home({
    week,
    charts,
    operations,
    figures,
    myDrafts,
    awaitingMyReview,
    frequentSheets,
    writableSheets,
    activity,
}: Props) {
    const quick =
        frequentSheets.length > 0 ? frequentSheets : writableSheets.slice(0, 6);

    return (
        <>
            <Head title="Overview" />
            <PageHeader
                title="Operations dashboard"
                actions={
                    <>
                        <span className="inline-flex h-9 items-center gap-2 rounded border bg-card px-3 text-sm">
                            <CalendarDays
                                className="size-4 text-muted-foreground"
                                aria-hidden
                            />
                            Last 7 days ({formatRange(week.from, week.to)})
                        </span>
                        {writableSheets.length > 0 && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus /> New entry
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid gap-5">
                {/* Headline figures */}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Entries"
                        value={week.entries}
                        hint={
                            <Trend
                                now={week.entries}
                                before={week.entriesBefore}
                                upIsGood
                                suffix="vs. previous 7 days"
                            />
                        }
                        icon={FileText}
                        accent="blue"
                        href={entries({ query: { scope: 'all' } })}
                    />
                    <StatCard
                        label="Pending review"
                        value={figures.pending}
                        hint={
                            awaitingMyReview !== null
                                ? `${awaitingMyReview} waiting on you`
                                : 'needs supervisor action'
                        }
                        icon={ClipboardCheck}
                        accent="amber"
                        href={awaitingMyReview !== null ? reviews() : undefined}
                    />
                    <StatCard
                        label="Tasks due"
                        value={week.tasksDue}
                        hint={`today or overdue · ${operations.overdueTasks} overdue`}
                        icon={ListTodo}
                        accent={operations.overdueTasks > 0 ? 'red' : 'emerald'}
                        href={tasksPage({
                            query: { status: 'open', sort: 'due' },
                        })}
                    />
                    <StatCard
                        label="Missed inspections"
                        value={week.missed}
                        hint={
                            <Trend
                                now={week.missed}
                                before={week.missedBefore}
                                suffix="vs. previous 7 days"
                            />
                        }
                        icon={TriangleAlert}
                        accent={week.missed > 0 ? 'red' : 'slate'}
                        href={schedulesPage({ query: { state: 'missed' } })}
                    />
                </div>

                {/* Entries over time, and how scheduled entries went */}
                <div className="grid gap-5 xl:grid-cols-3">
                    <ChartCard
                        className="xl:col-span-2"
                        title="Entries per day"
                        note="Submitted entries, last 14 days"
                        link={{
                            href: entries({ query: { scope: 'all' } }).url,
                            label: 'View entries',
                        }}
                    >
                        <EntriesPerDayChart data={charts.perDay} />
                    </ChartCard>
                    <ChartCard
                        title="Scheduled entries"
                        note="Last 7 days"
                        link={{ href: schedulesPage().url, label: 'Schedules' }}
                    >
                        <ComplianceChart data={charts.compliance} />
                    </ChartCard>
                </div>

                {/* Where entries come from, and what happened lately */}
                <div className="grid gap-5 xl:grid-cols-2">
                    <ChartCard
                        title="Entries by logbook"
                        note="Submitted in the last 7 days"
                    >
                        <EntriesByLogbookChart rows={week.byLogbook} />
                    </ChartCard>
                    <Panel className="min-w-0">
                        <PanelHeader
                            title="Recent activity"
                            actions={
                                <PanelLink
                                    href={
                                        entries({ query: { scope: 'all' } }).url
                                    }
                                >
                                    View all
                                </PanelLink>
                            }
                        />
                        <ActivityFeed events={activity} />
                    </Panel>
                </div>

                {/* Open work */}
                <div className="grid gap-5 lg:grid-cols-2 xl:grid-cols-3">
                    <ChartCard
                        title="Open tasks"
                        note="By status"
                        link={{ href: tasksPage().url, label: 'Tasks' }}
                    >
                        <TaskStatusChart data={charts.tasks} />
                    </ChartCard>
                    <ChartCard
                        title="Open exceptions"
                        note={`By severity · ${figures.outOfRange} readings out of range this week`}
                        link={{
                            href: exceptionsPage().url,
                            label: 'Exceptions',
                        }}
                    >
                        <SeverityChart data={charts.severity} />
                    </ChartCard>
                    <div className="min-w-0 lg:col-span-2 xl:col-span-1">
                        <DueTodayPanel due={operations.due} />
                    </div>
                </div>

                {/* The member's own work */}
                <div className="grid gap-5 xl:grid-cols-3">
                    <div className="min-w-0 xl:col-span-2">
                        <MyTasksPanel tasks={operations.myTasks} />
                    </div>
                    <QuickEntryPanel sheets={quick} drafts={myDrafts} />
                </div>

                <FollowUpPanels
                    upcoming={operations.upcoming}
                    missed={operations.myMissed}
                    returned={operations.returned}
                />
            </div>
        </>
    );
}
