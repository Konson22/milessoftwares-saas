import type { RequestPayload } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Copy,
    Eye,
    Pencil,
    Plus,
    Trash2,
    MapPin,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import type { Values } from '@/components/entry-fields';
import type { LocationSettings } from '@/lib/tracks';
import { EntryFields } from '@/components/entry-fields';
import {
    Check,
    Errors,
    Field,
    SelectField,
    TextArea,
    TextField,
} from '@/components/fields';
import type {
    FieldDef,
    FieldType,
    Option,
    ResourceOption,
} from '@/lib/logbook';
import {
    action,
    CONDITIONAL_TYPES,
    formatDateTime,
    nameFor,
} from '@/lib/logbook';
import { publish, show, update } from '@/routes/sheets';
import * as draft from '@/routes/sheets/draft';
import { useConfirm } from '@/components/dialogs';

type Props = {
    sheet: {
        id: string;
        name: string;
        code: string;
        description: string | null;
        instructions: string | null;
        status: string;
        reviewRequired: boolean;
        version: number | null;
        hasDraft: boolean;
        logbook: { id: string; name: string; status: string };
    };
    fields: FieldDef[];
    draftVersion: number | null;
    publishedVersion: number | null;
    types: { value: FieldType; label: string; settings: string[] }[];
    members: Option[];
    resources: ResourceOption[];
    /** GPS (ADR-0028): part of the draft, published with it. Null: no location. */
    location: LocationSettings | null;
    history: {
        id: string;
        action: string;
        user: string | null;
        details: Record<string, unknown> | null;
        at: string;
    }[];
};

/** A field in the editor: `key` keeps React rows stable while names change. */
type Row = FieldDef & { key: string; named: boolean };

let counter = 0;
const newKey = () => `row-${++counter}`;

/**
 * The template builder. Fields are a list: add, edit, reorder, remove.
 * Saving keeps a draft; publishing makes an immutable version that new
 * entries use. The preview is the real entry form.
 */
