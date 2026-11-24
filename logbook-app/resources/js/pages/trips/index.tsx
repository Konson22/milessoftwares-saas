import { Head, Link, router, usePage } from '@inertiajs/react';
import { Route } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Badge, SelectField, TextArea, TextField } from '@/components/fields';
import { PageHeader } from '@/components/page-header';
import { Toggle } from '@/components/work';
import type { ResourceOption } from '@/lib/logbook';
import { create as newEntry, show as showEntry } from '@/routes/entries';
import { cancel, index, store } from '@/routes/trips';

type Option = { value: string; label: string };

type Trip = {
    id: string;
    status: 'planned' | 'started' | 'done' | 'cancelled';
    day: string;
    time: string | null;
    destination: string | null;
    purpose: string | null;
    sheetId: string;
    sheet: string | null;
    resourceId: string;
    resource: string | null;
    entryId: string | null;
    driver: string;
    mine: boolean;
    cancelReason: string | null;
    canCancel: boolean;
};

type PlannableSheet = Option & {
    resourceType: string | null;
    assets: string[];
};

type Props = {
    trips: Trip[];
    filters: { status?: string; day?: string };
    labels: Record<string, string>;
    today: string;
    canPlan: boolean;
    sheets: PlannableSheet[];
    resources: ResourceOption[];
    members: Option[];
};

const TONES = {
    planned: 'info',
    started: 'warning',
    done: 'success',
    cancelled: 'neutral',
} as const;

/**
 * Planned trips (ADR-0032 §4). Managers plan a vehicle, a driver and a
 * day; the driver starts the trip here, which opens its entry with the
 * vehicle chosen. For its day a planned trip assigns the vehicle.
 */
export default function Trips({
    trips,
    filters,
    labels,
    today,
    canPlan,
    sheets,
    resources,
    members,
}: Props) {
    const filter = (patch: Record<string, string>) =>
        router.get(
            index().url,
            Object.fromEntries(
                Object.entries({ ...filters, ...patch }).filter(
                    ([, v]) => v !== '' && v !== undefined,
                ),
            ),
            { preserveState: true, preserveScroll: true },
        );

    return (
        <>
            <Head title="Trips" />
            <PageHeader
                title="Trips"
                description={
                    canPlan
                        ? 'Plan a trip: the vehicle, the driver and the day. The driver starts it from here or from the phone, and records the trip with that vehicle.'
                        : 'The trips planned for you. Start one to record it with the vehicle it was planned for.'
                }
            />
            {canPlan && (
                <div className="mb-4">
                    <Toggle label="Plan a trip">
                        {(close) =>
                            sheets.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No log sheet names a vehicle yet. Start one
                                    from the Vehicle movement starter in a
                                    logbook.
                                </p>
                            ) : (
                                <PlanForm
                                    sheets={sheets}
                                    resources={resources}
                                    members={members}
                                    today={today}
                                    onDone={close}
                                />
                            )
                        }
                    </Toggle>
                </div>
            )}
            <div className="mb-4 flex flex-wrap items-end gap-3">
                <SelectField
                    label="Status"
                    value={filters.status ?? ''}
                    onChange={(status) => filter({ status })}
                    options={Object.entries(labels).map(([value, label]) => ({
                        value,
                        label,
                    }))}
                    empty="Any status"
                />
                <TextField
                    label="Day"
                    type="date"
                    value={filters.day ?? ''}
                    onChange={(day) => filter({ day })}
                    hint={filters.day ? undefined : 'From a week ago on.'}
                />
            </div>
            <Panel>
                <PanelHeader title={`Trips (${trips.length})`} />
                {trips.length === 0 ? (
                    <EmptyState icon={Route} title="No trips">
                        {canPlan
                            ? 'Planned trips show here.'
                            : 'A manager plans trips for you.'}
                    </EmptyState>
                ) : (
                    <ul className="divide-y">
                        {trips.map((t) => (
                            <TripRow key={t.id} trip={t} labels={labels} />
                        ))}
                    </ul>
                )}
            </Panel>
        </>
    );
}

