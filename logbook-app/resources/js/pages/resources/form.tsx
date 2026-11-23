import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Badge, SelectField, TextArea, TextField } from '@/components/fields';
import { PageHeader } from '@/components/page-header';
import {
    index as resourcesIndex,
    show,
    store,
    templates as templatesPage,
    update,
} from '@/routes/resources';

export type AssetTemplate = {
    key: string;
    id: string | null;
    starter: boolean;
    version: number;
    active: boolean;
    name: string;
    type: string;
    kind: 'asset' | 'monitored_location';
    description: string;
    fields: { name: string; label: string; type: string; unit?: string }[];
    measurements: string[];
    fuel: { method: 'distance' | 'hours'; fuel_type: string } | null;
    suggested_sheets: string[];
};

type Detail = { label: string; value: string; unit: string };

type Props = {
    resource: {
        id: string;
        code: string;
        name: string;
        type: string;
        kind: 'asset' | 'monitored_location';
        status: 'active' | 'inactive' | 'retired';
        description: string | null;
        locationId: string | null;
        manufacturer: string | null;
        model: string | null;
        serialNumber: string | null;
        responsibleId: string | null;
        metadata: {
            name: string;
            label: string;
            value: string;
            unit?: string;
        }[];
        template: { name: string; version: number } | null;
    } | null;
    templates: AssetTemplate[];
    chosen: string;
    locations: { value: string; label: string }[];
    members: { value: string; label: string }[];
    types: string[];
    now: string;
};

const KINDS = [
    { value: 'asset', label: 'Physical asset (equipment, vehicle, unit)' },
    {
        value: 'monitored_location',
        label: 'Monitored location (a room or store that is checked)',
    },
];

const STATUSES = [
    { value: 'active', label: 'Active: offered on log sheets' },
    { value: 'inactive', label: 'Inactive: out of service for now' },
    { value: 'retired', label: 'Retired: kept for history only' },
];

/** The details a template asks for, empty, ready to fill. */
function detailsOf(template: AssetTemplate | null): Detail[] {
    return (template?.fields ?? []).map((f) => ({
        label: f.label,
        value: '',
        unit: f.unit ?? '',
    }));
}

/**
 * Registers or changes an asset (ADR-0031 §5). A new asset starts from a
 * template: its category, kind, the details to fill and the log sheets
 * suggested. The asset keeps a copy of the template as it was, so later
 * changes to the template never change it.
 */
