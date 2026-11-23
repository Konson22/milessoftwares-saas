import { Link } from '@inertiajs/react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Detail } from '@/components/fields';
import { formatDateTime } from '@/lib/logbook';
import { show as entryPage } from '@/routes/entries';

export type Calculation = {
    method: 'distance' | 'hours';
    measure: 'odometer' | 'hour_meter' | 'duration';
    resource: string | null;
    inputs: { start: number | null; end: number | null };
    quantity: number;
    unit: 'km' | 'h';
    rate: {
        value: number;
        unit: string;
        fuel_type: string;
        effective_from: string;
    } | null;
    estimated_l: number | null;
    actual_l: number | null;
    refill_l: number | null;
    variance_l: number | null;
    variance_pct: number | null;
    gps_km: number | null;
    overlaps: { id: string; reference: string | null }[];
    flags: string[];
    phone: { estimated_l: number | null } | null;
    calculated_at: string;
};

const FLAG_TEXT: Record<string, string> = {
    no_rate:
        'No fuel rate was set for this resource at that time, so nothing was estimated.',
    rate_method_mismatch:
        "The resource's rate is set for another measure, so nothing was estimated.",
    rate_changed:
        'The phone estimated with a different rate than the one in force; the figure here uses the rate in force.',
    overlap:
        'These meter readings overlap another recorded activity on the same resource. Check that the distance or hours are not counted twice.',
};

/**
 * The fuel calculation kept at submission (ADR-0030 §4.7). A difference
 * from the estimate is only a difference: it is not a finding.
 */
export function CalculationPanel({
    calculation: c,
}: {
    calculation: Calculation;
}) {
    const litres = (n: number | null) => (n === null ? '—' : `${n} L`);
    const reading =
        c.measure === 'odometer'
            ? 'Odometer'
            : c.measure === 'hour_meter'
              ? 'Hour meter'
              : null;

    return (
        <Panel>
            <PanelHeader
                title="Fuel estimate"
                description={`Worked out when recorded, ${formatDateTime(c.calculated_at)}, and kept as it was.`}
            />
            <PanelBody className="grid gap-4">
                <dl className="grid gap-4 sm:grid-cols-3">
                    {c.resource && (
                        <Detail label="Resource">{c.resource}</Detail>
                    )}
                    {reading && (
                        <Detail label={reading}>
                            {c.inputs.start} → {c.inputs.end}
                        </Detail>
                    )}
                    <Detail
                        label={c.unit === 'km' ? 'Distance' : 'Running time'}
                    >
                        {c.quantity} {c.unit}
                        {c.gps_km !== null && (
                            <span className="text-muted-foreground">
                                {' '}
                                (route: {c.gps_km} km)
                            </span>
                        )}
                    </Detail>
                    <Detail label="Rate used">
                        {c.rate
                            ? `${c.rate.value} ${c.rate.unit}, ${c.rate.fuel_type}, from ${formatDateTime(c.rate.effective_from)}`
                            : 'None'}
                    </Detail>
                    <Detail label="Estimated">{litres(c.estimated_l)}</Detail>
                    <Detail label="Fuel measured">
                        {c.actual_l === null
                            ? 'Not recorded'
                            : litres(c.actual_l)}
                    </Detail>
                    {c.variance_l !== null && (
                        <Detail label="Difference from estimate">
                            {c.variance_l > 0 ? '+' : ''}
                            {c.variance_l} L
                            {c.variance_pct !== null &&
                                ` (${c.variance_pct > 0 ? '+' : ''}${c.variance_pct}%)`}
                        </Detail>
                    )}
                    {c.refill_l !== null && (
                        <Detail label="Refill">{litres(c.refill_l)}</Detail>
                    )}
                    {c.phone?.estimated_l != null && (
                        <Detail label="Phone's estimate">
                            {litres(c.phone.estimated_l)}
                        </Detail>
                    )}
                </dl>
                {c.flags.length > 0 && (
                    <ul className="grid gap-1 text-sm">
                        {c.flags.map((f) => (
                            <li
                                key={f}
                                className="rounded-md bg-[#fcf1cf] px-3 py-2 text-[#8a5a00]"
                            >
                                {FLAG_TEXT[f] ?? f}
                                {f === 'overlap' && c.overlaps.length > 0 && (
                                    <>
                                        {' '}
                                        {c.overlaps.map((o, i) => (
                                            <span key={o.id}>
                                                {i > 0 && ', '}
                                                <Link
                                                    href={entryPage(o.id)}
                                                    className="underline"
                                                >
                                                    {o.reference ?? 'entry'}
                                                </Link>
                                            </span>
                                        ))}
                                    </>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-xs text-muted-foreground">
                    The estimate comes from the resource's rate; a difference
                    can have many causes (load, roads, idling, a meter or rate
                    that needs checking) and is not a finding by itself. A
                    refill is shown beside it and is not the fuel used.
                </p>
            </PanelBody>
        </Panel>
    );
}
