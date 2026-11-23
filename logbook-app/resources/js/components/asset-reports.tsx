import { Link } from '@inertiajs/react';
import { Boxes, CalendarCheck } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Badge } from '@/components/fields';
import { show as resourceShow } from '@/routes/resources';

export type AssetRow = {
    id: string;
    label: string;
    type: string;
    kind: 'asset' | 'monitored_location';
    status: string;
    location: string | null;
    records: number;
    lastAt: string | null;
    outOfRange: number;
    openExceptions: number;
    expected: number;
    onTime: number;
    missed: number;
    fuelL: number | null;
};

export type MeasurementGroup = {
    resource: string | null;
    label: string;
    fields: {
        name: string;
        label: string;
        unit: string | null;
        count: number;
        min: number | null;
        max: number | null;
        average: number | null;
        /** Null for readings that do not add up (temperatures, levels). */
        sum: number | null;
        outOfRange: number;
    }[];
};

type ComplianceCounts = {
    expected: number;
    onTime: number;
    late: number;
    missed: number;
    excused: number;
    awaitingReview: number;
    open: number;
    upcoming: number;
    rate: number | null;
};

export type ComplianceData = {
    rows: (ComplianceCounts & {
        sheetId: string;
        sheet: string;
        resourceId: string | null;
        resource: string | null;
    })[];
    totals: ComplianceCounts;
};

const num = (n: number | null, unit?: string | null) =>
    n === null
        ? '—'
        : `${n.toLocaleString(undefined, { maximumFractionDigits: 2 })}${unit ? ` ${unit}` : ''}`;

const shell = 'overflow-hidden rounded-lg border bg-card';

/**
 * ADR-0031 §11: one row per asset. Clicking an asset narrows every view
 * to it; its name opens the asset page.
 */
