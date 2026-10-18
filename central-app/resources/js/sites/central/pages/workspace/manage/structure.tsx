import { Form, Head, router } from '@inertiajs/react';
import { Building2, Pencil, Plus, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { EmptyState } from '@/shared/components/empty-state';
import InputError from '@/shared/components/input-error';
import { PageHeader } from '@/shared/components/page-header';
import { StatusBadge } from '@/shared/components/status-badge';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/shared/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/shared/components/ui/dialog';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/shared/components/ui/table';

type Status = 'active' | 'inactive' | 'archived';

type Branch = {
    id: string;
    name: string;
    code: string;
    city: string | null;
    status: Status;
};

type Department = {
    id: string;
    name: string;
    code: string;
    branch_id: string | null;
    parent_department_id: string | null;
    status: Status;
};

type Position = {
    id: string;
    title: string;
    code: string;
    department_id: string | null;
    status: Status;
};

type Props = {
    branches: Branch[];
    departments: Department[];
    positions: Position[];
};

/** Which form is open: a kind, and the record when editing one. */
type Editing =
    | { kind: 'branch'; record: Branch | null }
    | { kind: 'department'; record: Department | null }
    | { kind: 'position'; record: Position | null }
    | null;

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm';

export default function Structure({ branches, departments, positions }: Props) {
    const [branchQuery, setBranchQuery] = useState('');
    const [editing, setEditing] = useState<Editing>(null);
    const close = () => setEditing(null);

    const branchName = (id: string | null) =>
        branches.find((branch) => branch.id === id)?.name;
    const departmentName = (id: string | null) =>
        departments.find((department) => department.id === id)?.name;

    // Archiving keeps the record: assignments and memberships refer to it.
    const toggleArchive = (
        url: string,
        record: Record<string, unknown> & { status: Status },
    ) =>
        router.put(
            url,
            {
                ...record,
                status: record.status === 'archived' ? 'active' : 'archived',
            },
            { preserveScroll: true },
        );

    const filteredBranches = useMemo(() => {
        const q = branchQuery.trim().toLowerCase();
        if (!q) {
            return branches;
        }

        return branches.filter(
            (branch) =>
                branch.name.toLowerCase().includes(q) ||
                branch.code.toLowerCase().includes(q) ||
                (branch.city?.toLowerCase().includes(q) ?? false),
        );
    }, [branches, branchQuery]);

    const activeBranches = branches.filter((b) => b.status === 'active');
    const activeDepartments = departments.filter((d) => d.status === 'active');

    return (
        <>
            <Head title="Structure" />

            <div className="space-y-3">
                <PageHeader
                    title="Organization structure"
                    description="Manage branches, departments, and positions"
                    icon={Building2}
                    actions={
                        <Button
                            size="sm"
                            onClick={() =>
                                setEditing({ kind: 'branch', record: null })
                            }
                        >
                            <Plus className="size-4" />
                            Add branch
                        </Button>
                    }
                />

                <Card className="gap-0 overflow-hidden rounded-md py-0 shadow-none">
                    <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                        <CardTitle className="text-sm font-semibold">
                            Branches
                        </CardTitle>
                        <div className="relative w-full max-w-xs">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={branchQuery}
                                onChange={(event) =>
                                    setBranchQuery(event.target.value)
                                }
                                placeholder="Search name, code, or city"
                                aria-label="Search branches"
                                className="h-8 pl-8 text-xs"
                            />
                        </div>
                    </CardHeader>
                    <CardContent className="p-0">
                        {filteredBranches.length === 0 ? (
                            <EmptyState
                                title={
                                    branches.length === 0
                                        ? 'No branches yet'
                                        : 'No matching branches'
                                }
                                description={
                                    branches.length === 0
                                        ? 'Add a branch for each location you work from. Products such as Pharmacy keep stock and sales per branch.'
                                        : 'Try a different search term.'
                                }
                                action={
                                    branches.length === 0 ? (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                setEditing({
                                                    kind: 'branch',
                                                    record: null,
                                                })
                                            }
                                        >
                                            <Plus className="size-4" />
                                            Add branch
                                        </Button>
                                    ) : undefined
                                }
                            />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Branch</TableHead>
                                        <TableHead>Code</TableHead>
                                        <TableHead>Location</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">
                                            Actions
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {filteredBranches.map((branch) => (
                                        <TableRow key={branch.id}>
                                            <TableCell className="font-medium text-foreground">
                                                {branch.name}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {branch.code}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {branch.city ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={branch.status}
                                                />
                                            </TableCell>
                                            <TableCell className="space-x-1 text-right">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="h-7 text-xs"
                                                    onClick={() =>
                                                        setEditing({
                                                            kind: 'branch',
                                                            record: branch,
                                                        })
                                                    }
                                                >
                                                    <Pencil className="size-3.5" />
                                                    Edit
                                                    <span className="sr-only">
                                                        {' '}
                                                        {branch.name}
                                                    </span>
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-7 text-xs"
                                                    onClick={() =>
                                                        toggleArchive(
                                                            `/manage/branches/${branch.id}`,
                                                            branch,
                                                        )
                                                    }
                                                >
                                                    {branch.status ===
                                                    'archived'
                                                        ? 'Restore'
                                                        : 'Archive'}
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-3 lg:grid-cols-2">
                    <Section
                        title="Departments"
                        addLabel="Add department"
                        onAdd={() =>
                            setEditing({ kind: 'department', record: null })
                        }
                    >
                        <List
                            items={departments.map((department) => ({
                                id: department.id,
                                title: department.name,
                                detail: [
                                    department.code,
                                    branchName(department.branch_id) ??
                                        'All branches',
                                    department.parent_department_id &&
                                        `under ${departmentName(department.parent_department_id)}`,
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
                                status: department.status,
                                onEdit: () =>
                                    setEditing({
                                        kind: 'department',
                                        record: department,
                                    }),
                                onToggle: () =>
                                    toggleArchive(
                                        `/manage/departments/${department.id}`,
                                        department,
                                    ),
                            }))}
                        />
                    </Section>

                    <Section
                        title="Positions"
                        addLabel="Add position"
                        onAdd={() =>
                            setEditing({ kind: 'position', record: null })
                        }
                    >
                        <List
                            items={positions.map((position) => ({
                                id: position.id,
                                title: position.title,
                                detail: [
                                    position.code,
                                    departmentName(position.department_id),
                                ]
                                    .filter(Boolean)
                                    .join(' · '),
                                status: position.status,
                                onEdit: () =>
                                    setEditing({
                                        kind: 'position',
                                        record: position,
                                    }),
                                onToggle: () =>
                                    toggleArchive(
                                        `/manage/positions/${position.id}`,
                                        position,
                                    ),
                            }))}
                        />
                    </Section>
                </div>
            </div>

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => !open && close()}
            >
                <DialogContent className="sm:max-w-md">
                    {editing?.kind === 'branch' && (
                        <RecordForm
                            noun="branch"
                            record={editing.record}
                            url="/manage/branches"
                            onDone={close}
                        >
                            {(errors) => (
                                <>
                                    <Field
                                        label="Name"
                                        name="name"
                                        defaultValue={editing.record?.name}
                                        error={errors.name}
                                        autoFocus
                                    />
                                    <Field
                                        label="Code"
                                        name="code"
                                        defaultValue={editing.record?.code}
                                        error={errors.code}
                                        hint="Short and unique, e.g. JUB"
                                    />
                                    <Field
                                        label="City"
                                        name="city"
                                        defaultValue={
                                            editing.record?.city ?? undefined
                                        }
                                        error={errors.city}
                                    />
                                </>
                            )}
                        </RecordForm>
                    )}

                    {editing?.kind === 'department' && (
                        <RecordForm
                            noun="department"
                            record={editing.record}
                            url="/manage/departments"
                            onDone={close}
                        >
                            {(errors) => (
                                <>
                                    <Field
                                        label="Name"
                                        name="name"
                                        defaultValue={editing.record?.name}
                                        error={errors.name}
                                        autoFocus
                                    />
                                    <Field
                                        label="Code"
                                        name="code"
                                        defaultValue={editing.record?.code}
                                        error={errors.code}
                                    />
                                    <Select
                                        label="Branch"
                                        name="branch_id"
                                        defaultValue={
                                            editing.record?.branch_id ?? ''
                                        }
                                        error={errors.branch_id}
                                        empty="All branches"
                                        options={activeBranches.map((b) => ({
                                            value: b.id,
                                            label: b.name,
                                        }))}
                                    />
                                    <Select
                                        label="Part of"
                                        name="parent_department_id"
                                        defaultValue={
                                            editing.record
                                                ?.parent_department_id ?? ''
                                        }
                                        error={errors.parent_department_id}
                                        empty="—"
                                        options={activeDepartments
                                            .filter(
                                                (d) =>
                                                    d.id !== editing.record?.id,
                                            )
                                            .map((d) => ({
                                                value: d.id,
                                                label: d.name,
                                            }))}
                                    />
                                </>
                            )}
                        </RecordForm>
                    )}

                    {editing?.kind === 'position' && (
                        <RecordForm
                            noun="position"
                            record={editing.record}
                            url="/manage/positions"
                            onDone={close}
                        >
                            {(errors) => (
                                <>
                                    <Field
                                        label="Title"
                                        name="title"
                                        defaultValue={editing.record?.title}
                                        error={errors.title}
                                        autoFocus
                                    />
                                    <Field
                                        label="Code"
                                        name="code"
                                        defaultValue={editing.record?.code}
                                        error={errors.code}
                                    />
                                    <Select
                                        label="Department"
                                        name="department_id"
                                        defaultValue={
                                            editing.record?.department_id ?? ''
                                        }
                                        error={errors.department_id}
                                        empty="—"
                                        options={activeDepartments.map((d) => ({
                                            value: d.id,
                                            label: d.name,
                                        }))}
                                    />
                                </>
                            )}
                        </RecordForm>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

/**
 * The modal's form: creates a record, or updates one (keeping its status).
 * Closes when the server accepts it; errors stay in the modal.
 */
function RecordForm({
    noun,
    record,
    url,
    onDone,
    children,
}: {
    noun: string;
    record: { id: string; status: Status } | null;
    url: string;
    onDone: () => void;
    children: (errors: Record<string, string>) => ReactNode;
}) {
    return (
        <Form
            action={record ? `${url}/${record.id}` : url}
            method={record ? 'put' : 'post'}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="space-y-4"
        >
            {({ errors, processing }) => (
                <>
                    <DialogHeader>
                        <DialogTitle>
                            {record ? `Edit ${noun}` : `Add ${noun}`}
                        </DialogTitle>
                        <DialogDescription>
                            {record
                                ? `Changes apply wherever this ${noun} is used.`
                                : `The ${noun} is available to every application in this workspace.`}
                        </DialogDescription>
                    </DialogHeader>
                    {record && (
                        <input
                            type="hidden"
                            name="status"
                            value={record.status}
                        />
                    )}
                    <div className="space-y-3">{children(errors)}</div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {record ? 'Save' : `Add ${noun}`}
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

function Section({
    title,
    addLabel,
    onAdd,
    children,
}: {
    title: string;
    addLabel: string;
    onAdd: () => void;
    children: ReactNode;
}) {
    return (
        <Card className="gap-0 rounded-md py-0 shadow-none">
            <CardHeader className="flex flex-row items-center justify-between gap-3 border-b px-4 py-3">
                <CardTitle className="text-sm font-semibold">{title}</CardTitle>
                <Button size="sm" variant="outline" onClick={onAdd}>
                    <Plus className="size-3.5" />
                    {addLabel}
                </Button>
            </CardHeader>
            <CardContent className="px-4 py-4">{children}</CardContent>
        </Card>
    );
}

function Field({
    label,
    name,
    defaultValue,
    error,
    hint,
    autoFocus,
}: {
    label: string;
    name: string;
    defaultValue?: string;
    error?: string;
    hint?: string;
    autoFocus?: boolean;
}) {
    const id = `structure-${name}`;

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                name={name}
                defaultValue={defaultValue}
                autoFocus={autoFocus}
                aria-invalid={error ? true : undefined}
            />
            {hint && !error && (
                <p className="text-xs text-muted-foreground">{hint}</p>
            )}
            <InputError message={error} />
        </div>
    );
}

function Select({
    label,
    name,
    defaultValue,
    error,
    empty,
    options,
}: {
    label: string;
    name: string;
    defaultValue: string;
    error?: string;
    empty: string;
    options: { value: string; label: string }[];
}) {
    const id = `structure-${name}`;

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                name={name}
                className={selectClass}
                defaultValue={defaultValue}
            >
                <option value="">{empty}</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            <InputError message={error} />
        </div>
    );
}

function List({
    items,
}: {
    items: {
        id: string;
        title: string;
        detail: string;
        status: Status;
        onEdit: () => void;
        onToggle: () => void;
    }[];
}) {
    if (items.length === 0) {
        return <EmptyState className="py-8" title="None yet" />;
    }

    return (
        <ul className="divide-y rounded-md border">
            {items.map((item) => (
                <li key={item.id} className="flex items-center gap-2 px-3 py-2">
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium">{item.title}</p>
                        <p className="truncate text-xs text-muted-foreground">
                            {item.detail}
                        </p>
                    </div>
                    {item.status !== 'active' && (
                        <Badge variant="secondary">{item.status}</Badge>
                    )}
                    <Button size="sm" variant="ghost" onClick={item.onEdit}>
                        <Pencil className="size-3.5" />
                        <span className="sr-only">Edit {item.title}</span>
                    </Button>
                    <Button size="sm" variant="ghost" onClick={item.onToggle}>
                        {item.status === 'archived' ? 'Restore' : 'Archive'}
                    </Button>
                </li>
            ))}
        </ul>
    );
}
