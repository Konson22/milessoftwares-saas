import { router, usePage } from '@inertiajs/react';
import { Paperclip, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import type { FieldDef, FileRef, Option, ResourceOption } from '@/lib/logbook';
import { fieldVisible, fileSize } from '@/lib/logbook';
import {
    destroy as destroyAttachment,
    show as showAttachment,
    store as storeAttachment,
} from '@/routes/attachments';
import { useConfirm } from '@/components/dialogs';

export type Values = Record<string, unknown>;

const inputClass =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring';

/**
 * Renders a template's fields as a form. Used for entries and for the
 * builder's preview, so the preview is exactly what contributors see. The
 * server checks every value again against the template version.
 */
export function EntryFields({
    fields,
    values,
    onChange,
    errors,
    members,
    resources = [],
    files,
    entryId,
}: {
    fields: FieldDef[];
    values: Values;
    onChange: (name: string, value: unknown) => void;
    errors: Record<string, string>;
    members: Option[];
    /** Active resources, for resource fields. */
    resources?: ResourceOption[];
    /** Live attachments per file field; undefined in the preview. */
    files?: Record<string, FileRef[]>;
    /** Saved entry id: files can be attached once the entry exists. */
    entryId?: string | null;
}) {
    return (
        <div className="grid gap-5">
            {fields.map((field) => {
                // Hidden by its condition: not asked, and cleared on save.
                if (!fieldVisible(field, values, fields)) {
                    return null;
                }
                const id = `field-${field.name}`;
                const error = errors[`values.${field.name}`];

                return (
                    <div key={field.name} className="grid gap-1.5">
                        <Label htmlFor={id}>
                            {field.label}
                            {field.required && (
                                <span className="text-destructive"> *</span>
                            )}
                        </Label>
                        <Control
                            id={id}
                            field={field}
                            value={values[field.name]}
                            onChange={(v) => onChange(field.name, v)}
                            members={members}
                            resources={resources}
                            invalid={!!error}
                            files={files?.[field.name]}
                            entryId={entryId}
                        />
                        {field.assigned_only && (
                            <p className="text-xs text-muted-foreground">
                                {(field.resource_ids ?? []).length === 0
                                    ? 'Nothing is assigned to you on this day. A manager assigns vehicles and other assets.'
                                    : 'Only what is assigned to you on this day.'}
                            </p>
                        )}
                        {field.help && (
                            <p className="text-xs text-muted-foreground">
                                {field.help}
                            </p>
                        )}
                        {error && (
                            <p className="text-xs text-destructive">{error}</p>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function Control({
    id,
    field,
    value,
    onChange,
    members,
    resources,
    invalid,
    files,
    entryId,
}: {
    id: string;
    field: FieldDef;
    value: unknown;
    onChange: (value: unknown) => void;
    members: Option[];
    resources: ResourceOption[];
    invalid: boolean;
    files?: FileRef[];
    entryId?: string | null;
}) {
    const text =
        typeof value === 'string' || typeof value === 'number'
            ? String(value)
            : '';
    const common = {
        id,
        'aria-invalid': invalid || undefined,
    };

    switch (field.type) {
        case 'textarea':
            return (
                <textarea
                    data-slot="textarea"
                    {...common}
                    rows={4}
                    value={text}
                    placeholder={field.placeholder}
                    maxLength={field.max_length ?? 10000}
                    onChange={(e) => onChange(e.target.value)}
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring"
                />
            );
        case 'integer':
        case 'decimal':
            return (
                <NumberInput
                    field={field}
                    text={text}
                    onChange={onChange}
                    common={common}
                />
            );
        case 'date':
        case 'time':
            return (
                <Input
                    {...common}
                    type={field.type}
                    value={text}
                    onChange={(e) => onChange(e.target.value)}
                    className="max-w-xs"
                />
            );
        case 'datetime':
            return (
                <Input
                    {...common}
                    type="datetime-local"
                    value={text.slice(0, 16)}
                    onChange={(e) => onChange(e.target.value)}
                    className="max-w-xs"
                />
            );
        case 'select_one':
            return (
                <select
                    data-slot="select"
                    {...common}
                    value={text}
                    onChange={(e) => onChange(e.target.value)}
                    className={`${inputClass} max-w-md`}
                >
                    <option value="">Choose…</option>
                    {(field.choices ?? []).map((c) => (
                        <option key={c.value} value={c.value}>
                            {c.label}
                        </option>
                    ))}
                </select>
            );
        case 'select_multiple': {
            const picked = Array.isArray(value) ? (value as string[]) : [];

            return (
                <div id={id} className="grid gap-1.5 sm:grid-cols-2">
                    {(field.choices ?? []).map((c) => (
                        <label
                            key={c.value}
                            className="flex items-center gap-2 text-sm"
                        >
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={picked.includes(c.value)}
                                onChange={(e) =>
                                    onChange(
                                        e.target.checked
                                            ? [...picked, c.value]
                                            : picked.filter(
                                                  (v) => v !== c.value,
                                              ),
                                    )
                                }
                            />
                            {c.label}
                        </label>
                    ))}
                </div>
            );
        }
        case 'yes_no':
            return (
                <div id={id} role="radiogroup" className="flex gap-4 text-sm">
                    {[
                        [true, 'Yes'],
                        [false, 'No'],
                    ].map(([v, label]) => (
                        <label
                            key={String(v)}
                            className="flex items-center gap-2"
                        >
                            <input
                                type="radio"
                                name={id}
                                className="size-4"
                                checked={value === v}
                                onChange={() => onChange(v)}
                            />
                            {label as string}
                        </label>
                    ))}
                    {(value === true || value === false) && !field.required && (
                        <button
                            type="button"
                            className="text-xs text-muted-foreground underline"
                            onClick={() => onChange(null)}
                        >
                            Clear
                        </button>
                    )}
                </div>
            );
        case 'user':
            return (
                <select
                    data-slot="select"
                    {...common}
                    value={text}
                    onChange={(e) => onChange(e.target.value)}
                    className={`${inputClass} max-w-md`}
                >
                    <option value="">Choose a person…</option>
                    {members.map((m) => (
                        <option key={m.value} value={m.value}>
                            {m.label}
                        </option>
                    ))}
                </select>
            );
        case 'resource':
            return (
                <select
                    data-slot="select"
                    {...common}
                    value={text}
                    onChange={(e) => onChange(e.target.value)}
                    className={`${inputClass} max-w-md`}
                >
                    <option value="">Choose a resource…</option>
                    {resources
                        .filter(
                            (r) =>
                                resourceMatches(r, field.resource_type) &&
                                offered(field, r.value),
                        )
                        .map((r) => (
                            <option key={r.value} value={r.value}>
                                {r.label}
                            </option>
                        ))}
                    {/* A saved choice no longer offered stays visible, never swapped for another. */}
                    {text !== '' &&
                        !resources.some(
                            (r) => r.value === text && offered(field, r.value),
                        ) && (
                            <option value={text} disabled>
                                No longer offered: choose another
                            </option>
                        )}
                </select>
            );
        case 'file':
            return (
                <FileControl
                    id={id}
                    field={field.name}
                    files={files}
                    entryId={entryId}
                />
            );
        default:
            return (
                <Input
                    {...common}
                    type="text"
                    value={text}
                    placeholder={field.placeholder}
                    maxLength={field.max_length ?? 1000}
                    onChange={(e) => onChange(e.target.value)}
                />
            );
    }
}

/**
 * Files of one field: listed with download and remove, and uploaded one at
 * a time to the saved entry (stored privately on the server).
 */
function FileControl({
    id,
    field,
    files,
    entryId,
}: {
    id: string;
    field: string;
    files?: FileRef[];
    entryId?: string | null;
}) {
    const [busy, setBusy] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;
    const ask = useConfirm();

    if (files === undefined) {
        return (
            <p className="text-sm text-muted-foreground">
                Photos and documents are attached here.
            </p>
        );
    }
    if (!entryId) {
        return (
            <p className="text-sm text-muted-foreground">
                Save the draft first, then attach photos or documents.
            </p>
        );
    }

    return (
        <div className="grid gap-2">
            {files.length > 0 && (
                <ul className="grid gap-1 text-sm">
                    {files.map((file) => (
                        <li key={file.id} className="flex items-center gap-2">
                            <Paperclip className="size-4 text-muted-foreground" />
                            <a
                                href={showAttachment(file.id).url}
                                className="hover:underline"
                            >
                                {file.name}
                            </a>
                            <span className="text-xs text-muted-foreground">
                                {fileSize(file.size)}
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                aria-label={`Remove ${file.name}`}
                                disabled={busy}
                                onClick={async () => {
                                    if (
                                        !(await ask({
                                            title: `Remove ${file.name}?`,
                                            confirmLabel: 'Remove',
                                            destructive: true,
                                        }))
                                    ) {
                                        return;
                                    }
                                    setBusy(true);
                                    router.delete(
                                        destroyAttachment(file.id).url,
                                        {
                                            preserveScroll: true,
                                            preserveState: true,
                                            onFinish: () => setBusy(false),
                                        },
                                    );
                                }}
                            >
                                <Trash2 />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            <Input
                id={id}
                type="file"
                accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt"
                className="max-w-sm"
                disabled={busy}
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (!file) {
                        return;
                    }
                    setBusy(true);
                    router.post(
                        storeAttachment(entryId).url,
                        { field, file },
                        {
                            forceFormData: true,
                            preserveScroll: true,
                            preserveState: true,
                            onFinish: () => {
                                setBusy(false);
                                e.target.value = '';
                            },
                        },
                    );
                }}
            />
            <p className="text-xs text-muted-foreground">
                Photos, PDF or office documents, up to 10 MB each.
                {busy && ' Uploading…'}
            </p>
            {errors.file && (
                <p className="text-xs text-destructive">{errors.file}</p>
            )}
        </div>
    );
}

/** The normal-range bounds of a number field, as numbers. */
function bounds(field: FieldDef): { low: number | null; high: number | null } {
    const num = (v: string | number | undefined) =>
        v === undefined || v === '' || Number.isNaN(Number(v))
            ? null
            : Number(v);

    return { low: num(field.expected_min), high: num(field.expected_max) };
}

/**
 * A number with its unit, and a warning (not an error) when the reading is
 * outside the normal range: it is still saved, and flagged for review.
 */
function NumberInput({
    field,
    text,
    onChange,
    common,
}: {
    field: FieldDef;
    text: string;
    onChange: (value: string) => void;
    common: Record<string, unknown>;
}) {
    const { low, high } = bounds(field);
    const value = text.trim() === '' ? null : Number(text);
    const out =
        value !== null &&
        !Number.isNaN(value) &&
        ((low !== null && value < low) || (high !== null && value > high));
    const unit = field.unit ? ` ${field.unit}` : '';
    const range =
        low !== null && high !== null
            ? `${low} to ${high}${unit}`
            : low !== null
              ? `at least ${low}${unit}`
              : high !== null
                ? `at most ${high}${unit}`
                : null;

    return (
        <div className="grid gap-1">
            <div className="flex max-w-xs items-center gap-2">
                <Input
                    {...common}
                    type="text"
                    inputMode={field.type === 'integer' ? 'numeric' : 'decimal'}
                    value={text}
                    placeholder={field.placeholder}
                    onChange={(e) => onChange(e.target.value)}
                    className={out ? 'border-amber-500' : undefined}
                />
                {field.unit && (
                    <span className="shrink-0 text-sm text-muted-foreground">
                        {field.unit}
                    </span>
                )}
            </div>
            {range && (
                <p
                    className={
                        out
                            ? 'text-xs font-medium text-amber-800'
                            : 'text-xs text-muted-foreground'
                    }
                >
                    {out
                        ? `Outside the normal range (${range}). It will be saved and flagged for the reviewer.`
                        : `Normal range: ${range}.`}
                </p>
            )}
        </div>
    );
}

/**
 * ADR-0031: a sheet with linked assets offers only those. ADR-0032: an
 * "assigned only" sheet offers only the author's assigned assets, and
 * nothing when none is assigned.
 */
function offered(field: FieldDef, id: string): boolean {
    if (field.assigned_only) {
        return (field.resource_ids ?? []).includes(id);
    }

    return (
        !field.resource_ids ||
        field.resource_ids.length === 0 ||
        field.resource_ids.includes(id)
    );
}

/** A resource field asks for a type, or for a whole category by its name. */
function resourceMatches(r: ResourceOption, wanted?: string): boolean {
    if (!wanted) {
        return true;
    }
    const w = wanted.trim().toLowerCase();

    return r.type.toLowerCase() === w || (r.category ?? '').toLowerCase() === w;
}
