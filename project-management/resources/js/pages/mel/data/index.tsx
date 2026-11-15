import { Head, Link, router } from '@inertiajs/react';
import { SquarePen, Upload } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
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
import { selectClass } from '@/mel/components/fields';
import { SUBMISSION, formatDate, formatFigure } from '@/mel/lib/mel';
import type { Measure, Option, SubmissionStatus } from '@/mel/lib/mel';
import { entry, index } from '@/routes/mel/data';
import { show as importPage } from '@/routes/mel/import';
import { show as submission } from '@/routes/mel/submissions';

type Props = {
    programmes: Option[];
    periods: Option[];
    selected: { programme: string | null; period: string | null };
    grid: {
        period: {
            id: string;
            label: string;
            dueOn: string;
            open: boolean;
            overdue: boolean;
        };
        units: { id: string; code: string; name: string }[];
        rows: {
            id: string;
            code: string;
            name: string;
            measure: Measure | null;
            decimals: number;
            unitLabel: string | null;
            cells: {
                unitId: string;
                status: SubmissionStatus | 'missing';
                submissionId: string | null;
                value: string | null;
            }[];
        }[];
        canEnter: boolean;
    } | null;
    canImport: boolean;
};

export default function DataEntry({
    programmes,
    periods,
    selected,
    grid,
    canImport,
}: Props) {
    const go = (query: Record<string, string>) =>
        router.get(index.url({ query }), {}, { preserveScroll: true });

    return (
        <>
            <Head title="Data entry" />
            <PageHeader
                title="Data entry"
                actions={
                    canImport &&
                    selected.programme && (
                        <Button variant="outline" asChild>
                            <Link href={importPage(selected.programme)}>
                                <Upload aria-hidden />
                                Import a sheet
                            </Link>
                        </Button>
                    )
                }
            />

            <Panel>
                <PanelToolbar>
                    <select
                        aria-label="Programme"
                        value={selected.programme ?? ''}
                        onChange={(event) =>
                            go({ programme: event.target.value })
                        }
                        className={`${selectClass} w-auto`}
                    >
                        {programmes.map((programme) => (
                            <option
                                key={programme.value}
                                value={programme.value}
                            >
                                {programme.label}
                            </option>
                        ))}
                    </select>
                    {periods.length > 0 && (
                        <select
                            aria-label="Period"
                            value={selected.period ?? ''}
                            onChange={(event) =>
                                go({
                                    programme: selected.programme ?? '',
                                    period: event.target.value,
                                })
                            }
                            className={`${selectClass} w-auto`}
                        >
                            {periods.map((period) => (
                                <option key={period.value} value={period.value}>
                                    {period.label}
                                </option>
                            ))}
                        </select>
                    )}
                    {grid && (
                        <span className="text-sm text-muted-foreground">
                            {grid.period.open ? (
                                <>
                                    Due {formatDate(grid.period.dueOn)}
                                    {grid.period.overdue && (
                                        <StatusBadge
                                            tone="danger"
                                            className="ml-2"
                                        >
                                            Past due
                                        </StatusBadge>
                                    )}
                                </>
                            ) : (
                                <StatusBadge>Closed for entry</StatusBadge>
                            )}
                        </span>
                    )}
                </PanelToolbar>

                {grid === null ? (
                    <EmptyState icon={SquarePen} title="Nothing to enter yet">
                        {programmes.length === 0
                            ? 'You have no programmes yet.'
                            : 'This programme has no reporting periods yet.'}
                    </EmptyState>
                ) : grid.rows.length === 0 || grid.units.length === 0 ? (
                    <EmptyState icon={SquarePen} title="Nothing to enter">
                        {grid.units.length === 0
                            ? 'No reporting units you can enter for.'
                            : `No active indicators report in ${grid.period.label}.`}
                    </EmptyState>
                ) : (
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="min-w-64">
                                        Indicator
                                    </TableHead>
                                    {grid.units.map((unit) => (
                                        <TableHead
                                            key={unit.id}
                                            className="min-w-32"
                                            title={unit.name}
                                        >
                                            {unit.code}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {grid.rows.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>
                                            <span className="font-medium">
                                                {row.code}
                                            </span>{' '}
                                            {row.name}
                                        </TableCell>
                                        {row.cells.map((cell) => {
                                            const enter =
                                                grid.canEnter &&
                                                selected.programme
                                                    ? entry({
                                                          programme:
                                                              selected.programme,
                                                          period: grid.period
                                                              .id,
                                                          indicator: row.id,
                                                          unit: cell.unitId,
                                                      })
                                                    : null;
                                            const href =
                                                cell.status === 'missing' ||
                                                cell.status === 'draft' ||
                                                cell.status === 'rejected'
                                                    ? enter
                                                    : cell.submissionId
                                                      ? submission(
                                                            cell.submissionId,
                                                        )
                                                      : null;

                                            return (
                                                <TableCell key={cell.unitId}>
                                                    <GridCell
                                                        cell={cell}
                                                        row={row}
                                                        href={href}
                                                    />
                                                </TableCell>
                                            );
                                        })}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </Panel>
        </>
    );
}

function GridCell({
    cell,
    row,
    href,
}: {
    cell: NonNullable<Props['grid']>['rows'][number]['cells'][number];
    row: NonNullable<Props['grid']>['rows'][number];
    href: ReturnType<typeof entry> | null;
}) {
    const content =
        cell.status === 'missing' ? (
            <span className="text-muted-foreground">
                {href ? 'Enter' : '—'}
            </span>
        ) : (
            <span className="grid gap-0.5">
                <span className="tabular-nums">
                    {row.measure
                        ? formatFigure(cell.value, {
                              measure: row.measure,
                              decimals: row.decimals,
                              unitLabel: null,
                          })
                        : '—'}
                </span>
                <StatusBadge tone={SUBMISSION[cell.status].tone}>
                    {SUBMISSION[cell.status].label}
                </StatusBadge>
            </span>
        );

    return href ? (
        <Link href={href} className="block hover:underline">
            {content}
        </Link>
    ) : (
        content
    );
}
