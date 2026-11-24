import { Head, Link, router, usePage } from '@inertiajs/react';
import { Fuel, Gauge, UserRound } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Badge,
    SelectField,
    Status,
    TextArea,
    TextField,
} from '@/components/fields';
import {
    AssetOverview,
    type AssetIdentity,
    type AssetOperations,
} from '@/components/asset-overview';
import { PageHeader } from '@/components/page-header';
import { Toggle } from '@/components/work';
import { edit, index as resourcesIndex } from '@/routes/resources';
import * as assignmentRoutes from '@/routes/resources/assignments';
import * as fuelRates from '@/routes/resources/fuel-rates';
import * as meterEvents from '@/routes/resources/meter-events';

type Rate = {
    id: string;
    fuelType: 'diesel' | 'petrol' | 'other';
    method: 'distance' | 'hours';
    rate: number;
    unit: string;
    effectiveFrom: string;
    note: string | null;
    by: string;
    at: string;
    upcoming: boolean;
};

type MeterEventRow = {
    id: string;
    measure: 'odometer' | 'hour_meter';
    kind: 'replaced' | 'reset' | 'corrected';
    reading: number;
    occurredAt: string;
    reason: string;
    by: string;
};

type Assignment = {
    id: string;
    user: string;
    userId: string;
    from: string;
    to: string | null;
    note: string | null;
    by: string;
    endedAt: string | null;
    state: 'current' | 'upcoming' | 'over' | 'cancelled';
};

type Props = AssetOperations & {
    /** ADR-0032: who the asset is assigned to (a vehicle's drivers). */
    assignments: Assignment[];
    members: { value: string; label: string }[];
    today: string;
    resource: AssetIdentity & { active: boolean };
    currentRate: string | null;
    rates: Rate[];
    meterEvents: MeterEventRow[];
    history: { id: string; action: string; user: string; at: string }[];
    labels: Record<string, string>;
    now: string;
    canManage: boolean;
};

const FUELS = [
    { value: 'diesel', label: 'Diesel' },
    { value: 'petrol', label: 'Petrol' },
    { value: 'other', label: 'Other' },
];

/**
 * A resource's fuel rates and meter events (ADR-0030 §4.6, §4.8). Rates
 * are never edited: a new rate from a date on replaces the old one, and
 * entries keep the rate they were calculated with.
 */
