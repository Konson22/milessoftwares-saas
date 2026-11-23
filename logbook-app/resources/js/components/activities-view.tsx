import { Fuel, Gauge } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Pagination } from '@/components/entries-table';
import { Shell, useOpenRecord } from '@/components/report-views';
import type { Paginated } from '@/lib/logbook';
import { formatDateTime } from '@/lib/logbook';

export type FuelTotals = {
    entries: number;
    km: number;
    h: number;
    estimated_l: number;
    actual_l: number;
    refill_l: number;
    compared: number;
    variance_l: number | null;
    variance_pct: number | null;
    without_estimate: number;
    flagged: number;
};

export type ActivityRow = {
    id: string;
    reference: string | null;
    sheet: string;
    resource: string | null;
    operator: string;
    shift: string | null;
    startedAt: string;
    endedAt: string | null;
    durationH: number | null;
    quantity: number | null;
    unit: 'km' | 'h' | null;
    estimatedL: number | null;
    actualL: number | null;
    varianceL: number | null;
    variancePct: number | null;
    refillL: number | null;
    gpsKm: number | null;
    flags: string[];
    exceptions: number;
    track: string | null;
    status: string;
};

export type ActivitiesData = {
    summary: {
        count: number;
        limited: boolean;
        limit: number;
        total: FuelTotals;
        groups: Record<
            'resource' | 'operator' | 'shift' | 'month',
            (FuelTotals & { key: string; label: string })[]
        >;
    };
    rows: Paginated<ActivityRow>;
};

const GROUP_LABELS = {
    resource: 'By resource',
    operator: 'By operator',
    shift: 'By shift',
    month: 'By month',
} as const;

const FLAG_SHORT: Record<string, string> = {
    no_rate: 'no rate',
    rate_method_mismatch: 'rate for another measure',
    rate_changed: 'rate changed since the phone',
    overlap: 'readings overlap',
};

const litres = (n: number | null) =>
    n === null ? '—' : `${n.toLocaleString()} L`;
const signed = (n: number | null, unit: string) =>
    n === null ? '—' : `${n > 0 ? '+' : ''}${n.toLocaleString()}${unit}`;

/**
 * Activities and fuel (ADR-0030 §4.9). Totals come from the figure each
 * entry kept when it was recorded, one per entry. Estimated, measured and
 * refilled litres are kept apart; the difference covers only entries
 * that have both an estimate and a measurement.
 */