export default function ResourceForm({
    resource,
    templates,
    chosen,
    locations,
    members,
    types,
    now,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const initialTemplate =
        templates.find((t) => t.key === chosen) ??
        (resource === null ? null : null);
    const [templateKey, setTemplateKey] = useState(
        resource === null ? (initialTemplate?.key ?? '') : '',
    );
    const template = templates.find((t) => t.key === templateKey) ?? null;
    const [data, setData] = useState({
        code: resource?.code ?? '',
        name: resource?.name ?? '',
        type: resource?.type ?? initialTemplate?.type ?? '',
        kind: resource?.kind ?? initialTemplate?.kind ?? 'asset',
        status: resource?.status ?? 'active',
        description: resource?.description ?? '',
        location_id: resource?.locationId ?? '',
        manufacturer: resource?.manufacturer ?? '',
        model: resource?.model ?? '',
        serial_number: resource?.serialNumber ?? '',
        responsible_id: resource?.responsibleId ?? '',
    });
    const [details, setDetails] = useState<Detail[]>(
        resource
            ? resource.metadata.map((m) => ({
                  label: m.label,
                  value: m.value,
                  unit: m.unit ?? '',
              }))
            : detailsOf(initialTemplate),
    );
    const [fuel, setFuel] = useState({
        method: initialTemplate?.fuel?.method ?? 'hours',
        fuel_type: initialTemplate?.fuel?.fuel_type ?? 'diesel',
        rate: '',
        effective_from: now,
    });
    const set = (patch: Partial<typeof data>) => setData({ ...data, ...patch });

    const pick = (key: string) => {
        const next = templates.find((t) => t.key === key) ?? null;
        setTemplateKey(key);
        if (next) {
            set({ type: next.type, kind: next.kind });
            setDetails(detailsOf(next));
            if (next.fuel) {
                setFuel({
                    ...fuel,
                    method: next.fuel.method,
                    fuel_type: next.fuel.fuel_type,
                });
            }
        }
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const payload = {
            ...data,
            location_id: data.location_id || null,
            responsible_id: data.responsible_id || null,
            metadata: details.filter((d) => d.label.trim() !== ''),
            ...(resource === null
                ? {
                      template: templateKey || null,
                      fuel: fuel.rate.trim() !== '' ? fuel : null,
                  }
                : {}),
        };
        if (resource) {
            router.put(update(resource.id).url, payload, {
                preserveScroll: true,
                onSuccess: () => router.visit(show(resource.id).url),
            });
        } else {
            router.post(store().url, payload, { preserveScroll: true });
        }
    };

    const title = resource
        ? `Change ${resource.code}`
        : 'Register an asset or monitored location';
    const showFuel =
        resource === null &&
        data.kind === 'asset' &&
        (template?.fuel != null ||
            ['Generator', 'Vehicle'].includes(data.type));

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                actions={
                    <Button asChild variant="outline" size="sm">
                        <Link href={templatesPage()}>Asset templates</Link>
                    </Button>
                }
            />
            <form onSubmit={submit} className="grid gap-6">
                {resource === null && (
                    <Panel>
                        <PanelHeader
                            title="Start from a template"
                            description="A template sets the category, the details to record and the log sheets suggested. You can change everything below."
                        />
                        <PanelBody>
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {templates.map((t) => (
                                    <button
                                        key={t.key}
                                        type="button"
                                        onClick={() => pick(t.key)}
                                        aria-pressed={templateKey === t.key}
                                        className={`rounded-md border p-3 text-left text-sm hover:bg-muted ${
                                            templateKey === t.key
                                                ? 'border-[#0f766e] bg-[#e6f4f2]'
                                                : ''
                                        }`}
                                    >
                                        <span className="flex items-center justify-between gap-2 font-medium">
                                            {t.name}
                                            <Badge
                                                tone={
                                                    t.kind ===
                                                    'monitored_location'
                                                        ? 'info'
                                                        : 'neutral'
                                                }
                                            >
                                                {t.kind === 'monitored_location'
                                                    ? 'Location'
                                                    : t.starter
                                                      ? 'Starter'
                                                      : `v${t.version}`}
                                            </Badge>
                                        </span>
                                        <span className="mt-1 block text-muted-foreground">
                                            {t.description}
                                        </span>
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    onClick={() => {
                                        setTemplateKey('');
                                        setDetails([]);
                                    }}
                                    aria-pressed={templateKey === ''}
                                    className={`rounded-md border p-3 text-left text-sm hover:bg-muted ${
                                        templateKey === ''
                                            ? 'border-[#0f766e] bg-[#e6f4f2]'
                                            : ''
                                    }`}
                                >
                                    <span className="font-medium">
                                        No template
                                    </span>
                                    <span className="mt-1 block text-muted-foreground">
                                        Fill everything in yourself.
                                    </span>
                                </button>
                            </div>
                        </PanelBody>
                    </Panel>
                )}

                <Panel>
                    <PanelHeader
                        title="Identity"
                        description={
                            resource?.template
                                ? `Registered from the ${resource.template.name} template (version ${resource.template.version}).`
                                : undefined
                        }
                    />
                    <PanelBody className="grid gap-4 sm:grid-cols-2">
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
                                label="Category"
                                value={data.type}
                                onChange={(type) => set({ type })}
                                error={errors.type}
                                placeholder="Generator"
                                hint="Log sheets that ask for this category offer it."
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
                            label="Kind"
                            value={data.kind}
                            onChange={(kind) =>
                                set({ kind: kind as typeof data.kind })
                            }
                            options={KINDS}
                            error={errors.kind}
                        />
                        <SelectField
                            label="Location"
                            value={data.location_id}
                            onChange={(location_id) => set({ location_id })}
                            options={locations}
                            empty={
                                data.kind === 'monitored_location'
                                    ? 'Choose where it is'
                                    : 'None'
                            }
                            error={errors.location_id}
                        />
                        <SelectField
                            label="Responsible person"
                            value={data.responsible_id}
                            onChange={(responsible_id) =>
                                set({ responsible_id })
                            }
                            options={members}
                            empty="Nobody in particular"
                            error={errors.responsible_id}
                        />
                        {resource && (
                            <SelectField
                                label="Status"
                                value={data.status}
                                onChange={(status) =>
                                    set({
                                        status: status as typeof data.status,
                                    })
                                }
                                options={STATUSES}
                                error={errors.status}
                                hint="Retired assets keep their records and reports but take no new entries, links or schedules."
                            />
                        )}
                        <TextArea
                            label="Description"
                            value={data.description}
                            onChange={(description) => set({ description })}
                            error={errors.description}
                            className="sm:col-span-2"
                            rows={2}
                        />
                    </PanelBody>
                </Panel>

                {data.kind === 'asset' && (
                    <Panel>
                        <PanelHeader title="Make and model" />
                        <PanelBody className="grid gap-4 sm:grid-cols-3">
                            <TextField
                                label="Manufacturer"
                                value={data.manufacturer}
                                onChange={(manufacturer) =>
                                    set({ manufacturer })
                                }
                                error={errors.manufacturer}
                            />
                            <TextField
                                label="Model"
                                value={data.model}
                                onChange={(model) => set({ model })}
                                error={errors.model}
                            />
                            <TextField
                                label="Serial number"
                                value={data.serial_number}
                                onChange={(serial_number) =>
                                    set({ serial_number })
                                }
                                error={errors.serial_number}
                            />
                        </PanelBody>
                    </Panel>
                )}

                <Panel>
                    <PanelHeader
                        title="Details"
                        description="Facts about this asset, such as its capacity or rated power. Empty details are not kept."
                    />
                    <PanelBody className="grid gap-3">
                        {details.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No details yet.
                            </p>
                        )}
                        {details.map((d, i) => (
                            <div
                                key={i}
                                className="grid items-end gap-2 sm:grid-cols-[1fr_1fr_8rem_auto]"
                            >
                                <TextField
                                    label="Detail"
                                    value={d.label}
                                    onChange={(label) =>
                                        setDetails(
                                            details.map((x, j) =>
                                                j === i ? { ...x, label } : x,
                                            ),
                                        )
                                    }
                                    error={errors[`metadata.${i}.label`]}
                                />
                                <TextField
                                    label="Value"
                                    value={d.value}
                                    onChange={(value) =>
                                        setDetails(
                                            details.map((x, j) =>
                                                j === i ? { ...x, value } : x,
                                            ),
                                        )
                                    }
                                    error={errors[`metadata.${i}.value`]}
                                />
                                <TextField
                                    label="Unit"
                                    value={d.unit}
                                    onChange={(unit) =>
                                        setDetails(
                                            details.map((x, j) =>
                                                j === i ? { ...x, unit } : x,
                                            ),
                                        )
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove ${d.label || 'detail'}`}
                                    onClick={() =>
                                        setDetails(
                                            details.filter((_, j) => j !== i),
                                        )
                                    }
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                        ))}
                        {errors.metadata && (
                            <p className="text-sm text-destructive">
                                {errors.metadata}
                            </p>
                        )}
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    setDetails([
                                        ...details,
                                        { label: '', value: '', unit: '' },
                                    ])
                                }
                            >
                                <Plus className="size-4" /> Add a detail
                            </Button>
                        </div>
                    </PanelBody>
                </Panel>

                {showFuel && (
                    <Panel>
                        <PanelHeader
                            title="Fuel rate (optional)"
                            description="Used to estimate fuel on its activities. You can add or change it later on the asset's page."
                        />
                        <PanelBody className="grid gap-4 sm:grid-cols-3">
                            <SelectField
                                label="Calculated"
                                value={fuel.method}
                                onChange={(method) =>
                                    setFuel({
                                        ...fuel,
                                        method: method as typeof fuel.method,
                                    })
                                }
                                options={[
                                    {
                                        value: 'distance',
                                        label: 'By distance (L/100 km)',
                                    },
                                    {
                                        value: 'hours',
                                        label: 'By running hours (L/h)',
                                    },
                                ]}
                            />
                            <SelectField
                                label="Fuel"
                                value={fuel.fuel_type}
                                onChange={(fuel_type) =>
                                    setFuel({ ...fuel, fuel_type })
                                }
                                options={[
                                    { value: 'diesel', label: 'Diesel' },
                                    { value: 'petrol', label: 'Petrol' },
                                    { value: 'other', label: 'Other' },
                                ]}
                            />
                            <TextField
                                label={
                                    fuel.method === 'distance'
                                        ? 'Litres per 100 km'
                                        : 'Litres per hour'
                                }
                                value={fuel.rate}
                                onChange={(rate) => setFuel({ ...fuel, rate })}
                                inputMode="decimal"
                                error={errors.rate}
                            />
                        </PanelBody>
                    </Panel>
                )}

                {template && template.suggested_sheets.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        After saving, the asset page suggests log sheets for a{' '}
                        {template.name.toLowerCase()}: create one from a starter
                        or link an existing one.
                    </p>
                )}

                <div className="flex gap-2">
                    <Button type="submit">
                        {resource ? 'Save changes' : 'Register'}
                    </Button>
                    <Button asChild variant="ghost">
                        <Link
                            href={
                                resource ? show(resource.id) : resourcesIndex()
                            }
                        >
                            Cancel
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}
