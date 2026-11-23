import { Head, Link, router, usePage } from '@inertiajs/react';
import { Boxes } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Badge, SelectField, Status, TextField } from '@/components/fields';
import { Toggle } from '@/components/work';
import { useCan } from '@/lib/access';
import * as locations from '@/routes/locations';
import { create, edit, show, templates } from '@/routes/resources';

type Resource = {
    id: string;
    code: string;
    name: string;
    type: string;
    kind: 'asset' | 'monitored_location';
    status: 'active' | 'inactive' | 'retired';
    description: string | null;
    locationId: string | null;
    location: string | null;
    active: boolean;
    responsible: string | null;
    template: string | null;
    /** Log sheets that record it (ADR-0031). */
    sheets: number;
    /** ADR-0030: the fuel rate in force, e.g. "12 L/100 km". */
    fuelRate: string | null;
};

type Location = {
    id: string;
    name: string;
    path: string;
    parentId: string | null;
    active: boolean;
};

type Props = { resources: Resource[]; locations: Location[]; types: string[] };

/**
 * The asset register (ADR-0031): equipment, vehicles and monitored places,
 * with what records them. Registering and changing happen on their own
 * page, from a template.
 */
export default function Resources({
    resources,
    locations: places,
    types,
}: Props) {
    const manage = useCan()('logbook.manage');
    const [q, setQ] = useState('');
    const [type, setType] = useState('');
    const [kind, setKind] = useState('');
    const [status, setStatus] = useState('current');
    const placeOptions = places
        .filter((l) => l.active)
        .map((l) => ({ value: l.id, label: l.path }));
    const needle = q.trim().toLowerCase();
    const shown = resources.filter(
        (r) =>
            (needle === '' ||
                `${r.code} ${r.name} ${r.location ?? ''} ${r.responsible ?? ''}`
                    .toLowerCase()
                    .includes(needle)) &&
            (type === '' || r.type === type) &&
            (kind === '' || r.kind === kind) &&
            (status === 'current'
                ? r.status !== 'retired'
                : status === '' || r.status === status),
    );

    return (
        <>
            <Head title="Resources" />
            <PageHeader
                title="Resources and locations"
                actions={
                    <>
                        <Button asChild variant="outline" size="sm">
                            <Link href={templates()}>Asset templates</Link>
                        </Button>
                        {manage && (
                            <Button asChild size="sm">
                                <Link href={create()}>Register an asset</Link>
                            </Button>
                        )}
                    </>
                }
            />
            {manage && (
                <div className="mb-4 flex flex-wrap gap-2">
                    <Toggle label="Add a location" variant="outline">
                        {(close) => (
                            <LocationForm
                                places={placeOptions}
                                onDone={close}
                            />
                        )}
                    </Toggle>
                </div>
            )}
            <div className="grid gap-6 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <PanelHeader title={`Assets (${shown.length})`} />
                    <div className="grid gap-3 border-b px-4 py-3 sm:grid-cols-4">
                        <TextField
                            label="Search"
                            value={q}
                            onChange={setQ}
                            placeholder="Code, name, place"
                        />
                        <SelectField
                            label="Category"
                            value={type}
                            onChange={setType}
                            options={types.map((t) => ({ value: t, label: t }))}
                            empty="All"
                        />
                        <SelectField
                            label="Kind"
                            value={kind}
                            onChange={setKind}
                            options={[
                                { value: 'asset', label: 'Physical assets' },
                                {
                                    value: 'monitored_location',
                                    label: 'Monitored locations',
                                },
                            ]}
                            empty="All"
                        />
                        <SelectField
                            label="Status"
                            value={status}
                            onChange={setStatus}
                            options={[
                                {
                                    value: 'current',
                                    label: 'Active and inactive',
                                },
                                { value: 'active', label: 'Active' },
                                { value: 'inactive', label: 'Inactive' },
                                { value: 'retired', label: 'Retired' },
                            ]}
                            empty="All"
                        />
                    </div>
                    {shown.length === 0 ? (
                        <EmptyState
                            icon={Boxes}
                            title={
                                resources.length === 0
                                    ? 'No assets yet'
                                    : 'Nothing matches'
                            }
                        >
                            {resources.length === 0
                                ? 'Register the generators, vehicles, cold rooms and units your teams log.'
                                : 'Change the filters to see more.'}
                        </EmptyState>
                    ) : (
                        <ul className="divide-y">
                            {shown.map((r) => (
                                <li key={r.id} className="px-4 py-3 text-sm">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <Link
                                                href={show(r.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {r.code} · {r.name}
                                            </Link>
                                            <p className="text-muted-foreground">
                                                {[
                                                    r.type,
                                                    r.location,
                                                    r.responsible &&
                                                        `responsible ${r.responsible}`,
                                                    r.fuelRate &&
                                                        `fuel ${r.fuelRate}`,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {r.sheets === 0
                                                    ? 'Not linked to a log sheet'
                                                    : `${r.sheets} ${r.sheets === 1 ? 'log sheet' : 'log sheets'}`}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {r.kind ===
                                                'monitored_location' && (
                                                <Badge tone="info">
                                                    Monitored location
                                                </Badge>
                                            )}
                                            <Status value={r.status} />
                                            {manage && (
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link href={edit(r.id)}>
                                                        Change
                                                    </Link>
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
                <Panel className="self-start">
                    <PanelHeader title="Locations" />
                    {places.length === 0 ? (
                        <p className="px-4 py-3 text-sm text-muted-foreground">
                            No locations yet.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {places.map((l) => (
                                <li
                                    key={l.id}
                                    className="flex items-center justify-between gap-2 px-4 py-2 text-sm"
                                >
                                    <span>{l.path}</span>
                                    {!l.active && <Status value="inactive" />}
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}

function LocationForm({
    places,
    onDone,
}: {
    places: { value: string; label: string }[];
    onDone: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [name, setName] = useState('');
    const [parent, setParent] = useState('');

    return (
        <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(
                    locations.store().url,
                    { name, parent_id: parent || null },
                    { preserveScroll: true, onSuccess: onDone },
                );
            }}
        >
            <TextField
                label="Name"
                value={name}
                onChange={setName}
                error={errors.name}
                placeholder="Plant room"
                required
            />
            <SelectField
                label="Inside"
                value={parent}
                onChange={setParent}
                options={places}
                empty="Nowhere (a site)"
                error={errors.parent_id}
            />
            <div className="sm:col-span-2">
                <Button type="submit">Add location</Button>
            </div>
        </form>
    );
}