export default function Template({
    sheet,
    fields: initial,
    draftVersion,
    publishedVersion,
    types,
    members,
    resources,
    history,
    location: initialLocation,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const ask = useConfirm();
    const [rows, setRows] = useState<Row[]>(() =>
        initial.map((f) => ({ ...f, key: newKey(), named: true })),
    );
    const [open, setOpen] = useState<string | null>(null);
    const [tab, setTab] = useState<'fields' | 'preview' | 'settings'>('fields');
    const [busy, setBusy] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [location, setLocation] = useState<LocationForm>(() => ({
        mode: initialLocation?.mode ?? 'off',
        required: initialLocation?.required ?? false,
        resource_required: initialLocation?.resource_required ?? false,
        accuracy_m: initialLocation?.accuracy_m
            ? String(initialLocation.accuracy_m)
            : '',
    }));
    const [preview, setPreview] = useState<Values>({});
    const publishedNames = useMemo(
        () => new Set(publishedVersion ? initial.map((f) => f.name) : []),
        [initial, publishedVersion],
    );

    const typeLabel = (t: FieldType) =>
        types.find((x) => x.value === t)?.label ?? t;
    const settingsOf = (t: FieldType) =>
        types.find((x) => x.value === t)?.settings ?? [];

    const change = (next: Row[]) => {
        setRows(next);
        setDirty(true);
    };
    const patch = (key: string, values: Partial<Row>) =>
        change(rows.map((r) => (r.key === key ? { ...r, ...values } : r)));
    const move = (index: number, by: number) => {
        const next = [...rows];
        const [row] = next.splice(index, 1);
        next.splice(index + by, 0, row);
        change(next);
    };
    const add = (type: FieldType) => {
        const row: Row = {
            key: newKey(),
            named: false,
            name: '',
            label: '',
            type,
            required: false,
            ...(type === 'select_one' || type === 'select_multiple'
                ? { choices: [{ value: 'option_1', label: 'Option 1' }] }
                : {}),
        };
        change([...rows, row]);
        setOpen(row.key);
        setTab('fields');
    };

    const payload = () =>
        rows.map(({ key: _key, named: _named, ...field }) => {
            void _key;
            void _named;
            const settings = settingsOf(field.type);
            const out: Record<string, unknown> = {
                name: field.name || nameFor(field.label),
                label: field.label,
                type: field.type,
                required: field.required,
            };
            if (field.help) out.help = field.help;
            for (const s of settings) {
                const v = (field as Record<string, unknown>)[s];
                if (v !== undefined && v !== '' && v !== null) out[s] = v;
            }

            return out;
        });

    const save = async (andPublish: boolean) => {
        if (
            andPublish &&
            !(await ask({
                title: `Publish version ${(publishedVersion ?? 0) + 1}?`,
                message:
                    'New entries use it at once. It cannot be changed afterwards; earlier entries keep their own version.',
                confirmLabel: 'Publish',
            }))
        ) {
            return;
        }
        setBusy(true);
        const options = {
            preserveScroll: true,
            onSuccess: () => setDirty(false),
            onFinish: () => setBusy(false),
        };
        if (andPublish) {
            router.post(
                publish(sheet.id).url,
                { fields: payload(), location } as RequestPayload,
                options,
            );
        } else {
            router.put(
                draft.save(sheet.id).url,
                { fields: payload(), location } as RequestPayload,
                options,
            );
        }
    };

    const fieldErrors = (index: number) =>
        Object.entries(errors)
            .filter(
                ([k]) =>
                    k === `fields.${index}` || k.startsWith(`fields.${index}.`),
            )
            .map(([, v]) => v);

    const previewFields = payload() as FieldDef[];
    const editable =
        sheet.status !== 'archived' && sheet.logbook.status === 'active';

    return (
        <>
            <Head title={`Template · ${sheet.name}`} />
            <PageHeader
                title={`${sheet.name} template`}
                description={
                    <>
                        <Link href={show(sheet.id)} className="hover:underline">
                            {sheet.logbook.name} · {sheet.code}
                        </Link>{' '}
                        ·{' '}
                        {publishedVersion
                            ? `published version ${publishedVersion}`
                            : 'never published'}
                        {draftVersion &&
                            ` · editing draft version ${draftVersion}`}
                    </>
                }
                actions={
                    editable && (
                        <>
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => save(false)}
                            >
                                Save draft
                            </Button>
                            <Button
                                disabled={busy || rows.length === 0}
                                onClick={() => save(true)}
                            >
                                Publish version {(publishedVersion ?? 0) + 1}
                            </Button>
                        </>
                    )
                }
            />

            {!editable && (
                <p className="mb-4 rounded-md border bg-muted px-3 py-2 text-sm">
                    This sheet or its logbook is archived. Restore it to change
                    the template.
                </p>
            )}
            {dirty && (
                <p className="mb-4 text-sm text-amber-700" role="status">
                    Unsaved changes. Save the draft or publish to keep them.
                </p>
            )}
            <Errors
                errors={Object.fromEntries(
                    Object.entries(errors).filter(
                        ([k]) =>
                            !/^fields\.\d/.test(k) &&
                            ![
                                'name',
                                'code',
                                'description',
                                'instructions',
                            ].includes(k),
                    ),
                )}
            />

            <div className="mt-2 mb-4 flex gap-1" role="tablist">
                {(['fields', 'preview', 'settings'] as const).map((t) => (
                    <button
                        key={t}
                        role="tab"
                        aria-selected={tab === t}
                        onClick={() => setTab(t)}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium ${tab === t ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'}`}
                    >
                        {t === 'fields'
                            ? `Fields (${rows.length})`
                            : t === 'preview'
                              ? 'Preview'
                              : 'Sheet settings'}
                    </button>
                ))}
            </div>

            {tab === 'fields' && (
                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="grid content-start gap-3 lg:col-span-2">
                        {rows.length === 0 && (
                            <Panel>
                                <PanelBody className="text-sm text-muted-foreground">
                                    No fields yet. Add the first one from the
                                    list.
                                </PanelBody>
                            </Panel>
                        )}
                        {rows.map((row, index) => {
                            const problems = fieldErrors(index);
                            const isOpen = open === row.key;

                            return (
                                <Panel
                                    key={row.key}
                                    className={
                                        problems.length > 0
                                            ? 'border-destructive'
                                            : undefined
                                    }
                                >
                                    <div className="flex items-center gap-2 px-4 py-3">
                                        <span className="w-6 text-xs text-muted-foreground tabular-nums">
                                            {index + 1}
                                        </span>
                                        <button
                                            type="button"
                                            className="grid flex-1 text-left"
                                            aria-expanded={isOpen}
                                            onClick={() =>
                                                setOpen(isOpen ? null : row.key)
                                            }
                                        >
                                            <span className="text-sm font-medium">
                                                {row.label || 'Untitled field'}
                                                {row.required && (
                                                    <span className="text-destructive">
                                                        {' '}
                                                        *
                                                    </span>
                                                )}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {typeLabel(row.type)} ·{' '}
                                                {row.name ||
                                                    nameFor(
                                                        row.label || 'field',
                                                    )}
                                            </span>
                                        </button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            aria-label="Move up"
                                            disabled={index === 0 || !editable}
                                            onClick={() => move(index, -1)}
                                        >
                                            <ArrowUp />
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            aria-label="Move down"
                                            disabled={
                                                index === rows.length - 1 ||
                                                !editable
                                            }
                                            onClick={() => move(index, 1)}
                                        >
                                            <ArrowDown />
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            aria-label="Edit"
                                            onClick={() =>
                                                setOpen(isOpen ? null : row.key)
                                            }
                                        >
                                            <Pencil />
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            aria-label="Duplicate"
                                            disabled={!editable}
                                            onClick={() => {
                                                const copy = {
                                                    ...row,
                                                    key: newKey(),
                                                    named: false,
                                                    name: '',
                                                    label: `${row.label} (copy)`,
                                                };
                                                const next = [...rows];
                                                next.splice(index + 1, 0, copy);
                                                change(next);
                                                setOpen(copy.key);
                                            }}
                                        >
                                            <Copy />
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            aria-label="Remove"
                                            disabled={!editable}
                                            onClick={async () => {
                                                const warn = publishedNames.has(
                                                    row.name,
                                                )
                                                    ? ' Entries already written keep their answer to it; new entries will not have it.'
                                                    : '';
                                                if (
                                                    await ask({
                                                        title: `Remove “${row.label || 'this field'}” from the draft?`,
                                                        message:
                                                            warn.trim() ||
                                                            undefined,
                                                        confirmLabel: 'Remove',
                                                        destructive: true,
                                                    })
                                                ) {
                                                    change(
                                                        rows.filter(
                                                            (r) =>
                                                                r.key !==
                                                                row.key,
                                                        ),
                                                    );
                                                }
                                            }}
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                    {problems.length > 0 && !isOpen && (
                                        <p className="px-4 pb-3 text-xs text-destructive">
                                            {problems[0]}
                                        </p>
                                    )}
                                    {isOpen && (
                                        <PanelBody className="border-t">
                                            <FieldEditor
                                                row={row}
                                                index={index}
                                                settings={settingsOf(row.type)}
                                                types={types}
                                                earlier={rows.slice(0, index)}
                                                errors={errors}
                                                locked={publishedNames.has(
                                                    row.name,
                                                )}
                                                onChange={(values) =>
                                                    patch(row.key, values)
                                                }
                                            />
                                        </PanelBody>
                                    )}
                                </Panel>
                            );
                        })}
                    </div>
                    <div className="grid content-start gap-6">
                        <LocationPanel
                            value={location}
                            editable={editable}
                            errors={errors}
                            onChange={(next) => {
                                setLocation(next);
                                setDirty(true);
                            }}
                        />
                        {editable && (
                            <Panel>
                                <PanelHeader title="Add a field" />
                                <PanelBody className="grid grid-cols-2 gap-2">
                                    {types.map((t) => (
                                        <Button
                                            key={t.value}
                                            size="sm"
                                            variant="outline"
                                            className="justify-start"
                                            onClick={() => add(t.value)}
                                        >
                                            <Plus /> {t.label}
                                        </Button>
                                    ))}
                                </PanelBody>
                            </Panel>
                        )}
                        <Panel>
                            <PanelHeader title="Template history" />
                            <PanelBody>
                                {history.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Nothing yet.
                                    </p>
                                ) : (
                                    <ol className="grid gap-2 text-sm">
                                        {history.map((h) => (
                                            <li key={h.id}>
                                                <strong>{h.user}</strong>{' '}
                                                {action(h.action)}
                                                {typeof h.details?.version ===
                                                    'number' &&
                                                    ` (v${h.details.version})`}
                                                <span className="block text-xs text-muted-foreground">
                                                    {formatDateTime(h.at)}
                                                </span>
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </PanelBody>
                        </Panel>
                    </div>
                </div>
            )}

            {tab === 'preview' && (
                <Panel className="max-w-3xl">
                    <PanelHeader
                        title={
                            <span className="flex items-center gap-2">
                                <Eye className="size-4" /> What contributors
                                will see
                            </span>
                        }
                        description="Try the form here; nothing is saved."
                    />
                    <PanelBody className="grid gap-5">
                        {sheet.instructions && (
                            <p className="rounded-md bg-muted p-3 text-sm whitespace-pre-line">
                                {sheet.instructions}
                            </p>
                        )}
                        <Field label="When did this happen?">
                            <Input type="datetime-local" className="max-w-xs" />
                        </Field>
                        <EntryFields
                            fields={previewFields}
                            values={preview}
                            onChange={(name, value) =>
                                setPreview((v) => ({ ...v, [name]: value }))
                            }
                            errors={{}}
                            members={members}
                            resources={resources}
                        />
                    </PanelBody>
                </Panel>
            )}

            {tab === 'settings' && <SheetSettings sheet={sheet} />}
        </>
    );
}

function FieldEditor({
    row,
    index,
    settings,
    types,
    earlier,
    errors,
    locked,
    onChange,
}: {
    row: Row;
    index: number;
    settings: string[];
    types: Props['types'];
    /** The fields above this one: what its condition may name. */
    earlier: Row[];
    errors: Record<string, string>;
    locked: boolean;
    onChange: (values: Partial<Row>) => void;
}) {
    const e = (key: string) => errors[`fields.${index}.${key}`];
    const numeric = row.type === 'integer' || row.type === 'decimal';

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <TextField
                label="Label"
                value={row.label}
                onChange={(label) =>
                    onChange({
                        label,
                        ...(row.named ? {} : { name: nameFor(label) }),
                    })
                }
                error={e('label')}
                required
            />
            <TextField
                label="Identifier"
                value={row.name}
                onChange={(name) =>
                    onChange({
                        name: name.toLowerCase().replace(/[^a-z0-9_]/g, '_'),
                        named: true,
                    })
                }
                error={e('name')}
                hint={
                    locked
                        ? 'Used by entries already written: keep it to keep their answers together in reports.'
                        : 'Stable name used in exports and reports.'
                }
            />
            <SelectField
                label="Type"
                value={row.type}
                onChange={(type) => onChange({ type: type as FieldType })}
                options={types}
                error={e('type')}
            />
            <div className="flex items-end pb-2">
                <Check
                    label="Required"
                    checked={row.required}
                    onChange={(required) => onChange({ required })}
                />
            </div>
            <TextArea
                label="Help text"
                value={row.help ?? ''}
                onChange={(help) => onChange({ help })}
                error={e('help')}
                rows={2}
                className="sm:col-span-2"
            />
            {settings.includes('placeholder') && (
                <TextField
                    label="Placeholder"
                    value={row.placeholder ?? ''}
                    onChange={(placeholder) => onChange({ placeholder })}
                />
            )}
            {settings.includes('max_length') && (
                <TextField
                    label="Maximum length"
                    value={row.max_length?.toString() ?? ''}
                    onChange={(v) =>
                        onChange({
                            max_length: v === '' ? undefined : Number(v),
                        })
                    }
                    error={e('max_length')}
                    inputMode="numeric"
                />
            )}
            {numeric && (
                <>
                    <TextField
                        label="Unit (e.g. °C, bar, litres)"
                        value={row.unit ?? ''}
                        onChange={(unit) => onChange({ unit })}
                        error={e('unit')}
                    />
                    <span className="hidden sm:block" />
                    <TextField
                        label="Normal range: from"
                        value={row.expected_min?.toString() ?? ''}
                        onChange={(expected_min) => onChange({ expected_min })}
                        error={e('expected_min')}
                        inputMode="decimal"
                    />
                    <TextField
                        label="Normal range: to"
                        value={row.expected_max?.toString() ?? ''}
                        onChange={(expected_max) => onChange({ expected_max })}
                        error={e('expected_max')}
                        inputMode="decimal"
                    />
                    <p className="-mt-2 text-xs text-muted-foreground sm:col-span-2">
                        A reading outside the normal range is still saved, and
                        is flagged for the reviewer. The limits below refuse a
                        value outright: use them only for impossible values.
                    </p>
                    <TextField
                        label="Hard minimum"
                        value={row.min?.toString() ?? ''}
                        onChange={(min) => onChange({ min })}
                        error={e('min')}
                        inputMode="decimal"
                    />
                    <TextField
                        label="Hard maximum"
                        value={row.max?.toString() ?? ''}
                        onChange={(max) => onChange({ max })}
                        error={e('max')}
                        inputMode="decimal"
                    />
                </>
            )}
            {settings.includes('choices') && (
                <ChoicesEditor
                    choices={row.choices ?? []}
                    error={
                        e('choices') ??
                        Object.entries(errors).find(([k]) =>
                            k.startsWith(`fields.${index}.choices.`),
                        )?.[1]
                    }
                    onChange={(choices) => onChange({ choices })}
                />
            )}
            {settings.includes('default') && (
                <DefaultEditor
                    row={row}
                    error={e('default')}
                    onChange={(value) => onChange({ default: value })}
                />
            )}
            {settings.includes('resource_type') && (
                <TextField
                    label="Resource type (optional)"
                    value={row.resource_type ?? ''}
                    onChange={(resource_type) =>
                        onChange({ resource_type: resource_type || undefined })
                    }
                    error={e('resource_type')}
                    hint="Only resources of this type are offered, such as Generator."
                />
            )}
            <ExceptionRule
                row={row}
                settings={settings}
                error={e}
                onChange={onChange}
            />
            <ConditionEditor
                row={row}
                earlier={earlier}
                error={e('show_if')}
                onChange={onChange}
            />
        </div>
    );
}

const SEVERITY_OPTIONS = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
];

const YES_NO = [
    { value: 'yes', label: 'Yes' },
    { value: 'no', label: 'No' },
];

/** When an answer opens an exception for follow-up (ADR-0026 §2.7). */
function ExceptionRule({
    row,
    settings,
    error,
    onChange,
}: {
    row: Row;
    settings: string[];
    error: (key: string) => string | undefined;
    onChange: (values: Partial<Row>) => void;
}) {
    const byRange = settings.includes('raise_exception');
    const byAnswer = settings.includes('exception_values');
    if (!byRange && !byAnswer) {
        return null;
    }
    const answers = row.type === 'yes_no' ? YES_NO : (row.choices ?? []);
    const picked = row.exception_values ?? [];
    const active = byRange ? row.raise_exception === true : picked.length > 0;
    const message = error('raise_exception') ?? error('exception_values');

    return (
        <fieldset className="grid gap-2 rounded-md border p-3 sm:col-span-2">
            <legend className="px-1 text-sm font-medium">Exceptions</legend>
            {byRange && (
                <Check
                    label="Open an exception when a reading is outside the normal range"
                    checked={row.raise_exception === true}
                    onChange={(raise_exception) =>
                        onChange({
                            raise_exception: raise_exception || undefined,
                        })
                    }
                />
            )}
            {byAnswer && (
                <div className="grid gap-1.5">
                    <p className="text-sm">
                        Open an exception when the answer is:
                    </p>
                    <div className="flex flex-wrap gap-4">
                        {answers.map((a) => (
                            <Check
                                key={a.value}
                                label={a.label}
                                checked={picked.includes(a.value)}
                                onChange={(on) => {
                                    const next = on
                                        ? [...picked, a.value]
                                        : picked.filter((v) => v !== a.value);
                                    onChange({
                                        exception_values:
                                            next.length > 0 ? next : undefined,
                                    });
                                }}
                            />
                        ))}
                    </div>
                </div>
            )}
            {active && (
                <SelectField
                    label="Severity"
                    value={row.exception_severity ?? 'medium'}
                    onChange={(v) =>
                        onChange({
                            exception_severity: v as Row['exception_severity'],
                        })
                    }
                    options={SEVERITY_OPTIONS}
                    className="max-w-xs"
                />
            )}
            {message && <p className="text-xs text-destructive">{message}</p>}
        </fieldset>
    );
}

/** Show this field only for some answers of a yes/no or choice field above it. */
function ConditionEditor({
    row,
    earlier,
    error,
    onChange,
}: {
    row: Row;
    earlier: Row[];
    error?: string;
    onChange: (values: Partial<Row>) => void;
}) {
    const parents = earlier.filter((f) => CONDITIONAL_TYPES.includes(f.type));
    if (parents.length === 0 && !row.show_if) {
        return null;
    }
    const parent = parents.find((f) => f.name === row.show_if?.field);
    const answers = parent
        ? parent.type === 'yes_no'
            ? YES_NO
            : (parent.choices ?? [])
        : [];
    const picked = row.show_if?.in ?? [];

    return (
        <fieldset className="grid gap-2 rounded-md border p-3 sm:col-span-2">
            <legend className="px-1 text-sm font-medium">
                Show this field
            </legend>
            <SelectField
                label="Only when"
                value={row.show_if?.field ?? ''}
                onChange={(field) =>
                    onChange({
                        show_if: field === '' ? undefined : { field, in: [] },
                    })
                }
                options={parents.map((f) => ({
                    value: f.name,
                    label: f.label,
                }))}
                empty="Always"
                className="max-w-md"
            />
            {parent && (
                <div className="flex flex-wrap gap-4">
                    <span className="text-sm text-muted-foreground">is</span>
                    {answers.map((a) => (
                        <Check
                            key={a.value}
                            label={a.label}
                            checked={picked.includes(a.value)}
                            onChange={(on) =>
                                onChange({
                                    show_if: {
                                        field: parent.name,
                                        in: on
                                            ? [...picked, a.value]
                                            : picked.filter(
                                                  (v) => v !== a.value,
                                              ),
                                    },
                                })
                            }
                        />
                    ))}
                </div>
            )}
            <p className="text-xs text-muted-foreground">
                A hidden field is not asked, and any answer it had is cleared
                when the entry is saved.
            </p>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </fieldset>
    );
}

function ChoicesEditor({
    choices,
    error,
    onChange,
}: {
    choices: { value: string; label: string }[];
    error?: string;
    onChange: (choices: { value: string; label: string }[]) => void;
}) {
    return (
        <fieldset className="grid gap-2 sm:col-span-2">
            <legend className="mb-1 text-sm font-medium">Choices</legend>
            {choices.map((c, i) => (
                <div key={i} className="flex gap-2">
                    <Input
                        aria-label={`Choice ${i + 1}`}
                        value={c.label}
                        onChange={(e) => {
                            const next = [...choices];
                            next[i] = {
                                label: e.target.value,
                                value: nameFor(e.target.value),
                            };
                            onChange(next);
                        }}
                    />
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        aria-label={`Remove choice ${i + 1}`}
                        onClick={() =>
                            onChange(choices.filter((_, j) => j !== i))
                        }
                    >
                        <Trash2 />
                    </Button>
                </div>
            ))}
            <div>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        onChange([
                            ...choices,
                            {
                                value: `option_${choices.length + 1}`,
                                label: `Option ${choices.length + 1}`,
                            },
                        ])
                    }
                >
                    <Plus /> Add choice
                </Button>
            </div>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </fieldset>
    );
}

function DefaultEditor({
    row,
    error,
    onChange,
}: {
    row: Row;
    error?: string;
    onChange: (value: string | boolean | undefined) => void;
}) {
    if (row.type === 'yes_no') {
        return (
            <SelectField
                label="Default"
                value={
                    row.default === true
                        ? 'yes'
                        : row.default === false
                          ? 'no'
                          : ''
                }
                onChange={(v) => onChange(v === '' ? undefined : v === 'yes')}
                options={[
                    { value: 'yes', label: 'Yes' },
                    { value: 'no', label: 'No' },
                ]}
                empty="None"
                error={error}
            />
        );
    }
    if (row.type === 'select_one') {
        return (
            <SelectField
                label="Default"
                value={String(row.default ?? '')}
                onChange={(v) => onChange(v === '' ? undefined : v)}
                options={row.choices ?? []}
                empty="None"
                error={error}
            />
        );
    }

    return (
        <TextField
            label="Default value"
            value={String(row.default ?? '')}
            onChange={(v) => onChange(v === '' ? undefined : v)}
            error={error}
        />
    );
}

function SheetSettings({ sheet }: { sheet: Props['sheet'] }) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        name: sheet.name,
        code: sheet.code,
        description: sheet.description ?? '',
        instructions: sheet.instructions ?? '',
        review_required: sheet.reviewRequired,
    });
    const [busy, setBusy] = useState(false);

    return (
        <Panel className="max-w-3xl">
            <PanelBody>
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        setBusy(true);
                        router.put(update(sheet.id).url, data, {
                            preserveScroll: true,
                            onFinish: () => setBusy(false),
                        });
                    }}
                >
                    <div className="grid gap-4 sm:grid-cols-3">
                        <TextField
                            className="sm:col-span-2"
                            label="Name"
                            value={data.name}
                            onChange={(name) => setData({ ...data, name })}
                            error={errors.name}
                            required
                        />
                        <TextField
                            label="Code"
                            value={data.code}
                            onChange={(code) =>
                                setData({ ...data, code: code.toUpperCase() })
                            }
                            error={errors.code}
                            hint="Existing references keep their code."
                            required
                        />
                    </div>
                    <TextArea
                        label="Description"
                        value={data.description}
                        onChange={(description) =>
                            setData({ ...data, description })
                        }
                        error={errors.description}
                        rows={2}
                    />
                    <TextArea
                        label="Instructions for contributors"
                        value={data.instructions}
                        onChange={(instructions) =>
                            setData({ ...data, instructions })
                        }
                        error={errors.instructions}
                        rows={4}
                    />
                    <div className="grid gap-1">
                        <Check
                            label="Entries are reviewed by a supervisor"
                            checked={data.review_required}
                            onChange={(review_required) =>
                                setData({ ...data, review_required })
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            Applies to entries submitted from now on. Without
                            review, entries are recorded on submission and never
                            shown as reviewed.
                        </p>
                    </div>
                    <div>
                        <Button type="submit" disabled={busy}>
                            Save settings
                        </Button>
                    </div>
                </form>
            </PanelBody>
        </Panel>
    );
}

