import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    FolderKanban,
    Gauge,
    MinusCircle,
    Send,
    XCircle,
} from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { ChartPanel, SegmentedBar } from '@/components/charts';
import type { Part } from '@/components/charts';
import { selectClass } from '@/components/fields';
import { PerformanceTrend } from '@/components/performance';
import {
    PERFORMANCE,
    formatDate,
    formatDateTime,
    formatFigure,
} from '@/lib/mel';
import type {
    Measure,
    Option,
    PerformanceRow,
    PerformanceStatus,
} from '@/lib/mel';
import { home } from '@/routes';
import { index as dataIndex } from '@/routes/data';
import { create, show as programme } from '@/routes/programmes';
import { show as submission } from '@/routes/submissions';
import { index as verification } from '@/routes/verification';

type Props = {
    provisioned: boolean;
    programmes: Option[];
    selected: string | null;
    stats: {
        activeProgrammes: number;
        indicators: number;
        awaitingReview: number | null;
        awaitingPublication: number | null;
        sentBack: number | null;
        overdue: number | null;
    };
    statusCounts: Record<PerformanceStatus, number>;
    thresholds: { onTrack: number; attention: number };
    deadlines: {
        programmeId: string;
        programme: string;
        periodId: string;
        period: string;
        dueOn: string;
        overdue: boolean;
    }[];
    attention: {
        id: string;
        code: string;
        name: string;
        offTrack: number;
        overdue: number;
    }[];
    trend: PerformanceRow[] | null;
    published: {
        submissionId: string;
        indicator: string;
        name: string;
        period: string;
        unit: string;
        value: string | null;
        measure: Measure;
        decimals: number;
        unitLabel: string | null;
        publishedAt: string | null;
    }[];
    can: { createProgramme: boolean; enter: boolean; review: boolean };
};

const STATUS_PARTS: {
    key: PerformanceStatus;
    fill: string;
    ink: string;
    icon: typeof CheckCircle2;
}[] = [
    {
        key: 'on_track',
        fill: 'var(--viz-good)',
        ink: 'var(--viz-good-ink)',
        icon: CheckCircle2,
    },
    {
        key: 'needs_attention',
        fill: 'var(--viz-warning)',
        ink: 'var(--viz-warning-ink)',
        icon: AlertTriangle,
    },
    {
        key: 'off_track',
        fill: 'var(--viz-critical)',
        ink: 'var(--viz-critical-ink)',
        icon: XCircle,
    },
    {
        key: 'no_target',
        fill: 'var(--viz-grid)',
        ink: 'var(--foreground)',
        icon: MinusCircle,
    },
    {
        key: 'no_data',
        fill: 'var(--viz-baseline)',
        ink: 'var(--foreground)',
        icon: MinusCircle,
    },
];

