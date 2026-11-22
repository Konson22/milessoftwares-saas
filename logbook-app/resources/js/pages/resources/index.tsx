import { Head, router, usePage } from '@inertiajs/react';
import { Boxes } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Check,
    SelectField,
    Status,
    TextArea,
    TextField,
} from '@/components/fields';
import { Toggle } from '@/components/work';
import { useCan } from '@/lib/access';
import * as locations from '@/routes/locations';
import { store, update } from '@/routes/resources';

type Resource = {
    id: string;
    code: string;
    name: string;
    type: string;
    description: string | null;
    locationId: string | null;
    location: string | null;
    active: boolean;
};

type Location = {
    id: string;
    name: string;
    path: string;
    parentId: string | null;
    active: boolean;
};

type Props = { resources: Resource[]; locations: Location[]; types: string[] };

export default function Resources({
    resources,
    locations: places,
    types,
}: Props) {
    const manage = useCan()('logbook.manage');
    const [editing, setEditing] = useState<string | null>(null);
    const placeOptions = places
        .filter((l) => l.active)
        .map((l) => ({ value: l.id, label: l.path }));

    return (
        <>
            <Head title="Resources" />
            <PageHeader
                title="Resources and locations"
                description="Equipment, vehicles, rooms and places that are logged, inspected and maintained."
            />
            {manage && (
                <div className="mb-4 flex flex-wrap gap-2">
                    <Toggle label="Add a resource">
                        {(close) => (
                            <ResourceForm
                                places={placeOptions}
                                types={types}
                                onDone={close}
                            />
                        )}
                    </Toggle>
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
                    <PanelHeader title={`Resources (${resources.length})`} />
                    {resources.length === 0 ? (
                        <EmptyState icon={Boxes} title="No resources yet">
                            Add the generators, pumps, vehicles and rooms your
                            teams log.
                        </EmptyState>
                    ) : (
                        <ul className="divide-y">
                            {resources.map((r) => (
                                <li key={r.id} className="px-4 py-3 text-sm">
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p className="font-medium">
                                                {r.code} · {r.name}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {[r.type, r.location]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Status
                                                value={
                                                    r.active
                                                        ? 'active'
                                                        : 'inactive'
                                                }
                                            />
                                            {manage && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setEditing(
                                                            editing === r.id
                                                                ? null
                                                                : r.id,
                                                        )
                                                    }
                                                >
                                                    {editing === r.id
                                                        ? 'Close'
                                                        : 'Change'}
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                    {editing === r.id && (
                                        <div className="mt-3 rounded-md border p-4">
                                            <ResourceForm
                                                places={placeOptions}
                                                types={types}
                                                initial={r}
                                                onDone={() => setEditing(null)}
                                            />
                                        </div>
                                    )}
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

function ResourceForm({
    places,
    types,
    initial,
    onDone,
}: {
    places: { value: string; label: string }[];
    types: string[];
    initial?: Resource;
    onDone: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        code: initial?.code ?? '',
        name: initial?.name ?? '',
        type: initial?.type ?? '',
        description: initial?.description ?? '',
        location_id: initial?.locationId ?? '',
        active: initial?.active ?? true,
    });
    const set = (patch: Partial<typeof data>) => setData({ ...data, ...patch });

    return (
        <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.visit(initial ? update(initial.id).url : store().url, {
                    method: initial ? 'put' : 'post',
                    data: { ...data, location_id: data.location_id || null },
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <TextField
                label="Code"
                value={data.code}
                onChange={(code) => set({ code })}
                error={errors.code}
                placeholder="GEN-01"
                required
            />
            <TextField
                label="Name"
                value={data.name}
                onChange={(name) => set({ name })}
                error={errors.name}
                placeholder="Main generator"
                required
            />
            <div className="grid gap-1.5">
                <TextField
                    label="Type"
                    value={data.type}
                    onChange={(type) => set({ type })}
                    error={errors.type}
                    placeholder="Generator"
                    required
                />
                {types.length > 0 && (
                    <div className="flex flex-wrap gap-1">
                        {types.map((t) => (
                            <button
                                key={t}
                                type="button"
                                onClick={() => set({ type: t })}
                                className="rounded border px-1.5 py-0.5 text-xs hover:bg-muted"
                            >
                                {t}
                            </button>
                        ))}
                    </div>
                )}
            </div>
            <SelectField
                label="Location"
                value={data.location_id}
                onChange={(location_id) => set({ location_id })}
                options={places}
                empty="None"
                error={errors.location_id}
            />
            <TextArea
                label="Description"
                value={data.description}
                onChange={(description) => set({ description })}
                className="sm:col-span-2"
                rows={2}
            />
            {initial && (
                <div className="sm:col-span-2">
                    <Check
                        label="Active: offered in Resource fields and on tasks"
                        checked={data.active}
                        onChange={(active) => set({ active })}
                    />
                </div>
            )}
            <div className="sm:col-span-2">
                <Button type="submit">
                    {initial ? 'Save' : 'Add resource'}
                </Button>
            </div>
        </form>
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