type LocationForm = {
    mode: 'off' | 'entry' | 'route';
    required: boolean;
    resource_required: boolean;
    accuracy_m: string;
};

/**
 * GPS for this sheet (ADR-0028). Off by default. Choosing routes starts
 * nothing on anyone's phone: each person starts their own recording.
 */
function LocationPanel({
    value,
    editable,
    errors,
    onChange,
}: {
    value: LocationForm;
    editable: boolean;
    errors: Record<string, string>;
    onChange: (value: LocationForm) => void;
}) {
    const set = (patch: Partial<LocationForm>) =>
        onChange({ ...value, ...patch });

    return (
        <Panel>
            <PanelHeader
                title={
                    <span className="flex items-center gap-2">
                        <MapPin className="size-4" /> Location (GPS)
                    </span>
                }
                description="Saved with the draft and published with it."
            />
            <PanelBody className="grid gap-3">
                <SelectField
                    label="Take location"
                    value={value.mode}
                    onChange={(mode) =>
                        set({ mode: mode as LocationForm['mode'] })
                    }
                    options={[
                        { value: 'off', label: 'No (default)' },
                        { value: 'entry', label: 'Where each entry is made' },
                        { value: 'route', label: 'Record a route (trips)' },
                    ]}
                    error={errors['location.mode']}
                />
                {value.mode !== 'off' && (
                    <>
                        <Check
                            label={
                                value.mode === 'route'
                                    ? 'Each entry needs a location or a route'
                                    : 'Each entry needs a location'
                            }
                            checked={value.required}
                            onChange={(required) => set({ required })}
                        />
                        {value.mode === 'route' && (
                            <Check
                                label="A route needs its vehicle or resource"
                                checked={value.resource_required}
                                onChange={(resource_required) =>
                                    set({ resource_required })
                                }
                            />
                        )}
                        <TextField
                            label="Accuracy guidance, metres (optional)"
                            type="number"
                            value={value.accuracy_m}
                            onChange={(accuracy_m) => set({ accuracy_m })}
                            error={errors['location.accuracy_m']}
                        />
                        <p className="text-xs text-muted-foreground">
                            {value.mode === 'route'
                                ? 'People start, pause and end each trip themselves on the phone app; nothing is recorded until they do, and they see while it is on. Entries written during a trip are linked to it.'
                                : 'The phone or browser asks before taking the location, and the person sees it on the entry. No location is ever made up.'}
                        </p>
                    </>
                )}
                {!editable && (
                    <p className="text-xs text-muted-foreground">
                        This sheet cannot be changed now.
                    </p>
                )}
            </PanelBody>
        </Panel>
    );
}