export default function Home({
    provisioned,
    programmes,
    selected,
    stats,
    statusCounts,
    thresholds,
    deadlines,
    attention,
    trend,
    published,
    can,
}: Props) {
    if (!provisioned) {
        return (
            <>
                <Head title="Dashboard" />
                <EmptyState icon={FolderKanban} title="Setting up">
                    M&E is being set up for this workspace. Refresh in a moment.
                </EmptyState>
            </>
        );
    }

    const assessed = Object.values(statusCounts).reduce(
        (sum, count) => sum + count,
        0,
    );
    const parts: Part[] = STATUS_PARTS.map((part) => ({
        ...part,
        label: PERFORMANCE[part.key].label,
        value: statusCounts[part.key],
    }));
    const add = can.createProgramme ? (
        <Button asChild>
            <Link href={create()}>New programme</Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Dashboard" />
            <PageHeader
                title="Dashboard"
                actions={
                    <>
                        {programmes.length > 1 && (
                            <select
                                aria-label="Programme"
                                value={selected ?? ''}
                                onChange={(event) =>
                                    router.get(
                                        home.url({
                                            query: event.target.value
                                                ? {
                                                      programme:
                                                          event.target.value,
                                                  }
                                                : {},
                                        }),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                                className={`${selectClass} w-auto`}
                            >
                                <option value="">All programmes</option>
                                {programmes.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        )}
                        {add}
                    </>
                }
            />

            {programmes.length === 0 ? (
                <Panel>
                    <EmptyState
                        icon={FolderKanban}
                        title="No programmes yet"
                        action={add}
                    >
                        {can.createProgramme
                            ? 'Start with a programme: its results framework, indicators and reporting periods follow.'
                            : 'Programmes you are given access to show here.'}
                    </EmptyState>
                </Panel>
            ) : (
                <div className="grid gap-3">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            label="Active programmes"
                            value={stats.activeProgrammes}
                            icon={FolderKanban}
                        />
                        <StatCard
                            label="Active indicators"
                            value={stats.indicators}
                            icon={Gauge}
                            accent="violet"
                        />
                        {stats.awaitingReview !== null && (
                            <StatCard
                                label="Waiting for review"
                                value={stats.awaitingReview}
                                hint={
                                    stats.sentBack
                                        ? `${stats.sentBack} sent back for correction`
                                        : undefined
                                }
                                icon={ClipboardCheck}
                                accent="amber"
                                href={can.review ? verification() : undefined}
                            />
                        )}
                        {stats.awaitingPublication !== null && (
                            <StatCard
                                label="Verified, not published"
                                value={stats.awaitingPublication}
                                icon={Send}
                                accent="emerald"
                                href={
                                    can.review
                                        ? verification({
                                              query: { stage: 'publish' },
                                          })
                                        : undefined
                                }
                            />
                        )}
                        {stats.overdue !== null &&
                            stats.awaitingReview === null && (
                                <StatCard
                                    label="Overdue results"
                                    value={stats.overdue}
                                    icon={CalendarClock}
                                    accent="red"
                                />
                            )}
                    </div>

                    <div className="grid gap-3 lg:grid-cols-3">
                        <ChartPanel
                            className="lg:col-span-2"
                            title="Indicators against target"
                            description={`Each indicator's latest period. On track from ${thresholds.onTrack}% of target; needs attention from ${thresholds.attention}%.`}
                            columns={['Status', 'Indicators']}
                            rows={parts.map((part) => [part.label, part.value])}
                            empty={
                                assessed === 0
                                    ? 'No indicator has a period to assess yet.'
                                    : null
                            }
                        >
                            <SegmentedBar
                                parts={parts}
                                label="Indicators by status"
                            />
                            {stats.overdue !== null && stats.overdue > 0 && (
                                <p className="mt-3 text-sm text-muted-foreground">
                                    <CalendarClock
                                        className="mr-1 inline size-4 align-text-bottom"
                                        aria-hidden
                                    />
                                    {stats.overdue} results are past their due
                                    date with nothing entered.
                                </p>
                            )}
                        </ChartPanel>

                        <Panel>
                            <PanelHeader
                                title="Reporting deadlines"
                                description="Open periods due in the next weeks"
                            />
                            {deadlines.length === 0 ? (
                                <p className="p-3 text-sm text-muted-foreground">
                                    Nothing due soon.
                                </p>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {deadlines.map((deadline) => (
                                        <li
                                            key={deadline.periodId}
                                            className="flex items-center justify-between gap-2 px-3 py-2"
                                        >
                                            <Link
                                                href={dataIndex({
                                                    query: {
                                                        programme:
                                                            deadline.programmeId,
                                                        period: deadline.periodId,
                                                    },
                                                })}
                                                className="hover:underline"
                                            >
                                                <span className="font-medium">
                                                    {deadline.programme}
                                                </span>{' '}
                                                {deadline.period}
                                            </Link>
                                            <StatusBadge
                                                tone={
                                                    deadline.overdue
                                                        ? 'danger'
                                                        : 'neutral'
                                                }
                                            >
                                                {deadline.overdue
                                                    ? 'Overdue'
                                                    : 'Due'}{' '}
                                                {formatDate(deadline.dueOn)}
                                            </StatusBadge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    </div>

                    {attention.length > 0 && (
                        <Panel>
                            <PanelHeader
                                title="Needs attention"
                                description="Programmes with indicators off track or results overdue"
                            />
                            <ul className="divide-y text-sm">
                                {attention.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                                    >
                                        <Link
                                            href={programme(item.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {item.code} · {item.name}
                                        </Link>
                                        <span className="flex gap-2">
                                            {item.offTrack > 0 && (
                                                <StatusBadge tone="danger">
                                                    {item.offTrack} off track
                                                </StatusBadge>
                                            )}
                                            {item.overdue > 0 && (
                                                <StatusBadge tone="warning">
                                                    {item.overdue} overdue
                                                </StatusBadge>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    )}

                    {trend !== null && trend.length > 0 && (
                        <div className="grid gap-3 lg:grid-cols-2">
                            {trend.map((row) => (
                                <PerformanceTrend
                                    key={row.indicator.id}
                                    row={row}
                                />
                            ))}
                        </div>
                    )}
                    {trend === null && programmes.length > 1 && (
                        <p className="text-sm text-muted-foreground">
                            Choose a programme above to see its indicators over
                            time.
                        </p>
                    )}

                    <Panel>
                        <PanelHeader
                            title="Recently published"
                            description="Results released to donors and partners"
                        />
                        {published.length === 0 ? (
                            <p className="p-3 text-sm text-muted-foreground">
                                Nothing published yet.
                            </p>
                        ) : (
                            <PanelBody className="p-0">
                                <ul className="divide-y text-sm">
                                    {published.map((item) => (
                                        <li
                                            key={item.submissionId}
                                            className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                                        >
                                            <Link
                                                href={submission(
                                                    item.submissionId,
                                                )}
                                                className="min-w-0 hover:underline"
                                            >
                                                <span className="font-medium">
                                                    {item.indicator}
                                                </span>{' '}
                                                {item.name}
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · {item.unit} ·{' '}
                                                    {item.period}
                                                </span>
                                            </Link>
                                            <span className="flex items-center gap-3">
                                                <span className="font-medium tabular-nums">
                                                    {formatFigure(
                                                        item.value,
                                                        item,
                                                    )}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {formatDateTime(
                                                        item.publishedAt,
                                                    )}
                                                </span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </PanelBody>
                        )}
                    </Panel>
                </div>
            )}
        </>
    );
}
