import { Form, Head, router } from '@inertiajs/react';
import { Building2, Plus, Search } from 'lucide-react';
import { useMemo, useRef, useState, type ReactNode } from 'react';
import { EmptyState } from '@/shared/components/empty-state';
import InputError from '@/shared/components/input-error';
import { PageHeader } from '@/shared/components/page-header';
import { PageToolbar } from '@/shared/components/page-toolbar';
import { StatusBadge } from '@/shared/components/status-badge';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/shared/components/ui/card';
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

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export default function Structure({ branches, departments, positions }: Props) {
    const [branchQuery, setBranchQuery] = useState('');
    const createBranchRef = useRef<HTMLDivElement>(null);

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

    const focusCreateBranch = () => {
        createBranchRef.current?.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        });
        createBranchRef.current
            ?.querySelector<HTMLInputElement>('input[name="name"]')
            ?.focus();
    };

    return (
        <>
            <Head title="Structure" />

            <div className="space-y-6">
                <PageHeader
                    title="Organization structure"
                    description="Manage branches, departments, and positions"
                    icon={Building2}
                    actions={
                        <Button size="sm" onClick={focusCreateBranch}>
                            <Plus className="size-4" />
                            Create branch
                        </Button>
                    }
                />

                <Card className="gap-0 overflow-hidden rounded-md py-0 shadow-none">
                    <CardHeader className="border-b px-4 py-3">
                        <CardTitle className="text-sm font-semibold">
                            Branches
                        </CardTitle>
                    </CardHeader>
                    <div className="px-4 pt-3">
                        <PageToolbar
                            left={
                                <div className="relative w-full max-w-sm">
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
                            }
                            right={
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={focusCreateBranch}
                                >
                                    <Plus className="size-3.5" />
                                    Add branch
                                </Button>
                            }
                        />
                    </div>
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
                                        ? 'Create a branch to organize your locations.'
                                        : 'Try a different search term.'
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
                                            <TableCell className="text-right">
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
                    <div
                        ref={createBranchRef}
                        className="border-t bg-muted/20 px-4 py-4"
                    >
                        <p className="mb-3 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Create branch
                        </p>
                        <Form
                            action="/manage/branches"
                            method="post"
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="grid gap-3 sm:grid-cols-4 sm:items-end"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        label="Name"
                                        name="name"
                                        error={errors.name}
                                    />
                                    <Field
                                        label="Code"
                                        name="code"
                                        error={errors.code}
                                    />
                                    <Field
                                        label="City"
                                        name="city"
                                        error={errors.city}
                                    />
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                        className="sm:mb-0.5"
                                    >
                                        Add branch
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                </Card>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Section title="Departments">
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
                                onToggle: () =>
                                    toggleArchive(
                                        `/manage/departments/${department.id}`,
                                        department,
                                    ),
                            }))}
                        />
                        <Form
                            action="/manage/departments"
                            method="post"
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="space-y-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        label="Name"
                                        name="name"
                                        error={errors.name}
                                    />
                                    <Field
                                        label="Code"
                                        name="code"
                                        error={errors.code}
                                    />
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="department-branch">
                                            Branch
                                        </Label>
                                        <select
                                            id="department-branch"
                                            name="branch_id"
                                            className={selectClass}
                                            defaultValue=""
                                        >
                                            <option value="">
                                                All branches
                                            </option>
                                            {branches
                                                .filter(
                                                    (branch) =>
                                                        branch.status ===
                                                        'active',
                                                )
                                                .map((branch) => (
                                                    <option
                                                        key={branch.id}
                                                        value={branch.id}
                                                    >
                                                        {branch.name}
                                                    </option>
                                                ))}
                                        </select>
                                        <InputError
                                            message={errors.branch_id}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="department-parent">
                                            Part of
                                        </Label>
                                        <select
                                            id="department-parent"
                                            name="parent_department_id"
                                            className={selectClass}
                                            defaultValue=""
                                        >
                                            <option value="">—</option>
                                            {departments
                                                .filter(
                                                    (department) =>
                                                        department.status ===
                                                        'active',
                                                )
                                                .map((department) => (
                                                    <option
                                                        key={department.id}
                                                        value={department.id}
                                                    >
                                                        {department.name}
                                                    </option>
                                                ))}
                                        </select>
                                        <InputError
                                            message={
                                                errors.parent_department_id
                                            }
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        Add department
                                    </Button>
                                </>
                            )}
                        </Form>
                    </Section>

                    <Section title="Positions">
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
                                onToggle: () =>
                                    toggleArchive(
                                        `/manage/positions/${position.id}`,
                                        position,
                                    ),
                            }))}
                        />
                        <Form
                            action="/manage/positions"
                            method="post"
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="space-y-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        label="Title"
                                        name="title"
                                        error={errors.title}
                                    />
                                    <Field
                                        label="Code"
                                        name="code"
                                        error={errors.code}
                                    />
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="position-department">
                                            Department
                                        </Label>
                                        <select
                                            id="position-department"
                                            name="department_id"
                                            className={selectClass}
                                            defaultValue=""
                                        >
                                            <option value="">—</option>
                                            {departments
                                                .filter(
                                                    (department) =>
                                                        department.status ===
                                                        'active',
                                                )
                                                .map((department) => (
                                                    <option
                                                        key={department.id}
                                                        value={department.id}
                                                    >
                                                        {department.name}
                                                    </option>
                                                ))}
                                        </select>
                                        <InputError
                                            message={errors.department_id}
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        Add position
                                    </Button>
                                </>
                            )}
                        </Form>
                    </Section>
                </div>
            </div>
        </>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <Card className="gap-0 rounded-md py-0 shadow-none">
            <CardHeader className="border-b px-4 py-3">
                <CardTitle className="text-sm font-semibold">{title}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 px-4 py-4">
                {children}
            </CardContent>
        </Card>
    );
}

function Field({
    label,
    name,
    error,
}: {
    label: string;
    name: string;
    error?: string;
}) {
    const id = `field-${name}-${label}`.toLowerCase().replace(/\s+/g, '-');

    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id} className="text-xs">
                {label}
            </Label>
            <Input id={id} name={name} className="h-8 text-sm" />
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
        onToggle: () => void;
    }[];
}) {
    if (items.length === 0) {
        return (
            <EmptyState
                className="py-8"
                title="None yet"
                description="Add one using the form below."
            />
        );
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
                    <Button size="sm" variant="ghost" onClick={item.onToggle}>
                        {item.status === 'archived' ? 'Restore' : 'Archive'}
                    </Button>
                </li>
            ))}
        </ul>
    );
}