function TripRow({
    trip: t,
    labels,
}: {
    trip: Trip;
    labels: Record<string, string>;
}) {
    const [cancelling, setCancelling] = useState(false);
    const [reason, setReason] = useState('');

    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p className="flex flex-wrap items-center gap-2 font-medium">
                        {t.day}
                        {t.time ? ` at ${t.time}` : ''} · {t.resource}
                        <Badge tone={TONES[t.status]}>{labels[t.status]}</Badge>
                    </p>
                    <p className="text-muted-foreground">
                        {[t.destination, t.purpose]
                            .filter(Boolean)
                            .join(' · ') || 'No destination given'}
                    </p>
                    <p className="text-muted-foreground">
                        {t.mine ? 'You drive' : `Driver: ${t.driver}`} ·{' '}
                        {t.sheet}
                        {t.cancelReason
                            ? ` · Cancelled: ${t.cancelReason}`
                            : ''}
                    </p>
                </div>
                <div className="flex gap-2">
                    {t.mine && t.status === 'planned' && (
                        <Button asChild size="sm">
                            <Link
                                href={newEntry({
                                    query: { sheet: t.sheetId, dispatch: t.id },
                                })}
                            >
                                Start trip
                            </Link>
                        </Button>
                    )}
                    {t.entryId && (
                        <Button asChild size="sm" variant="outline">
                            <Link href={showEntry(t.entryId)}>
                                {t.status === 'started' && t.mine
                                    ? 'Continue'
                                    : 'Open entry'}
                            </Link>
                        </Button>
                    )}
                    {t.canCancel && !cancelling && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setCancelling(true)}
                        >
                            Cancel
                        </Button>
                    )}
                </div>
            </div>
            {cancelling && (
                <form
                    className="mt-3 grid gap-3 rounded-md border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            cancel(t.id).url,
                            { reason },
                            {
                                preserveScroll: true,
                                onSuccess: () => setCancelling(false),
                            },
                        );
                    }}
                >
                    <TextArea
                        label="Why (optional, the driver sees it)"
                        value={reason}
                        onChange={setReason}
                    />
                    <div className="flex gap-2">
                        <Button type="submit" variant="destructive">
                            Cancel the trip
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setCancelling(false)}
                        >
                            Keep it
                        </Button>
                    </div>
                </form>
            )}
        </li>
    );
}

function matches(r: ResourceOption, sheet: PlannableSheet | undefined) {
    if (!sheet) {
        return false;
    }
    if (sheet.assets.length > 0 && !sheet.assets.includes(r.value)) {
        return false;
    }
    if (!sheet.resourceType) {
        return true;
    }
    const w = sheet.resourceType.trim().toLowerCase();

    return r.type.toLowerCase() === w || (r.category ?? '').toLowerCase() === w;
}

function PlanForm({
    sheets,
    resources,
    members,
    today,
    onDone,
}: {
    sheets: PlannableSheet[];
    resources: ResourceOption[];
    members: Option[];
    today: string;
    onDone: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        sheet_id: sheets.length === 1 ? sheets[0].value : '',
        resource_id: '',
        user_id: '',
        planned_on: today,
        planned_time: '',
        destination: '',
        purpose: '',
    });
    const set = (patch: Partial<typeof data>) => setData({ ...data, ...patch });
    const sheet = sheets.find((s) => s.value === data.sheet_id);

    return (
        <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(store().url, data, {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <SelectField
                label="Log sheet"
                value={data.sheet_id}
                onChange={(sheet_id) => set({ sheet_id, resource_id: '' })}
                options={sheets}
                empty="Choose a log sheet…"
                error={errors.sheet_id}
            />
            <SelectField
                label="Vehicle"
                value={data.resource_id}
                onChange={(resource_id) => set({ resource_id })}
                options={resources.filter((r) => matches(r, sheet))}
                empty={
                    sheet ? 'Choose a vehicle…' : 'Choose the log sheet first'
                }
                error={errors.resource_id}
            />
            <SelectField
                label="Driver"
                value={data.user_id}
                onChange={(user_id) => set({ user_id })}
                options={members}
                empty="Choose a person…"
                error={errors.user_id}
            />
            <div className="grid grid-cols-2 gap-4">
                <TextField
                    label="Day"
                    type="date"
                    value={data.planned_on}
                    onChange={(planned_on) => set({ planned_on })}
                    error={errors.planned_on}
                />
                <TextField
                    label="Time (optional)"
                    type="time"
                    value={data.planned_time}
                    onChange={(planned_time) => set({ planned_time })}
                    error={errors.planned_time}
                />
            </div>
            <TextField
                label="Destination (optional)"
                value={data.destination}
                onChange={(destination) => set({ destination })}
                error={errors.destination}
            />
            <TextField
                label="Purpose (optional)"
                value={data.purpose}
                onChange={(purpose) => set({ purpose })}
                error={errors.purpose}
            />
            <div className="flex gap-2 sm:col-span-2">
                <Button type="submit">Plan the trip</Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
