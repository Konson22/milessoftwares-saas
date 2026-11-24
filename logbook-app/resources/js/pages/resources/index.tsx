import { Head, Link, router, usePage } from '@inertiajs/react';
import { Boxes } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Badge,
    Check,
    SelectField,
    Status,
    TextField,
} from '@/components/fields';
import { Toggle } from '@/components/work';
import { useCan } from '@/lib/access';
import * as locations from '@/routes/locations';
import { create, edit, show, templates } from '@/routes/resources';
import * as categoryRoutes from '@/routes/resources/categories';

type Resource = {
    id: string;
    code: string;
    name: string;
    type: string;
    categoryId: string | null;
    category: string | null;
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

type Category = {
    id: string;
    name: string;
    description: string | null;
    /** Types suggested in it (Car, Pickup...). */
    types: string[];
    active: boolean;
    /** Assets in it, retired ones left out. */
    assets: number;
};

type Location = {
    id: string;
    name: string;
    path: string;
    parentId: string | null;
    active: boolean;
};

type Props = {
    resources: Resource[];
    locations: Location[];
    categories: Category[];
};

/**
 * The asset register (ADR-0031): equipment, vehicles and monitored places,
 * grouped by category (Vehicles, Generators…), each with its type (Car,
 * Pickup…). Registering and changing happen on their own page.
 */
export default function Resources({
    resources,
    locations: places,
    categories,
}: Props) {
    const manage = useCan()('logbook.manage');
    const [q, setQ] = useState('');
    const [category, setCategory] = useState('');
    const [kind, setKind] = useState('');
    const [status, setStatus] = useState('current');
    const placeOptions = places
        .filter((l) => l.active)
        .map((l) => ({ value: l.id, label: l.path }));
    const needle = q.trim().toLowerCase();
    const shown = resources.filter(
        (r) =>
            (needle === '' ||
                `${r.code} ${r.name} ${r.type} ${r.location ?? ''} ${r.responsible ?? ''}`
                    .toLowerCase()
                    .includes(needle)) &&
            (category === '' || (r.categoryId ?? '') === category) &&
            (kind === '' || r.kind === kind) &&
            (status === 'current'
                ? r.status !== 'retired'
                : status === '' || r.status === status),
    );
    // Groups in the workspace's category order; assets without one last.
    const groups = [
        ...categories.map((c) => ({
            id: c.id,
            name: c.name,
            items: shown.filter((r) => r.categoryId === c.id),
        })),
        {
            id: '',
            name: 'No category',
            items: shown.filter(
                (r) =>
                    r.categoryId === null ||
                    !categories.some((c) => c.id === r.categoryId),
            ),
        },
    ].filter((g) => g.items.length > 0);

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
                    <Toggle label="Add a category" variant="outline">
                        {(close) => <CategoryForm onDone={close} />}
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
                    <PanelHeader title={`Assets (${shown.length})`} />
                    <div className="grid gap-3 border-b px-4 py-3 sm:grid-cols-4">
                        <TextField
                            label="Search"
                            value={q}
                            onChange={setQ}
                            placeholder="Code, name, type, place"
                        />
                        <SelectField
                            label="Category"
                            value={category}
                            onChange={setCategory}
                            options={categories.map((c) => ({
                                value: c.id,
                                label: `${c.name} (${c.assets})`,
                            }))}
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
                                ? 'Register the vehicles, generators, cold rooms and units your teams log.'
                                : 'Change the filters to see more.'}
                        </EmptyState>
                    ) : (
                        groups.map((g) => (
                            <section key={g.id || 'none'}>
                                <h3 className="flex items-center justify-between border-b bg-muted px-4 py-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    <span>{g.name}</span>
                                    <span>{g.items.length}</span>
                                </h3>
                                <ul className="divide-y border-b">
                                    {g.items.map((r) => (
                                        <AssetRow
                                            key={r.id}
                                            r={r}
                                            manage={manage}
                                        />
                                    ))}
                                </ul>
                            </section>
                        ))
                    )}
                </Panel>
                <div className="grid gap-6 self-start">
                    <Panel>
                        <PanelHeader title="Categories" />
                        <ul className="divide-y">
                            {categories.map((c) => (
                                <li
                                    key={c.id}
                                    className="flex items-center justify-between gap-2 px-4 py-2 text-sm"
                                >
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setCategory(
                                                category === c.id ? '' : c.id,
                                            )
                                        }
                                        aria-pressed={category === c.id}
                                        className={`min-w-0 text-left hover:underline ${
                                            category === c.id
                                                ? 'font-semibold text-[#0f766e]'
                                                : ''
                                        }`}
                                        title={c.description ?? undefined}
                                    >
                                        {c.name}{' '}
                                        <span className="text-muted-foreground">
                                            ({c.assets})
                                        </span>
                                    </button>
                                    <span className="flex items-center gap-2">
                                        {!c.active && (
                                            <Badge tone="warning">Off</Badge>
                                        )}
                                        {manage && (
                                            <Toggle
                                                label="Change"
                                                variant="outline"
                                            >
                                                {(close) => (
                                                    <CategoryForm
                                                        initial={c}
                                                        onDone={close}
                                                    />
                                                )}
                                            </Toggle>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                    <Panel>
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
                                        {!l.active && (
                                            <Status value="inactive" />
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>
            </div>
        </>
    );
}

function AssetRow({ r, manage }: { r: Resource; manage: boolean }) {
    return (
        <li className="px-4 py-3 text-sm">
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
                            r.responsible && `responsible ${r.responsible}`,
                            r.fuelRate && `fuel ${r.fuelRate}`,
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
                    {r.kind === 'monitored_location' && (
                        <Badge tone="info">Monitored location</Badge>
                    )}
                    <Status value={r.status} />
                    {manage && (
                        <Button asChild size="sm" variant="outline">
                            <Link href={edit(r.id)}>Change</Link>
                        </Button>
                    )}
                </div>
            </div>
        </li>
    );
}

/** Adds or changes a category. Categories are switched off, never deleted. */
function CategoryForm({
    initial,
    onDone,
}: {
    initial?: Category;
    onDone: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        name: initial?.name ?? '',
        description: initial?.description ?? '',
        types: (initial?.types ?? []).join(', '),
        active: initial?.active ?? true,
    });
    const set = (patch: Partial<typeof data>) => setData({ ...data, ...patch });

    return (
        <form
            className="grid gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                const payload = {
                    ...data,
                    types: data.types
                        .split(',')
                        .map((t) => t.trim())
                        .filter(Boolean),
                };
                if (initial) {
                    router.put(categoryRoutes.update(initial.id).url, payload, {
                        preserveScroll: true,
                        onSuccess: onDone,
                    });
                } else {
                    router.post(categoryRoutes.store().url, payload, {
                        preserveScroll: true,
                        onSuccess: onDone,
                    });
                }
            }}
        >
            <TextField
                label="Name"
                value={data.name}
                onChange={(name) => set({ name })}
                error={errors.name}
                placeholder="Vehicles"
                required
            />
            <TextField
                label="What it covers"
                value={data.description}
                onChange={(description) => set({ description })}
                error={errors.description}
                placeholder="Cars, pickups, trucks and motorbikes"
            />
            <TextField
                label="Types in it (comma separated)"
                value={data.types}
                onChange={(types) => set({ types })}
                error={errors.types}
                placeholder="Car, Pickup, Truck, Motorbike"
                hint="Offered when registering an asset in this category."
            />
            {initial && (
                <Check
                    label="Offered when registering assets"
                    checked={data.active}
                    onChange={(active) => set({ active })}
                />
            )}
            {initial && !data.active && initial.assets > 0 && (
                <p className="text-sm text-muted-foreground">
                    Its {initial.assets}{' '}
                    {initial.assets === 1 ? 'asset keeps' : 'assets keep'} it.
                </p>
            )}
            <div className="flex gap-2">
                <Button type="submit">
                    {initial ? 'Save' : 'Add category'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
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
