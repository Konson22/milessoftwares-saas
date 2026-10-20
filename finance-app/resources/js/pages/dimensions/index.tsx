import { Form, Head } from '@inertiajs/react';
import { FolderKanban, Plus, Shapes } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field } from '@/components/field';
import { useCan } from '@/lib/access';
import { store, update } from '@/routes/dimensions';

type Value = {
    id: string;
    kind: string;
    code: string;
    name: string;
    active: boolean;
};

type Props = { costCentres: Value[]; projects: Value[] };

function ValueFields({
    kind,
    value,
    errors,
}: {
    kind: string;
    value?: Value;
    errors: Record<string, string>;
}) {
    const id = value?.id ?? `new-${kind}`;

    return (
        <>
            {!value && <input type="hidden" name="kind" value={kind} />}
            <Field id={`${id}-code`} label="Code" error={errors.code}>
                <Input
                    id={`${id}-code`}
                    name="code"
                    required
                    maxLength={16}
                    defaultValue={value?.code}
                />
            </Field>
            <Field
                id={`${id}-name`}
                label="Name"
                error={errors.name}
                className="sm:col-span-2"
            >
                <Input
                    id={`${id}-name`}
                    name="name"
                    required
                    maxLength={255}
                    defaultValue={value?.name}
                />
            </Field>
            {value && (
                <label className="flex items-center gap-2 self-end pb-2 text-sm">
                    <input type="hidden" name="active" value="0" />
                    <input
                        type="checkbox"
                        name="active"
                        value="1"
                        defaultChecked={value.active}
                    />
                    Open for new lines
                </label>
            )}
        </>
    );
}

function Values({
    kind,
    title,
    singular,
    icon,
    hint,
    values,
    manage,
}: {
    kind: string;
    title: string;
    singular: string;
    icon: LucideIcon;
    hint: string;
    values: Value[];
    manage: boolean;
}) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <Panel className="mb-3">
            <PanelHeader
                title={title}
                actions={
                    manage && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setAdding((current) => !current)}
                        >
                            <Plus className="size-4" aria-hidden />
                            New {singular.toLowerCase()}
                        </Button>
                    )
                }
            />
            {adding && (
                <PanelBody>
                    <Form
                        {...store.form()}
                        onSuccess={() => setAdding(false)}
                        className="grid gap-3 sm:grid-cols-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <ValueFields kind={kind} errors={errors} />
                                <div className="self-end">
                                    <Button type="submit" disabled={processing}>
                                        Add {singular.toLowerCase()}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </PanelBody>
            )}
            {values.length === 0 ? (
                <EmptyState icon={icon} title={`No ${title.toLowerCase()} yet`}>
                    {hint}
                </EmptyState>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Code</TableHead>
                            <TableHead>Name</TableHead>
                            <TableHead>Status</TableHead>
                            {manage && <TableHead />}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {values.map((value) =>
                            editing === value.id ? (
                                <TableRow key={value.id}>
                                    <TableCell colSpan={manage ? 4 : 3}>
                                        <Form
                                            {...update.form(value.id)}
                                            onSuccess={() => setEditing(null)}
                                            className="grid gap-3 py-2 sm:grid-cols-4"
                                        >
                                            {({ errors, processing }) => (
                                                <>
                                                    <ValueFields
                                                        kind={kind}
                                                        value={value}
                                                        errors={errors}
                                                    />
                                                    <div className="flex gap-2 sm:col-span-4">
                                                        <Button
                                                            type="submit"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Save
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
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
                                    </TableCell>
                                </TableRow>
                            ) : (
                                <TableRow key={value.id}>
                                    <TableCell className="font-medium">
                                        {value.code}
                                    </TableCell>
                                    <TableCell>{value.name}</TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={
                                                value.active
                                                    ? 'success'
                                                    : 'neutral'
                                            }
                                        >
                                            {value.active ? 'Open' : 'Closed'}
                                        </StatusBadge>
                                    </TableCell>
                                    {manage && (
                                        <TableCell className="text-right">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setEditing(value.id)
                                                }
                                            >
                                                Edit
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ),
                        )}
                    </TableBody>
                </Table>
            )}
        </Panel>
    );
}

/**
 * Cost centres and projects: chosen on journal, invoice and bill lines to
 * report income and spending by what it was for.
 */
export default function Dimensions({ costCentres, projects }: Props) {
    const can = useCan();
    const manage = can('finance.accounts.manage');

    return (
        <>
            <Head title="Cost centres & projects" />
            <PageHeader
                title="Cost centres & projects"
                description="Chosen on journal, invoice and bill lines. An account can require them; see Reports → P&L by dimension."
            />
            <Values
                kind="cost_centre"
                title="Cost centres"
                singular="Cost centre"
                icon={Shapes}
                hint="For example: Administration, Pharmacy, Clinic."
                values={costCentres}
                manage={manage}
            />
            <Values
                kind="project"
                title="Projects"
                singular="Project"
                icon={FolderKanban}
                hint="For example a grant, a building works or an event."
                values={projects}
                manage={manage}
            />
        </>
    );
}