export default function ResourceShow({
    resource,
    currentRate,
    rates,
    meterEvents: events,
    history,
    labels,
    now,
    canManage,
    assignments,
    members,
    today,
    ...ops
}: Props) {
    const [tab, setTab] = useState<
        'overview' | 'assigned' | 'fuel' | 'meters' | 'history'
    >('overview');
    const isVehicle = resource.category === 'Vehicles';
    const current = rates.find((r) => r.id === currentRate) ?? null;

    return (
        <>
            <Head title={`${resource.code} · ${resource.name}`} />
            <PageHeader
                title={`${resource.code} · ${resource.name}`}
                description={
                    <>
                        <Link
                            href={resourcesIndex()}
                            className="hover:underline"
                        >
                            Resources
                        </Link>{' '}
                        ·{' '}
                        {[resource.category, resource.type, resource.location]
                            .filter(Boolean)
                            .join(' · ')}
                    </>
                }
                badges={<Status value={resource.status} />}
                actions={
                    canManage && (
                        <Button asChild size="sm" variant="outline">
                            <Link href={edit(resource.id)}>Change</Link>
                        </Button>
                    )
                }
            />

            <div className="mb-4 flex gap-2" role="tablist">
                {(
                    [
                        ['overview', 'Overview'],
                        ['assigned', isVehicle ? 'Drivers' : 'Assigned to'],
                        ['fuel', 'Fuel rates'],
                        ['meters', 'Meters'],
                        ['history', 'History'],
                    ] as const
                ).map(([key, label]) => (
                    <Button
                        key={key}
                        role="tab"
                        aria-selected={tab === key}
                        size="sm"
                        variant={tab === key ? 'default' : 'outline'}
                        onClick={() => setTab(key)}
                    >
                        {label}
                    </Button>
                ))}
            </div>

            {tab === 'overview' && (
                <AssetOverview
                    resource={resource}
                    ops={ops}
                    canManage={canManage}
                />
            )}

            {tab === 'assigned' && (
                <Panel>
                    <PanelHeader
                        title={isVehicle ? 'Drivers' : 'Assigned to'}
                        description="On sheets set to assigned only, a person records this asset only on the days it is assigned to them. Only managers assign."
                    />
                    {canManage && resource.status !== 'retired' && (
                        <div className="border-b px-4 py-3">
                            <Toggle
                                label={
                                    isVehicle
                                        ? 'Assign a driver'
                                        : 'Assign a person'
                                }
                            >
                                {(close) => (
                                    <AssignForm
                                        resourceId={resource.id}
                                        members={members}
                                        today={today}
                                        onDone={close}
                                    />
                                )}
                            </Toggle>
                        </div>
                    )}
                    {assignments.length === 0 ? (
                        <EmptyState
                            icon={UserRound}
                            title="Not assigned to anyone"
                        >
                            {canManage
                                ? 'Assign it to the people who use it.'
                                : 'A manager assigns it.'}
                        </EmptyState>
                    ) : (
                        <ul className="divide-y">
                            {assignments.map((a) => (
                                <li
                                    key={a.id}
                                    className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm"
                                >
                                    <div>
                                        <p className="flex items-center gap-2 font-medium">
                                            {a.user}
                                            <Badge>
                                                {ASSIGNMENT_STATES[a.state]}
                                            </Badge>
                                        </p>
                                        <p className="text-muted-foreground">
                                            {a.state === 'cancelled'
                                                ? `Was to start ${a.from}`
                                                : a.to
                                                  ? `${a.from} to ${a.to}`
                                                  : `From ${a.from}, until ended`}
                                            {a.note ? ` · ${a.note}` : ''} ·
                                            assigned by {a.by}
                                        </p>
                                    </div>
                                    {canManage &&
                                        (a.state === 'current' ||
                                            a.state === 'upcoming') &&
                                        a.endedAt === null && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        assignmentRoutes.end({
                                                            resource:
                                                                resource.id,
                                                            assignment: a.id,
                                                        }).url,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {a.state === 'upcoming'
                                                    ? 'Cancel'
                                                    : 'End today'}
                                            </Button>
                                        )}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            )}

            {tab === 'fuel' && (
                <div className="mb-4 grid gap-4 sm:grid-cols-3">
                    <Panel>
                        <PanelBody>
                            <p className="text-xs text-muted-foreground">
                                Fuel rate now
                            </p>
                            <p className="mt-1 text-xl font-semibold">
                                {current
                                    ? `${current.rate} ${current.unit}`
                                    : 'Not set'}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {current
                                    ? `${labels[current.method]} · ${current.fuelType} · since ${current.effectiveFrom}`
                                    : 'No fuel is estimated for this resource.'}
                            </p>
                        </PanelBody>
                    </Panel>
                    <Panel>
                        <PanelBody>
                            <p className="text-xs text-muted-foreground">
                                Rates on record
                            </p>
                            <p className="mt-1 text-xl font-semibold">
                                {rates.length}
                            </p>
                        </PanelBody>
                    </Panel>
                    <Panel>
                        <PanelBody>
                            <p className="text-xs text-muted-foreground">
                                Meter events
                            </p>
                            <p className="mt-1 text-xl font-semibold">
                                {events.length}
                            </p>
                        </PanelBody>
                    </Panel>
                </div>
            )}

            {tab === 'fuel' && (
                <Panel>
                    <PanelHeader
                        title="Fuel rates"
                        description="The rate in force when an activity happens is used for its estimate. Add a new rate to change it; past entries keep theirs."
                    />
                    {canManage && (
                        <div className="border-b px-4 py-3">
                            <Toggle label="Add a rate">
                                {(close) => (
                                    <RateForm
                                        resourceId={resource.id}
                                        last={rates[0] ?? null}
                                        now={now}
                                        onDone={close}
                                    />
                                )}
                            </Toggle>
                        </div>
                    )}
                    {rates.length === 0 ? (
                        <EmptyState icon={Fuel} title="No fuel rate">
                            Resources without a rate, such as rooms or
                            computers, get no fuel estimate.
                        </EmptyState>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-left text-xs text-muted-foreground">
                                    <tr className="border-b">
                                        <th className="px-4 py-2">From</th>
                                        <th className="px-4 py-2">Rate</th>
                                        <th className="px-4 py-2">Fuel</th>
                                        <th className="px-4 py-2">Note</th>
                                        <th className="px-4 py-2">Added</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {rates.map((r) => (
                                        <tr key={r.id}>
                                            <td className="px-4 py-2 whitespace-nowrap">
                                                {r.effectiveFrom}{' '}
                                                {r.id === currentRate && (
                                                    <Badge tone="success">
                                                        In force
                                                    </Badge>
                                                )}
                                                {r.upcoming && (
                                                    <Badge tone="info">
                                                        Upcoming
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 font-medium whitespace-nowrap">
                                                {r.rate} {r.unit}
                                            </td>
                                            <td className="px-4 py-2 capitalize">
                                                {r.fuelType}
                                            </td>
                                            <td className="px-4 py-2 text-muted-foreground">
                                                {r.note ?? '—'}
                                            </td>
                                            <td className="px-4 py-2 whitespace-nowrap text-muted-foreground">
                                                {r.by}, {r.at}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>
            )}

            {tab === 'meters' && (
                <Panel>
                    <PanelHeader
                        title="Meter events"
                        description="When an odometer or hour meter is replaced, reset or corrected, record it here. Otherwise a start reading below the last end reading is refused."
                    />
                    {canManage && (
                        <div className="border-b px-4 py-3">
                            <Toggle label="Record a meter event">
                                {(close) => (
                                    <MeterForm
                                        resourceId={resource.id}
                                        now={now}
                                        onDone={close}
                                    />
                                )}
                            </Toggle>
                        </div>
                    )}
                    {events.length === 0 ? (
                        <EmptyState icon={Gauge} title="No meter events">
                            Readings are expected to keep going up.
                        </EmptyState>
                    ) : (
                        <ul className="divide-y">
                            {events.map((e) => (
                                <li key={e.id} className="px-4 py-3 text-sm">
                                    <p className="font-medium">
                                        {labels[e.kind]} · {labels[e.measure]} ·
                                        now reads {e.reading}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {e.occurredAt} · {e.reason} · recorded
                                        by {e.by}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            )}

            {tab === 'history' && (
                <Panel>
                    <PanelHeader title="History" />
                    {history.length === 0 ? (
                        <p className="px-4 py-3 text-sm text-muted-foreground">
                            Nothing recorded yet.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {history.map((h) => (
                                <li
                                    key={h.id}
                                    className="flex justify-between gap-2 px-4 py-2 text-sm"
                                >
                                    <span>
                                        {h.action
                                            .replace('resource.', '')
                                            .replaceAll('_', ' ')}{' '}
                                        · {h.user}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {h.at}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            )}
        </>
    );
}

const ASSIGNMENT_STATES: Record<Assignment['state'], string> = {
    current: 'Current',
    upcoming: 'Upcoming',
    over: 'Over',
    cancelled: 'Cancelled',
};

function AssignForm({
    resourceId,
    members,
    today,
    onDone,
}: {
    resourceId: string;
    members: { value: string; label: string }[];
    today: string;
    onDone: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        user_id: '',
        starts_on: today,
        ends_on: '',
        note: '',
    });
    const set = (patch: Partial<typeof data>) => setData({ ...data, ...patch });

    return (
        <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(assignmentRoutes.store(resourceId).url, data, {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <SelectField
                label="Person"
                value={data.user_id}
                onChange={(user_id) => set({ user_id })}
                options={members}
                empty="Choose a person…"
                error={errors.user_id}
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
                hint="Leave empty to keep it until ended."
            />
            <TextArea
                label="Note (optional)"
                value={data.note}
                onChange={(note) => set({ note })}
                error={errors.note}
                className="sm:col-span-2"
            />
            <div className="flex gap-2 sm:col-span-2">
                <Button type="submit">Assign</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function RateForm({
    resourceId,
    last,
    now,
    onDone,
}: {
    resourceId: string;
    last: Rate | null;
    now: string;
    onDone: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        fuel_type: last?.fuelType ?? 'diesel',
        method: last?.method ?? 'distance',
        rate: '',
        effective_from: now,
        note: '',
    });
    const set = (patch: Partial<typeof data>) => setData({ ...data, ...patch });

    return (
        <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(fuelRates.store(resourceId).url, data, {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <SelectField
                label="Calculated"
                value={data.method}
                onChange={(method) =>
                    set({ method: method as typeof data.method })
                }
                options={[
                    { value: 'distance', label: 'By distance (L/100 km)' },
                    { value: 'hours', label: 'By running hours (L/h)' },
                ]}
                error={errors.method}
            />
            <SelectField
                label="Fuel"
                value={data.fuel_type}
                onChange={(fuel_type) =>
                    set({ fuel_type: fuel_type as typeof data.fuel_type })
                }
                options={FUELS}
                error={errors.fuel_type}
            />
            <TextField
                label={
                    data.method === 'distance'
                        ? 'Litres per 100 km'
                        : 'Litres per hour'
                }
                value={data.rate}
                onChange={(rate) => set({ rate })}
                inputMode="decimal"
                error={errors.rate}
            />
            <TextField
                label="Applies from"
                type="datetime-local"
                value={data.effective_from}
                onChange={(effective_from) => set({ effective_from })}
                error={errors.effective_from}
                hint="Activities from this moment use it. Earlier entries keep the rate they were calculated with."
            />
            <TextArea
                label="Note (optional)"
                value={data.note}
                onChange={(note) => set({ note })}
                error={errors.note}
                className="sm:col-span-2"
            />
            <div className="flex gap-2 sm:col-span-2">
                <Button type="submit">Add rate</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function MeterForm({
    resourceId,
    now,
    onDone,
}: {
    resourceId: string;
    now: string;
    onDone: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        measure: 'odometer',
        kind: 'replaced',
        reading: '0',
        occurred_at: now,
        reason: '',
    });
    const set = (patch: Partial<typeof data>) => setData({ ...data, ...patch });

    return (
        <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(meterEvents.store(resourceId).url, data, {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <SelectField
                label="Meter"
                value={data.measure}
                onChange={(measure) => set({ measure })}
                options={[
                    { value: 'odometer', label: 'Odometer (km)' },
                    { value: 'hour_meter', label: 'Hour meter (h)' },
                ]}
                error={errors.measure}
            />
            <SelectField
                label="What happened"
                value={data.kind}
                onChange={(kind) => set({ kind })}
                options={[
                    { value: 'replaced', label: 'Meter replaced' },
                    { value: 'reset', label: 'Meter reset' },
                    { value: 'corrected', label: 'Reading corrected' },
                ]}
                error={errors.kind}
            />
            <TextField
                label="It reads now"
                value={data.reading}
                onChange={(reading) => set({ reading })}
                inputMode="decimal"
                error={errors.reading}
            />
            <TextField
                label="When"
                type="datetime-local"
                value={data.occurred_at}
                onChange={(occurred_at) => set({ occurred_at })}
                error={errors.occurred_at}
            />
            <TextArea
                label="Why"
                value={data.reason}
                onChange={(reason) => set({ reason })}
                error={errors.reason}
                className="sm:col-span-2"
            />
            <div className="flex gap-2 sm:col-span-2">
                <Button type="submit">Record</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