export function ActivitiesView({ data }: { data: ActivitiesData }) {
    const [group, setGroup] = useState<keyof typeof GROUP_LABELS>('resource');
    const open = useOpenRecord();
    const { summary, rows } = data;
    const t = summary.total;

    return (
        <div className="grid gap-5">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Tile
                    label="Activities"
                    value={summary.count.toLocaleString()}
                    note={
                        [
                            t.km > 0 && `${t.km.toLocaleString()} km`,
                            t.h > 0 && `${t.h.toLocaleString()} h`,
                        ]
                            .filter(Boolean)
                            .join(' · ') || undefined
                    }
                />
                <Tile
                    label="Estimated fuel"
                    value={litres(t.estimated_l)}
                    note={
                        t.without_estimate > 0
                            ? `${t.without_estimate} without an estimate`
                            : undefined
                    }
                />
                <Tile
                    label="Fuel measured"
                    value={litres(t.actual_l)}
                    note={
                        t.variance_l !== null
                            ? `${signed(t.variance_l, ' L')} from estimate over ${t.compared} with both`
                            : 'No measured figures to compare'
                    }
                />
                <Tile
                    label="Refilled"
                    value={litres(t.refill_l)}
                    note="Not the fuel used"
                />
            </div>
            {summary.limited && (
                <p className="text-sm text-muted-foreground">
                    Totals cover the first {summary.limit.toLocaleString()}{' '}
                    activities; narrow the filters for exact figures.
                </p>
            )}

            <Shell
                title="Fuel totals"
                icon={Fuel}
                note="from each entry's recorded figure"
            >
                <div className="flex flex-wrap gap-1 border-b px-4 py-2">
                    {(
                        Object.keys(
                            GROUP_LABELS,
                        ) as (keyof typeof GROUP_LABELS)[]
                    ).map((g) => (
                        <button
                            key={g}
                            type="button"
                            onClick={() => setGroup(g)}
                            aria-pressed={group === g}
                            className={`rounded-md px-3 py-1 text-sm ${
                                group === g
                                    ? 'bg-[#e3edfc] font-medium text-[#1d4fb8]'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {GROUP_LABELS[g]}
                        </button>
                    ))}
                </div>
                {summary.groups[group].length === 0 ? (
                    <p className="px-4 py-6 text-sm text-muted-foreground">
                        No activities in these filters.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr className="border-b">
                                    <th className="px-4 py-2">
                                        {GROUP_LABELS[group].replace('By ', '')}
                                    </th>
                                    <th className="px-4 py-2 text-right">
                                        Activities
                                    </th>
                                    <th className="px-4 py-2 text-right">
                                        Distance
                                    </th>
                                    <th className="px-4 py-2 text-right">
                                        Hours
                                    </th>
                                    <th className="px-4 py-2 text-right">
                                        Estimated
                                    </th>
                                    <th className="px-4 py-2 text-right">
                                        Measured
                                    </th>
                                    <th className="px-4 py-2 text-right">
                                        Difference
                                    </th>
                                    <th className="px-4 py-2 text-right">
                                        Refilled
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {summary.groups[group].map((r) => (
                                    <tr key={r.key}>
                                        <td className="px-4 py-2">
                                            {r.label}
                                            {r.flagged > 0 && (
                                                <span className="ml-2 text-xs text-[#8a5a00]">
                                                    {r.flagged} to check
                                                </span>
                                            )}
                                        </td>
                                        <Num>{r.entries}</Num>
                                        <Num>
                                            {r.km > 0
                                                ? `${r.km.toLocaleString()} km`
                                                : '—'}
                                        </Num>
                                        <Num>
                                            {r.h > 0
                                                ? `${r.h.toLocaleString()} h`
                                                : '—'}
                                        </Num>
                                        <Num>{litres(r.estimated_l)}</Num>
                                        <Num>
                                            {r.compared > 0 || r.actual_l > 0
                                                ? litres(r.actual_l)
                                                : '—'}
                                        </Num>
                                        <Num>
                                            {r.variance_l === null
                                                ? '—'
                                                : `${signed(r.variance_l, ' L')}${r.variance_pct !== null ? ` (${signed(r.variance_pct, '%')})` : ''}`}
                                        </Num>
                                        <Num>
                                            {r.refill_l > 0
                                                ? litres(r.refill_l)
                                                : '—'}
                                        </Num>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <p className="border-t px-4 py-2 text-xs text-muted-foreground">
                    Differences are over activities with both an estimate and a
                    measured figure. A difference is a prompt to look, not a
                    finding: load, roads, idling, a meter or a rate can all
                    explain it.
                </p>
            </Shell>

            <Shell
                title="Activities"
                icon={Gauge}
                note={`${rows.total.toLocaleString()} recorded`}
            >
                {rows.data.length === 0 ? (
                    <p className="px-4 py-6 text-sm text-muted-foreground">
                        No activities in these filters. Activities come from
                        event-based sheets and sheets with a fuel estimate.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-left text-xs text-muted-foreground">
                                <tr className="border-b">
                                    <th className="px-4 py-2">Reference</th>
                                    <th className="px-4 py-2">Resource</th>
                                    <th className="px-4 py-2">Operator</th>
                                    <th className="px-4 py-2">Started</th>
                                    <th className="px-4 py-2 text-right">
                                        Duration
                                    </th>
                                    <th className="px-4 py-2 text-right">
                                        Quantity
                                    </th>
                                    <th className="px-4 py-2 text-right">
                                        Estimated
                                    </th>
                                    <th className="px-4 py-2 text-right">
                                        Measured
                                    </th>
                                    <th className="px-4 py-2 text-right">
                                        Difference
                                    </th>
                                    <th className="px-4 py-2 text-right">
                                        Refill
                                    </th>
                                    <th className="px-4 py-2">Notes</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.data.map((r) => (
                                    <tr
                                        key={r.id}
                                        onClick={() => open(r.id)}
                                        className="cursor-pointer hover:bg-[#f6f8fa]"
                                    >
                                        <td className="px-4 py-2 whitespace-nowrap">
                                            <span className="font-medium">
                                                {r.reference ?? 'Draft'}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {r.sheet}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2">
                                            {r.resource ?? '—'}
                                        </td>
                                        <td className="px-4 py-2">
                                            {r.operator}
                                            {r.shift && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {r.shift}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-2 whitespace-nowrap">
                                            {formatDateTime(r.startedAt)}
                                        </td>
                                        <Num>
                                            {r.durationH === null
                                                ? '—'
                                                : `${r.durationH} h`}
                                        </Num>
                                        <Num>
                                            {r.quantity === null
                                                ? '—'
                                                : `${r.quantity} ${r.unit}`}
                                            {r.gpsKm !== null && (
                                                <span className="block text-xs text-muted-foreground">
                                                    route {r.gpsKm} km
                                                </span>
                                            )}
                                        </Num>
                                        <Num>{litres(r.estimatedL)}</Num>
                                        <Num>{litres(r.actualL)}</Num>
                                        <Num>
                                            {r.varianceL === null
                                                ? '—'
                                                : signed(r.varianceL, ' L')}
                                        </Num>
                                        <Num>{litres(r.refillL)}</Num>
                                        <td className="px-4 py-2 text-xs text-muted-foreground">
                                            {[
                                                ...r.flags.map(
                                                    (f) => FLAG_SHORT[f] ?? f,
                                                ),
                                                r.exceptions > 0 &&
                                                    `${r.exceptions} exception${r.exceptions === 1 ? '' : 's'}`,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') || '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination page={rows} />
            </Shell>
        </div>
    );
}

function Tile({
    label,
    value,
    note,
}: {
    label: string;
    value: string;
    note?: string;
}) {
    return (
        <div className="rounded-lg border bg-card px-4 py-3 shadow-[0_1px_2px_#e3e3e3]">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-1 text-xl font-semibold">{value}</p>
            {note && (
                <p className="mt-0.5 text-xs text-muted-foreground">{note}</p>
            )}
        </div>
    );
}

function Num({ children }: { children: ReactNode }) {
    return (
        <td className="px-4 py-2 text-right whitespace-nowrap tabular-nums">
            {children}
        </td>
    );
}
