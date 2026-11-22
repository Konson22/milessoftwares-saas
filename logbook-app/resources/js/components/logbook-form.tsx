import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { SelectField, TextArea, TextField } from '@/components/fields';
import { LOGBOOK_ICONS } from '@/components/logbook-icon';

export type LogbookData = {
    name: string;
    description: string | null;
    icon: string | null;
    restricted: boolean;
    departmentId: string | null;
};

export type DepartmentOption = { id: string; name: string };

/**
 * Name, description, icon and the department it belongs to. Who reaches it
 * is its members list, on the logbook's page. A manager for some
 * departments files logbooks under one of them; the whole workspace is for
 * workspace-wide managers.
 */
export function LogbookForm({
    initial,
    url,
    method,
    submitLabel,
    onDone,
    departments,
    canFileUnderWorkspace,
}: {
    initial: LogbookData;
    departments: DepartmentOption[];
    canFileUnderWorkspace: boolean;
    url: string;
    method: 'post' | 'put';
    submitLabel: string;
    onDone?: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        name: initial.name,
        description: initial.description ?? '',
        icon: initial.icon ?? 'book',
        department_id:
            initial.departmentId ??
            (canFileUnderWorkspace ? '' : (departments[0]?.id ?? '')),
    });
    const [busy, setBusy] = useState(false);

    return (
        <form
            className="grid gap-4"
            onSubmit={(e) => {
                e.preventDefault();
                setBusy(true);
                router.visit(url, {
                    method,
                    data,
                    preserveScroll: true,
                    onSuccess: () => onDone?.(),
                    onFinish: () => setBusy(false),
                });
            }}
        >
            <TextField
                label="Name"
                value={data.name}
                onChange={(name) => setData({ ...data, name })}
                error={errors.name}
                placeholder="e.g. Facility Management"
                required
            />
            <SelectField
                label="Department"
                value={data.department_id}
                onChange={(department_id) =>
                    setData({ ...data, department_id })
                }
                empty={canFileUnderWorkspace ? 'Whole workspace' : undefined}
                options={departments.map((d) => ({
                    value: d.id,
                    label: d.name,
                }))}
                error={errors.department_id}
                hint={
                    data.department_id
                        ? 'Its members must have a Logbook role for this department (or the whole workspace).'
                        : 'Its members must have a Logbook role for the whole workspace.'
                }
            />
            <TextArea
                label="Description"
                value={data.description}
                onChange={(description) => setData({ ...data, description })}
                error={errors.description}
                rows={2}
            />
            <fieldset className="grid gap-1.5">
                <legend className="mb-1.5 text-sm font-medium">Icon</legend>
                <div className="flex flex-wrap gap-2">
                    {Object.entries(LOGBOOK_ICONS).map(([key, Icon]) => (
                        <button
                            key={key}
                            type="button"
                            aria-label={key}
                            aria-pressed={data.icon === key}
                            onClick={() => setData({ ...data, icon: key })}
                            className={`rounded-md border p-2 ${
                                data.icon === key
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'hover:bg-muted'
                            }`}
                        >
                            <Icon className="size-4" />
                        </button>
                    ))}
                </div>
            </fieldset>
            <div>
                <Button type="submit" disabled={busy}>
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