export function AssetsView({
    data,
    onAsset,
}: {
    data: { rows: AssetRow[]; truncated: boolean };
    onAsset: (id: string) => void;
}) {
    if (data.rows.length === 0) {
        return (
            <section className={shell}>
                <EmptyState icon={Boxes} title="No assets match">
                    Register assets, or clear the asset filters.
                </EmptyState>
            </section>
        );
    }

    return (
        <section className={shell}>
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Asset</TableHead>
                            <TableHead>Location</TableHead>
                            <TableHead className="text-right">
                                Records
                            </TableHead>
                            <TableHead>Last record</TableHead>
                            <TableHead className="text-right">
                                Out of range
                            </TableHead>
                            <TableHead className="text-right">
                                Open exceptions
                            </TableHead>
                            <TableHead className="text-right">
                                Checks on time
                            </TableHead>
                            <TableHead className="text-right">Missed</TableHead>
                            <TableHead className="text-right">Fuel</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data.rows.map((r) => (
                            <TableRow key={r.id}>
                                <TableCell>
                                    <Link
                                        href={resourceShow(r.id)}
                                        className="font-medium hover:underline"
                                    >
                                        {r.label}
                                    </Link>
                                    <span className="block text-xs text-muted-foreground">
                                        {r.type}
                                        {r.kind === 'monitored_location' &&
                                            ' · monitored location'}
                                        {r.status !== 'active' &&
                                            ` · ${r.status}`}
                                    </span>
                                </TableCell>
                                <TableCell className="text-muted-foreground">
                                    {r.location ?? '—'}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {r.records > 0 ? (
                                        <button
                                            type="button"
                                            className="font-medium text-primary hover:underline"
                                            onClick={() => onAsset(r.id)}
                                            title="Show these records"
                                        >
                                            {r.records}
                                        </button>
                                    ) : (
                                        0
                                    )}
                                </TableCell>
                                <TableCell className="whitespace-nowrap text-muted-foreground">
                                    {r.lastAt ?? '—'}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {r.outOfRange > 0 ? (
                                        <Badge tone="danger">
                                            {r.outOfRange}
                                        </Badge>
                                    ) : (
                                        0
                                    )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {r.openExceptions > 0 ? (
                                        <Badge tone="warning">
                                            {r.openExceptions}
                                        </Badge>
                                    ) : (
                                        0
                                    )}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {r.expected === 0
                                        ? '—'
                                        : `${r.onTime} of ${r.expected}`}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {r.missed > 0 ? (
                                        <Badge tone="danger">{r.missed}</Badge>
                                    ) : r.expected === 0 ? (
                                        '—'
                                    ) : (
                                        0
                                    )}
                                </TableCell>
                                <TableCell className="text-right whitespace-nowrap tabular-nums">
                                    {num(r.fuelL, 'L')}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
            {data.truncated && (
                <p className="border-t px-4 py-2 text-xs text-muted-foreground">
                    Showing the first 500 assets: filter by category or location
                    to see the others.
                </p>
            )}
        </section>
    );
}

/**
 * One sheet's readings per asset: count, lowest, highest, average, and a
 * total only where readings add up.
 */
export function MeasurementsView({
    groups,
    sheetChosen,
}: {
    groups: MeasurementGroup[] | null;
    sheetChosen: boolean;
}) {
    if (!sheetChosen) {
        return (
            <p className="rounded-lg border bg-card px-4 py-3 text-sm text-muted-foreground">
                Choose a log sheet in the filters to see its readings per asset:
                lowest, highest and average.
            </p>
        );
    }
    if (groups === null || groups.length === 0) {
        return (
            <p className="rounded-lg border bg-card px-4 py-3 text-sm text-muted-foreground">
                This log sheet has no number readings in this period.
            </p>
        );
    }

    return (
        <section className={shell}>
            <h2 className="border-b px-4 py-3 text-sm font-semibold">
                Readings per asset
            </h2>
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Asset</TableHead>
                            <TableHead>Reading</TableHead>
                            <TableHead className="text-right">Count</TableHead>
                            <TableHead className="text-right">Lowest</TableHead>
                            <TableHead className="text-right">
                                Highest
                            </TableHead>
                            <TableHead className="text-right">
                                Average
                            </TableHead>
                            <TableHead className="text-right">Total</TableHead>
                            <TableHead className="text-right">
                                Out of range
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {groups.flatMap((g) =>
                            g.fields
                                .filter((f) => f.count > 0)
                                .map((f, i) => (
                                    <TableRow key={`${g.resource}-${f.name}`}>
                                        <TableCell className="font-medium">
                                            {i === 0 ? g.label : ''}
                                        </TableCell>
                                        <TableCell>{f.label}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {f.count}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {num(f.min, f.unit)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {num(f.max, f.unit)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {num(f.average, f.unit)}
                                        </TableCell>
                                        <TableCell
                                            className="text-right tabular-nums"
                                            title={
                                                f.sum === null
                                                    ? 'Not totalled: these readings do not add up'
                                                    : undefined
                                            }
                                        >
                                            {f.sum === null
                                                ? 'n/a'
                                                : num(f.sum, f.unit)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {f.outOfRange > 0 ? (
                                                <Badge tone="danger">
                                                    {f.outOfRange}
                                                </Badge>
                                            ) : (
                                                0
                                            )}
                                        </TableCell>
                                    </TableRow>
                                )),
                        )}
                    </TableBody>
                </Table>
            </div>
            <p className="border-t px-4 py-2 text-xs text-muted-foreground">
                Temperatures, levels and pressures are never added up: their
                total shows n/a.
            </p>
        </section>
    );
}

/**
 * ADR-0031 §11: expected readings by sheet and asset. On time and late
 * say when the entry came in, whatever its review.
 */
export function ComplianceView({
    data,
    onRow,
}: {
    data: ComplianceData;
    onRow: (sheetId: string, resourceId: string | null) => void;
}) {
    if (data.rows.length === 0) {
        return (
            <section className={shell}>
                <EmptyState
                    icon={CalendarCheck}
                    title="Nothing was expected in this period"
                >
                    Schedule log sheets, per asset or for the whole sheet, to
                    follow compliance here.
                </EmptyState>
            </section>
        );
    }
    const cells = (c: ComplianceCounts) => (
        <>
            <TableCell className="text-right tabular-nums">
                {c.expected}
            </TableCell>
            <TableCell className="text-right tabular-nums">
                {c.onTime}
            </TableCell>
            <TableCell className="text-right tabular-nums">{c.late}</TableCell>
            <TableCell className="text-right tabular-nums">
                {c.missed > 0 ? <Badge tone="danger">{c.missed}</Badge> : 0}
            </TableCell>
            <TableCell className="text-right tabular-nums">
                {c.excused}
            </TableCell>
            <TableCell className="text-right tabular-nums">
                {c.awaitingReview}
            </TableCell>
            <TableCell className="text-right tabular-nums">{c.open}</TableCell>
            <TableCell className="text-right font-medium tabular-nums">
                {c.rate === null ? '—' : `${c.rate}%`}
            </TableCell>
        </>
    );

    return (
        <section className={shell}>
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Log sheet</TableHead>
                            <TableHead>Asset</TableHead>
                            <TableHead className="text-right">
                                Expected
                            </TableHead>
                            <TableHead className="text-right">
                                On time
                            </TableHead>
                            <TableHead className="text-right">Late</TableHead>
                            <TableHead className="text-right">Missed</TableHead>
                            <TableHead className="text-right">
                                Excused
                            </TableHead>
                            <TableHead className="text-right">
                                Awaiting review
                            </TableHead>
                            <TableHead className="text-right">
                                Open now
                            </TableHead>
                            <TableHead className="text-right">
                                Completion
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {data.rows.map((r) => (
                            <TableRow key={`${r.sheetId}-${r.resourceId}`}>
                                <TableCell>
                                    <button
                                        type="button"
                                        className="text-left font-medium hover:underline"
                                        onClick={() =>
                                            onRow(r.sheetId, r.resourceId)
                                        }
                                        title="Show these records"
                                    >
                                        {r.sheet}
                                    </button>
                                </TableCell>
                                <TableCell className="text-muted-foreground">
                                    {r.resource ?? 'Whole sheet'}
                                </TableCell>
                                {cells(r)}
                            </TableRow>
                        ))}
                        <TableRow className="font-semibold">
                            <TableCell colSpan={2}>Total</TableCell>
                            {cells(data.totals)}
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
            <p className="border-t px-4 py-2 text-xs text-muted-foreground">
                Completion counts entries submitted, on time or late, out of
                those expected; excused readings are left out. Readings still to
                come in this period: {data.totals.upcoming}.
            </p>
        </section>
    );
}
