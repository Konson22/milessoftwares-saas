import { Form, Head, Link } from '@inertiajs/react';
import { Download, Printer } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader, PanelToolbar } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { ChartPanel, SegmentedBar } from '@/components/charts';
import { selectClass } from '@/mel/components/fields';
import {
    ActualFigure,
    PerformanceBadge,
    PerformanceTrend,
} from '@/mel/components/performance';
import {
    FREQUENCIES,
    PERFORMANCE,
    SUBMISSION,
    formatDate,
    formatDateTime,
    formatFigure,
    formatNumber,
    formatTarget,
} from '@/mel/lib/mel';
import type {
    Cell,
    Option,
    PerformanceRow,
    PerformanceStatus,
    SubmissionStatus,
} from '@/mel/lib/mel';
import { show as indicatorPage } from '@/routes/mel/indicators';
import { index, show } from '@/routes/mel/reports';
import { show as submissionPage } from '@/routes/mel/submissions';

type Context = {
    report: { key: string; title: string };
    programme: { id: string; code: string; name: string; currency: string };
    filters: {
        frequency: string;
        from: string | null;
        to: string | null;
        unit: string | null;
        period: string | null;
    };
    options: { frequencies: string[]; units: Option[]; periods: Option[] };
    generated: {
        at: string;
        by: string;
        stage: string;
        scope: string | null;
        thresholds: { onTrack: number; attention: number };
    };
    can: { export: boolean };
};

type Totals = Record<
    | 'expected'
    | 'missing'
    | 'overdue'
    | 'draft'
    | 'submitted'
    | 'under_review'
    | 'rejected'
    | 'verified'
    | 'published',
    number
>;

type Completeness = {
    units: { id: string; code: string; name: string }[];
    rows: {
        indicator: { id: string; code: string; name: string };
        period: { id: string; label: string; dueOn: string };
        cells: {
            unitId: string;
            status: SubmissionStatus | 'missing';
            overdue: boolean;
            submissionId: string | null;
        }[];
    }[];
    totals: Totals;
    byPeriod: {
        period: { id: string; label: string; dueOn: string };
        totals: Totals;
    }[];
};

type Props = Context & { data: unknown };

