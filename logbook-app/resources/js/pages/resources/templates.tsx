import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Badge,
    Check,
    SelectField,
    TextArea,
    TextField,
} from '@/components/fields';
import { PageHeader } from '@/components/page-header';
import { Toggle } from '@/components/work';
import { create, index as resourcesIndex } from '@/routes/resources';
import * as templateRoutes from '@/routes/resources/templates';
import type { AssetTemplate } from './form';

type Props = {
    templates: AssetTemplate[];
    starters: { value: string; label: string }[];
    canManage: boolean;
};

/**
 * Asset templates (ADR-0031 §4): the starters every workspace has, and the
 * organization's own. Changing a template makes a new version; assets
 * already registered keep the version they were made from.
 */
export default function AssetTemplates({
    templates,
    starters,
    canManage,
}: Props) {
    const [editing, setEditing] = useState<string | null>(null);
    const starterName = Object.fromEntries(
        starters.map((s) => [s.value, s.label]),
    );

    return (
        <>
            <Head title="Asset templates" />
            <PageHeader
                title="Asset templates"
                actions={
                    <Button asChild variant="outline" size="sm">
                        <Link href={resourcesIndex()}>Resources</Link>
                    </Button>
                }
            />
            {canManage && (
                <div className="mb-4">
                    <Toggle label="New template">
                        {(close) => (
                            <TemplateForm starters={starters} onDone={close} />
                        )}
                    </Toggle>
                </div>
            )}
            <Panel>
                <PanelHeader title={`Templates (${templates.length})`} />
                <ul className="divide-y">
                    {templates.map((t) => (
                        <li key={t.key} className="px-4 py-3 text-sm">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="flex flex-wrap items-center gap-2 font-medium">
                                        {t.name}
                                        <Badge tone="neutral">
                                            {t.starter
                                                ? 'Starter'
                                                : `Version ${t.version}`}
                                        </Badge>
                                        {t.kind === 'monitored_location' && (
                                            <Badge tone="info">
                                                Monitored location
                                            </Badge>
                                        )}
                                        {!t.active && (
                                            <Badge tone="warning">
                                                Not offered
                                            </Badge>
                                        )}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {[
                                            t.type,
                                            t.description,
                                            t.fuel &&
                                                `fuel by ${t.fuel.method === 'distance' ? 'distance' : 'running hours'}`,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                    {t.fields.length > 0 && (
                                        <p className="text-muted-foreground">
                                            Details:{' '}
                                            {t.fields
                                                .map(
                                                    (f) =>
                                                        f.label +
                                                        (f.unit
                                                            ? ` (${f.unit})`
                                                            : ''),
                                                )
                                                .join(', ')}
                                        </p>
                                    )}
                                    {t.suggested_sheets.length > 0 && (
                                        <p className="text-muted-foreground">
                                            Suggested log sheets:{' '}
                                            {t.suggested_sheets
                                                .map((k) => starterName[k] ?? k)
                                                .join(', ')}
                                        </p>
                                    )}
                                </div>
                                <div className="flex gap-2">
                                    {canManage && t.active && (
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="outline"
                                        >
                                            <Link
                                                href={create({
                                                    query: { template: t.key },
                                                })}
                                            >
                                                Register from it
                                            </Link>
                                        </Button>
                                    )}
                                    {canManage && !t.starter && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setEditing(
                                                    editing === t.key
                                                        ? null
                                                        : t.key,
                                                )
                                            }
                                        >
                                            {editing === t.key
                                                ? 'Close'
                                                : 'Change'}
                                        </Button>
                                    )}
                                </div>
                            </div>
                            {editing === t.key && (
                                <div className="mt-3 rounded-md border p-4">
                                    <TemplateForm
                                        starters={starters}
                                        initial={t}
                                        onDone={() => setEditing(null)}
                                    />
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            </Panel>
        </>
    );
}

function TemplateForm({
    starters,
    initial,
    onDone,
}: {
    starters: { value: string; label: string }[];
    initial?: AssetTemplate;
    onDone: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        name: initial?.name ?? '',
        type: initial?.type ?? '',
        kind: initial?.kind ?? 'asset',
        description: initial?.description ?? '',
        measurements: (initial?.measurements ?? []).join(', '),
        fuel: initial?.fuel?.method ?? '',
        fuel_type: initial?.fuel?.fuel_type ?? 'diesel',
        suggested_sheets: initial?.suggested_sheets ?? [],
        active: initial?.active ?? true,
    });
    const [fields, setFields] = useState(
        (initial?.fields ?? []).map((f) => ({
            label: f.label,
            type: f.type,
            unit: f.unit ?? '',
        })),
    );
    const set = (patch: Partial<typeof data>) => setData({ ...data, ...patch });

    return (
        <form
            className="grid gap-4 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                const payload = {
                    name: data.name,
                    type: data.type,
                    kind: data.kind,
                    description: data.description,
                    measurements: data.measurements
                        .split(',')
                        .map((m) => m.trim())
                        .filter(Boolean),
                    fuel: data.fuel
                        ? { method: data.fuel, fuel_type: data.fuel_type }
                        : null,
                    suggested_sheets: data.suggested_sheets,
                    fields,
                    active: data.active,
                };
                if (initial?.id) {
                    router.put(templateRoutes.update(initial.id).url, payload, {
                        preserveScroll: true,
                        onSuccess: onDone,
                    });
                } else {
                    router.post(templateRoutes.store().url, payload, {
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
                placeholder="Vaccine fridge"
                required
            />
            <TextField
                label="Category given to assets"
                value={data.type}
                onChange={(type) => set({ type })}
                error={errors.type}
                placeholder="Refrigerator"
                required
            />
            <SelectField
                label="Kind"
                value={data.kind}
                onChange={(kind) => set({ kind: kind as typeof data.kind })}
                options={[
                    { value: 'asset', label: 'Physical asset' },
                    {
                        value: 'monitored_location',
                        label: 'Monitored location',
                    },
                ]}
                error={errors.kind}
            />
            <TextField
                label="Measurements (comma separated)"
                value={data.measurements}
                onChange={(measurements) => set({ measurements })}
                placeholder="Temperature, Humidity"
            />
            <SelectField
                label="Fuel estimate"
                value={data.fuel}
                onChange={(fuel) => set({ fuel })}
                options={[
                    { value: 'distance', label: 'By distance' },
                    { value: 'hours', label: 'By running hours' },
                ]}
                empty="No fuel"
            />
            {data.fuel !== '' && (
                <SelectField
                    label="Fuel"
                    value={data.fuel_type}
                    onChange={(fuel_type) => set({ fuel_type })}
                    options={[
                        { value: 'diesel', label: 'Diesel' },
                        { value: 'petrol', label: 'Petrol' },
                        { value: 'other', label: 'Other' },
                    ]}
                />
            )}
            <TextArea
                label="Description"
                value={data.description}
                onChange={(description) => set({ description })}
                className="sm:col-span-2"
                rows={2}
            />
            <div className="grid gap-2 sm:col-span-2">
                <p className="text-sm font-medium">Details to record</p>
                {fields.map((f, i) => (
                    <div
                        key={i}
                        className="grid items-end gap-2 sm:grid-cols-[1fr_10rem_8rem_auto]"
                    >
                        <TextField
                            label="Label"
                            value={f.label}
                            onChange={(label) =>
                                setFields(
                                    fields.map((x, j) =>
                                        j === i ? { ...x, label } : x,
                                    ),
                                )
                            }
                            error={errors[`fields.${i}.label`]}
                        />
                        <SelectField
                            label="Type"
                            value={f.type}
                            onChange={(type) =>
                                setFields(
                                    fields.map((x, j) =>
                                        j === i ? { ...x, type } : x,
                                    ),
                                )
                            }
                            options={[
                                { value: 'text', label: 'Text' },
                                { value: 'number', label: 'Number' },
                                { value: 'date', label: 'Date' },
                            ]}
                        />
                        <TextField
                            label="Unit"
                            value={f.unit}
                            onChange={(unit) =>
                                setFields(
                                    fields.map((x, j) =>
                                        j === i ? { ...x, unit } : x,
                                    ),
                                )
                            }
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={`Remove ${f.label || 'detail'}`}
                            onClick={() =>
                                setFields(fields.filter((_, j) => j !== i))
                            }
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                ))}
                {errors.fields && (
                    <p className="text-sm text-destructive">{errors.fields}</p>
                )}
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            setFields([
                                ...fields,
                                { label: '', type: 'text', unit: '' },
                            ])
                        }
                    >
                        <Plus className="size-4" /> Add a detail
                    </Button>
                </div>
            </div>
            <div className="grid gap-2 sm:col-span-2">
                <p className="text-sm font-medium">Suggested log sheets</p>
                <div className="grid gap-1 sm:grid-cols-3">
                    {starters.map((s) => (
                        <Check
                            key={s.value}
                            label={s.label}
                            checked={data.suggested_sheets.includes(s.value)}
                            onChange={(on) =>
                                set({
                                    suggested_sheets: on
                                        ? [...data.suggested_sheets, s.value]
                                        : data.suggested_sheets.filter(
                                              (k) => k !== s.value,
                                          ),
                                })
                            }
                        />
                    ))}
                </div>
            </div>
            {initial && (
                <div className="sm:col-span-2">
                    <Check
                        label="Offered when registering assets"
                        checked={data.active}
                        onChange={(active) => set({ active })}
                    />
                </div>
            )}
            <div className="flex gap-2 sm:col-span-2">
                <Button type="submit">
                    {initial ? 'Save as a new version' : 'Create template'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
