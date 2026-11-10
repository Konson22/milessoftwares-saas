import { Form, Head, router } from '@inertiajs/react';
import { Network } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select, invalid } from '@/components/field';
import { archive, restore, store, update } from '@/routes/admin/org-units';
import type { Option } from '@/types';

type Unit = {
    id: string;
    parentId: string | null;
    kind: string;
    name: string;
    code: string | null;
    archived: boolean;
    members: number;
};

const keep = { preserveScroll: true };

/**
 * Parents first, each followed by what sits inside it. A unit whose parent is
 * not listed (archived units are listed apart) starts its own branch.
 */
function arranged(units: Unit[]): { unit: Unit; depth: number }[] {
    const known = new Set(units.map((unit) => unit.id));
    const rows: { unit: Unit; depth: number }[] = [];
    const add = (parentId: string | null, depth: number) => {
        for (const unit of units) {
            const top = unit.parentId === null || !known.has(unit.parentId);
            if (parentId === null ? top : unit.parentId === parentId) {
                rows.push({ unit, depth });
                add(unit.id, depth + 1);
            }
        }
    };
    add(null, 0);

    return rows;
}

function UnitFields({
    unit,
    kinds,
    parents,
    errors,
}: {
    unit?: Unit;
    kinds: Option[];
    parents: Option[];
    errors: Record<string, string | undefined>;
}) {
    const prefix = unit ? `unit-${unit.id}` : 'new-unit';

    return (
        <>
            <Field id={`${prefix}-name`} label="Name" error={errors.name}>
                <Input
                    id={`${prefix}-name`}
                    name="name"
                    required
                    maxLength={120}
                    defaultValue={unit?.name}
                    {...invalid(`${prefix}-name`, errors.name)}
                />
            </Field>
            <Field id={`${prefix}-kind`} label="Kind" error={errors.kind}>
                <Select
                    id={`${prefix}-kind`}
                    name="kind"
                    options={kinds}
                    defaultValue={unit?.kind}
                />
            </Field>
            <Field
                id={`${prefix}-parent`}
                label="Sits inside"
                error={errors.parent_id}
            >
                <Select
                    id={`${prefix}-parent`}
                    name="parent_id"
                    placeholder="Nothing: top level"
                    options={parents}
                    defaultValue={unit?.parentId ?? ''}
                />
            </Field>
            <Field
                id={`${prefix}-code`}
                label="Code"
                error={errors.code}
                hint="Optional."
            >
                <Input
                    id={`${prefix}-code`}
                    name="code"
                    maxLength={24}
                    defaultValue={unit?.code ?? ''}
                    {...invalid(`${prefix}-code`, errors.code)}
                />
            </Field>
        </>
    );
}

/**
 * Administration → Departments and teams: how the organization is arranged.
 */
export default function OrgUnits({
    units,
    kinds,
}: {
    units: Unit[];
    kinds: Option[];
}) {
    const [editing, setEditing] = useState<string | null>(null);
    const active = units.filter((unit) => !unit.archived);
    const archived = units.filter((unit) => unit.archived);
    const label = (kind: string) =>
        kinds.find((option) => option.value === kind)?.label ?? kind;
    const parents = (except?: string): Option[] =>
        active
            .filter((unit) => unit.id !== except)
            .map((unit) => ({ value: unit.id, label: unit.name }));

    return (
        <>
            <Head title="Departments and teams" />
            <PageHeader
                title="Departments and teams"
                description="How your organization is arranged. Add members to a unit from the Members page."
            />

            <Panel>
                <PanelHeader title="Add a unit" />
                <PanelBody>
                    <Form
                        {...store.form()}
                        options={keep}
                        resetOnSuccess
                        className="grid gap-4 sm:grid-cols-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <UnitFields
                                    kinds={kinds}
                                    parents={parents()}
                                    errors={errors}
                                />
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        Add unit
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </PanelBody>
            </Panel>

            <Panel className="mt-4">
                {active.length === 0 ? (
                    <EmptyState icon={Network} title="No units yet">
                        Add your first department, team, branch or country
                        office above.
                    </EmptyState>
                ) : (
                    <ul className="divide-y">
                        {arranged(active).map(({ unit, depth }) => (
                            <li key={unit.id} className="px-4 py-3">
                                {editing === unit.id ? (
                                    <Form
                                        {...update.form(unit.id)}
                                        options={keep}
                                        onSuccess={() => setEditing(null)}
                                        className="grid gap-4 sm:grid-cols-2"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <UnitFields
                                                    unit={unit}
                                                    kinds={kinds}
                                                    parents={parents(unit.id)}
                                                    errors={errors}
                                                />
                                                <div className="flex gap-2 sm:col-span-2">
                                                    <Button
                                                        type="submit"
                                                        disabled={processing}
                                                    >
                                                        Save
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        onClick={() =>
                                                            setEditing(null)
                                                        }
                                                    >
                                                        Cancel
                                                    </Button>
                                                </div>
                                            </>
                                        )}
                                    </Form>
                                ) : (
                                    <div
                                        className="flex flex-wrap items-center justify-between gap-3"
                                        style={{ paddingLeft: depth * 24 }}
                                    >
                                        <span>
                                            <span className="font-medium">
                                                {unit.name}
                                            </span>
                                            <span className="block text-sm text-muted-foreground">
                                                {label(unit.kind)}
                                                {unit.code
                                                    ? ` · ${unit.code}`
                                                    : ''}
                                                {` · ${unit.members} ${unit.members === 1 ? 'member' : 'members'}`}
                                            </span>
                                        </span>
                                        <span className="flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    setEditing(unit.id)
                                                }
                                            >
                                                Edit
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        archive(unit.id).url,
                                                        {},
                                                        keep,
                                                    )
                                                }
                                            >
                                                Archive
                                            </Button>
                                        </span>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            {archived.length > 0 && (
                <Panel className="mt-4">
                    <PanelHeader title="Archived" />
                    <ul className="divide-y">
                        {archived.map((unit) => (
                            <li
                                key={unit.id}
                                className="flex items-center justify-between gap-3 px-4 py-3 text-sm"
                            >
                                <span className="text-muted-foreground">
                                    {unit.name} · {label(unit.kind)}
                                </span>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            restore(unit.id).url,
                                            {},
                                            keep,
                                        )
                                    }
                                >
                                    Restore
                                </Button>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
        </>
    );
}