export default function ReportShow(props: Props) {
    const { report, programme, generated, can } = props;
    const query: Record<string, string> = { programme: programme.id };
    for (const [key, value] of Object.entries(props.filters)) {
        if (value) {
            query[key] = value;
        }
    }

    return (
        <>
            <Head title={`${report.title} · ${programme.code}`} />
            <PageHeader
                title={
                    <>
                        <span className="text-muted-foreground">
                            {programme.code}
                        </span>{' '}
                        {report.title}
                    </>
                }
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <Link href={index()}>All reports</Link>
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => window.print()}
                            className="print:hidden"
                        >
                            <Printer aria-hidden />
                            Print
                        </Button>
                        {can.export && (
                            <Button variant="outline" asChild>
                                <a
                                    href={show.url(report.key, {
                                        query: { ...query, format: 'xlsx' },
                                    })}
                                >
                                    <Download aria-hidden />
                                    Excel
                                </a>
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid gap-3">
                <Filters {...props} />

                <p className="text-xs text-muted-foreground">
                    {programme.name} · {generated.stage}
                    {generated.scope && ` · ${generated.scope}`} · on track from{' '}
                    {generated.thresholds.onTrack}% of target, needs attention
                    from {generated.thresholds.attention}% · generated{' '}
                    {formatDateTime(generated.at)} by {generated.by}
                </p>

                {report.key === 'iptt' && (
                    <Iptt
                        data={
                            props.data as {
                                periods: { id: string; label: string }[];
                                rows: PerformanceRow[];
                            }
                        }
                        programmeId={programme.id}
                    />
                )}
                {report.key === 'framework' && (
                    <FrameworkReport data={props.data as FrameworkData} />
                )}
                {report.key === 'target-actual' && (
                    <TargetActual data={props.data as TargetActualData} />
                )}
                {(report.key === 'completeness' ||
                    report.key === 'verification') && (
                    <CompletenessReport
                        data={props.data as Completeness}
                        verification={report.key === 'verification'}
                    />
                )}
                {report.key === 'programme' && (
                    <ProgrammeReport data={props.data as ProgrammeData} />
                )}
            </div>
        </>
    );
}

function Filters({ report, programme, filters, options }: Props) {
    return (
        <Panel className="print:hidden">
            <PanelToolbar>
                <Form
                    {...show.form(report.key)}
                    options={{ preserveScroll: true }}
                    className="flex flex-wrap items-center gap-2"
                >
                    <input
                        type="hidden"
                        name="programme"
                        value={programme.id}
                    />
                    {options.frequencies.length > 1 &&
                        report.key !== 'framework' && (
                            <select
                                name="frequency"
                                aria-label="Reported"
                                defaultValue={filters.frequency}
                                className={`${selectClass} w-auto`}
                            >
                                {options.frequencies.map((frequency) => (
                                    <option key={frequency} value={frequency}>
                                        {FREQUENCIES[frequency]}
                                    </option>
                                ))}
                            </select>
                        )}
                    {report.key === 'target-actual' ? (
                        <select
                            name="period"
                            aria-label="Period"
                            defaultValue={filters.period ?? ''}
                            className={`${selectClass} w-auto`}
                        >
                            {options.periods.map((period) => (
                                <option key={period.value} value={period.value}>
                                    {period.label}
                                </option>
                            ))}
                        </select>
                    ) : (
                        report.key !== 'framework' && (
                            <>
                                <label className="flex items-center gap-1 text-sm">
                                    From
                                    <input
                                        type="date"
                                        name="from"
                                        defaultValue={filters.from ?? ''}
                                        className={`${selectClass} w-auto`}
                                    />
                                </label>
                                <label className="flex items-center gap-1 text-sm">
                                    to
                                    <input
                                        type="date"
                                        name="to"
                                        defaultValue={filters.to ?? ''}
                                        className={`${selectClass} w-auto`}
                                    />
                                </label>
                            </>
                        )
                    )}
                    {options.units.length > 1 &&
                        report.key !== 'target-actual' &&
                        report.key !== 'completeness' &&
                        report.key !== 'verification' && (
                            <select
                                name="unit"
                                aria-label="Unit"
                                defaultValue={filters.unit ?? ''}
                                className={`${selectClass} w-auto`}
                            >
                                <option value="">All units</option>
                                {options.units.map((unit) => (
                                    <option key={unit.value} value={unit.value}>
                                        {unit.label}
                                    </option>
                                ))}
                            </select>
                        )}
                    <Button type="submit" variant="outline">
                        Show
                    </Button>
                </Form>
            </PanelToolbar>
        </Panel>
    );
}

function Iptt({
    data,
    programmeId,
}: {
    data: { periods: { id: string; label: string }[]; rows: PerformanceRow[] };
    programmeId: string;
}) {
    if (data.rows.length === 0) {
        return <Empty>No active indicators report at this frequency.</Empty>;
    }

    const cellFor = (row: PerformanceRow, periodId: string): Cell | undefined =>
        row.periods.find((cell) => cell.periodId === periodId);

    return (
        <>
            <Panel>
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="min-w-56">
                                    Indicator
                                </TableHead>
                                <TableHead>Baseline</TableHead>
                                {data.periods.map((period) => (
                                    <TableHead
                                        key={period.id}
                                        className="min-w-36"
                                    >
                                        {period.label}
                                    </TableHead>
                                ))}
                                <TableHead>To date</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.rows.map((row) => (
                                <TableRow key={row.indicator.id}>
                                    <TableCell>
                                        <Link
                                            href={indicatorPage({
                                                programme: programmeId,
                                                indicator: row.indicator.id,
                                            })}
                                            className="hover:underline"
                                        >
                                            <span className="font-medium">
                                                {row.indicator.code}
                                            </span>{' '}
                                            {row.indicator.name}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            Result {row.indicator.resultCode} ·
                                            v{row.indicator.version}
                                        </div>
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {row.baseline
                                            ? formatFigure(
                                                  row.baseline.value,
                                                  row.indicator,
                                              )
                                            : '—'}
                                    </TableCell>
                                    {data.periods.map((period) => {
                                        const cell = cellFor(row, period.id);

                                        return (
                                            <TableCell key={period.id}>
                                                {cell ? (
                                                    <div className="grid gap-0.5 text-sm">
                                                        <ActualFigure
                                                            actual={cell.actual}
                                                            row={row}
                                                        />
                                                        <span className="text-xs text-muted-foreground">
                                                            Target{' '}
                                                            {formatTarget(
                                                                cell.target,
                                                                row.indicator,
                                                            )}
                                                            {cell.assessment
                                                                .achievement !==
                                                                null &&
                                                                ` · ${formatNumber(cell.assessment.achievement, 0)}%`}
                                                        </span>
                                                        {cell.assessment
                                                            .status !==
                                                            'no_data' && (
                                                            <PerformanceBadge
                                                                assessment={
                                                                    cell.assessment
                                                                }
                                                            />
                                                        )}
                                                    </div>
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>
                                        );
                                    })}
                                    <TableCell className="tabular-nums">
                                        {row.toDate?.value
                                            ? formatFigure(
                                                  row.toDate.value,
                                                  row.indicator,
                                              )
                                            : '—'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </Panel>
            <div className="grid gap-3 lg:grid-cols-2 print:hidden">
                {data.rows
                    .filter((row) =>
                        row.periods.some((cell) => cell.actual !== null),
                    )
                    .slice(0, 8)
                    .map((row) => (
                        <PerformanceTrend key={row.indicator.id} row={row} />
                    ))}
            </div>
        </>
    );
}

type FrameworkData = {
    results: {
        code: string;
        level: string;
        depth: number;
        statement: string;
        assumptions: string | null;
        meansOfVerification: string | null;
        indicators: {
            indicator: PerformanceRow['indicator'];
            baseline: PerformanceRow['baseline'];
            latest: Cell | null;
            toDate: PerformanceRow['toDate'];
        }[];
    }[];
};

function FrameworkReport({ data }: { data: FrameworkData }) {
    if (data.results.length === 0) {
        return <Empty>The framework has no results yet.</Empty>;
    }

    return (
        <Panel>
            <ul className="divide-y">
                {data.results.map((result) => (
                    <li
                        key={result.code}
                        className="grid gap-2 p-3"
                        style={{
                            paddingLeft: `${0.75 + (result.depth - 1) * 1.5}rem`,
                        }}
                    >
                        <div>
                            <div className="text-xs text-muted-foreground">
                                {result.level} {result.code}
                            </div>
                            <div className="text-sm font-medium">
                                {result.statement}
                            </div>
                        </div>
                        {result.indicators.length > 0 && (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-xs text-muted-foreground">
                                        <th className="py-1 font-medium">
                                            Indicator
                                        </th>
                                        <th className="py-1 font-medium">
                                            Baseline
                                        </th>
                                        <th className="py-1 font-medium">
                                            Latest
                                        </th>
                                        <th className="py-1 font-medium">
                                            Target
                                        </th>
                                        <th className="py-1 font-medium">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {result.indicators.map((item) => (
                                        <tr
                                            key={item.indicator.id}
                                            className="border-t"
                                        >
                                            <td className="py-1">
                                                <span className="font-medium">
                                                    {item.indicator.code}
                                                </span>{' '}
                                                {item.indicator.name}
                                            </td>
                                            <td className="py-1 tabular-nums">
                                                {item.baseline
                                                    ? formatFigure(
                                                          item.baseline.value,
                                                          item.indicator,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="py-1 tabular-nums">
                                                {item.latest?.actual?.value
                                                    ? `${formatFigure(item.latest.actual.value, item.indicator)} (${item.latest.period})`
                                                    : '—'}
                                            </td>
                                            <td className="py-1 tabular-nums">
                                                {item.latest
                                                    ? formatTarget(
                                                          item.latest.target,
                                                          item.indicator,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="py-1">
                                                {item.latest ? (
                                                    <PerformanceBadge
                                                        assessment={
                                                            item.latest
                                                                .assessment
                                                        }
                                                    />
                                                ) : (
                                                    <StatusBadge>
                                                        No data
                                                    </StatusBadge>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                        {(result.meansOfVerification || result.assumptions) && (
                            <dl className="grid gap-2 text-xs text-muted-foreground sm:grid-cols-2">
                                {result.meansOfVerification && (
                                    <div>
                                        <dt className="font-medium">
                                            Means of verification
                                        </dt>
                                        <dd className="whitespace-pre-line">
                                            {result.meansOfVerification}
                                        </dd>
                                    </div>
                                )}
                                {result.assumptions && (
                                    <div>
                                        <dt className="font-medium">
                                            Assumptions
                                        </dt>
                                        <dd className="whitespace-pre-line">
                                            {result.assumptions}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        )}
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

type TargetActualData = {
    period: { id: string; label: string } | null;
    rows: {
        indicator: PerformanceRow['indicator'];
        programme: Cell | null;
        units: { unit: string | null; cell: Cell | null }[];
    }[];
};

function TargetActual({ data }: { data: TargetActualData }) {
    if (data.period === null || data.rows.length === 0) {
        return <Empty>No indicators report in this period.</Empty>;
    }

    return (
        <Panel>
            <PanelHeader title={data.period.label} />
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Indicator</TableHead>
                            <TableHead>For</TableHead>
                            <TableHead className="text-right">Target</TableHead>
                            <TableHead className="text-right">Actual</TableHead>
                            <TableHead className="text-right">
                                % of target
                            </TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data.rows.flatMap((row) =>
                            [
                                { unit: 'Programme', cell: row.programme },
                                ...row.units,
                            ].map((entry, index) => (
                                <TableRow key={`${row.indicator.id}-${index}`}>
                                    <TableCell>
                                        {index === 0 && (
                                            <>
                                                <span className="font-medium">
                                                    {row.indicator.code}
                                                </span>{' '}
                                                {row.indicator.name}
                                            </>
                                        )}
                                    </TableCell>
                                    <TableCell
                                        className={
                                            index === 0 ? 'font-medium' : ''
                                        }
                                    >
                                        {entry.unit}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatTarget(
                                            entry.cell?.target ?? null,
                                            row.indicator,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {entry.cell?.actual?.value
                                            ? formatFigure(
                                                  entry.cell.actual.value,
                                                  row.indicator,
                                              )
                                            : '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {entry.cell?.assessment.achievement
                                            ? `${formatNumber(entry.cell.assessment.achievement, 0)}%`
                                            : '—'}
                                    </TableCell>
                                    <TableCell>
                                        {entry.cell && (
                                            <PerformanceBadge
                                                assessment={
                                                    entry.cell.assessment
                                                }
                                            />
                                        )}
                                    </TableCell>
                                </TableRow>
                            )),
                        )}
                    </TableBody>
                </Table>
            </div>
        </Panel>
    );
}

const COMPLETENESS_PARTS: {
    key: keyof Totals;
    label: string;
    fill: string;
    ink: string;
}[] = [
    {
        key: 'published',
        label: 'Published',
        fill: 'var(--viz-step-4)',
        ink: 'var(--viz-step-4-ink)',
    },
    {
        key: 'verified',
        label: 'Verified',
        fill: 'var(--viz-step-3)',
        ink: 'var(--viz-step-3-ink)',
    },
    {
        key: 'under_review',
        label: 'Under review',
        fill: 'var(--viz-step-2)',
        ink: 'var(--viz-step-2-ink)',
    },
    {
        key: 'submitted',
        label: 'Submitted',
        fill: 'var(--viz-step-1)',
        ink: 'var(--viz-step-1-ink)',
    },
    {
        key: 'rejected',
        label: 'Sent back',
        fill: 'var(--viz-warning)',
        ink: 'var(--viz-warning-ink)',
    },
    {
        key: 'draft',
        label: 'Draft',
        fill: 'var(--viz-baseline)',
        ink: 'var(--foreground)',
    },
    {
        key: 'missing',
        label: 'Missing',
        fill: 'var(--viz-grid)',
        ink: 'var(--foreground)',
    },
];

function CompletenessReport({
    data,
    verification,
}: {
    data: Completeness;
    verification: boolean;
}) {
    if (data.totals.expected === 0) {
        return <Empty>Nothing was expected in these periods.</Empty>;
    }

    const parts = COMPLETENESS_PARTS.map((part) => ({
        ...part,
        value: data.totals[part.key],
    }));
    const reported = data.totals.expected - data.totals.missing;

    return (
        <>
            <ChartPanel
                title={
                    verification
                        ? 'Results by stage'
                        : `${reported} of ${data.totals.expected} expected results entered`
                }
                description={
                    data.totals.overdue > 0
                        ? `${data.totals.overdue} past their due date with nothing entered.`
                        : undefined
                }
                columns={['Stage', 'Results']}
                rows={parts.map((part) => [part.label, part.value])}
            >
                <SegmentedBar parts={parts} label="Results by stage" />
            </ChartPanel>

            <Panel>
                <PanelHeader title="By period" />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Period</TableHead>
                            <TableHead>Due</TableHead>
                            <TableHead className="text-right">
                                Expected
                            </TableHead>
                            <TableHead className="text-right">
                                Missing
                            </TableHead>
                            <TableHead className="text-right">
                                In review
                            </TableHead>
                            <TableHead className="text-right">
                                Sent back
                            </TableHead>
                            <TableHead className="text-right">
                                Verified
                            </TableHead>
                            <TableHead className="text-right">
                                Published
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data.byPeriod
                            .filter((row) => row.totals.expected > 0)
                            .map((row) => (
                                <TableRow key={row.period.id}>
                                    <TableCell>{row.period.label}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatDate(row.period.dueOn)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.totals.expected}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.totals.missing}
                                        {row.totals.overdue > 0 && (
                                            <StatusBadge
                                                tone="danger"
                                                className="ml-1"
                                            >
                                                {row.totals.overdue} late
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.totals.submitted +
                                            row.totals.under_review}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.totals.rejected}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.totals.verified}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.totals.published}
                                    </TableCell>
                                </TableRow>
                            ))}
                    </TableBody>
                </Table>
            </Panel>

            <Panel>
                <PanelHeader title="By indicator and unit" />
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="min-w-56">
                                    Indicator
                                </TableHead>
                                <TableHead>Period</TableHead>
                                {data.units.map((unit) => (
                                    <TableHead key={unit.id} title={unit.name}>
                                        {unit.code}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.rows.map((row) => (
                                <TableRow
                                    key={`${row.indicator.id}-${row.period.id}`}
                                >
                                    <TableCell>
                                        <span className="font-medium">
                                            {row.indicator.code}
                                        </span>{' '}
                                        {row.indicator.name}
                                    </TableCell>
                                    <TableCell>{row.period.label}</TableCell>
                                    {row.cells.map((cell) => (
                                        <TableCell key={cell.unitId}>
                                            {cell.status === 'missing' ? (
                                                <StatusBadge
                                                    tone={
                                                        cell.overdue
                                                            ? 'danger'
                                                            : 'neutral'
                                                    }
                                                >
                                                    {cell.overdue
                                                        ? 'Late'
                                                        : 'Not yet'}
                                                </StatusBadge>
                                            ) : cell.submissionId ? (
                                                <Link
                                                    href={submissionPage(
                                                        cell.submissionId,
                                                    )}
                                                >
                                                    <StatusBadge
                                                        tone={
                                                            SUBMISSION[
                                                                cell.status
                                                            ].tone
                                                        }
                                                    >
                                                        {
                                                            SUBMISSION[
                                                                cell.status
                                                            ].label
                                                        }
                                                    </StatusBadge>
                                                </Link>
                                            ) : null}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </Panel>
        </>
    );
}

type ProgrammeData = {
    byPeriod: {
        period: string;
        counts: Record<PerformanceStatus, number>;
        indicators: number;
    }[];
    rows: PerformanceRow[];
    /** Why linked projects are not shown (Projects did not answer), or null. */
    projectsMessage: string | null;
    projects: {
        unit: string;
        project: string | null;
        status: string | null;
        progressPercent: number | null;
        currency: string | null;
        approvedBudgetMinor: number | null;
        paidMinor: number | null;
    }[];
};

const STATUS_ORDER: PerformanceStatus[] = [
    'on_track',
    'needs_attention',
    'off_track',
    'no_target',
    'no_data',
];

const STATUS_FILL: Record<PerformanceStatus, [string, string]> = {
    on_track: ['var(--viz-good)', 'var(--viz-good-ink)'],
    needs_attention: ['var(--viz-warning)', 'var(--viz-warning-ink)'],
    off_track: ['var(--viz-critical)', 'var(--viz-critical-ink)'],
    no_target: ['var(--viz-grid)', 'var(--foreground)'],
    no_data: ['var(--viz-baseline)', 'var(--foreground)'],
};

function ProgrammeReport({ data }: { data: ProgrammeData }) {
    return (
        <>
            {data.byPeriod.length === 0 ? (
                <Empty>No indicators to assess in these periods.</Empty>
            ) : (
                <Panel>
                    <PanelHeader
                        title="Indicators by status"
                        description="A count of indicators in each state, by period. Indicators are never added together into one score."
                    />
                    <ul className="divide-y">
                        {data.byPeriod.map((row) => (
                            <li
                                key={row.period}
                                className="grid gap-2 p-3 sm:grid-cols-[8rem_1fr] sm:items-center"
                            >
                                <span className="text-sm font-medium">
                                    {row.period}
                                </span>
                                <SegmentedBar
                                    label={`${row.period}: indicators by status`}
                                    parts={STATUS_ORDER.map((status) => ({
                                        key: status,
                                        label: PERFORMANCE[status].label,
                                        value: row.counts[status],
                                        fill: STATUS_FILL[status][0],
                                        ink: STATUS_FILL[status][1],
                                    }))}
                                />
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}

            {data.projectsMessage !== null && (
                <Panel>
                    <PanelHeader
                        title="Linked projects"
                        description={data.projectsMessage}
                    />
                </Panel>
            )}

            {data.projects.length > 0 && (
                <Panel>
                    <PanelHeader
                        title="Linked projects"
                        description="Delivery and spending from Project Management, shown beside results. They are not M&E results."
                    />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Unit</TableHead>
                                <TableHead>Project</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Done
                                </TableHead>
                                <TableHead className="text-right">
                                    Paid of approved budget
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.projects.map((project) => (
                                <TableRow key={project.unit}>
                                    <TableCell>{project.unit}</TableCell>
                                    <TableCell>{project.project}</TableCell>
                                    <TableCell>
                                        {project.status ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {project.progressPercent === null
                                            ? '—'
                                            : `${project.progressPercent}%`}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {project.approvedBudgetMinor === null
                                            ? '—'
                                            : `${((project.paidMinor ?? 0) / 100).toLocaleString()} of ${(project.approvedBudgetMinor / 100).toLocaleString()} ${project.currency ?? ''}`}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>
            )}

            {data.rows.length > 0 && (
                <div className="grid gap-3 lg:grid-cols-2">
                    {data.rows
                        .filter((row) =>
                            row.periods.some((cell) => cell.actual !== null),
                        )
                        .map((row) => (
                            <PerformanceTrend
                                key={row.indicator.id}
                                row={row}
                            />
                        ))}
                </div>
            )}
        </>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return (
        <Panel>
            <p className="p-6 text-center text-sm text-muted-foreground">
                {children}
            </p>
        </Panel>
    );
}
