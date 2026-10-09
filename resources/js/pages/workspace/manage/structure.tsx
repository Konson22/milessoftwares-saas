import { Form, Head, router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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

    return (
        <>
            <Head title="Structure" />

            <h1 className="text-2xl font-semibold">Organization structure</h1>

            <div className="mt-8 grid gap-10 lg:grid-cols-3">
                <Section title="Branches">
                    <List
                        items={branches.map((branch) => ({
                            id: branch.id,
                            title: branch.name,
                            detail: [branch.code, branch.city]
                                .filter(Boolean)
                                .join(' · '),
                            status: branch.status,
                            onToggle: () =>
                                toggleArchive(
                                    `/manage/branches/${branch.id}`,
                                    branch,
                                ),
                        }))}
                    />
                    <Form
                        action="/manage/branches"
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
                                <Field
                                    label="City"
                                    name="city"
                                    error={errors.city}
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Add branch
                                </Button>
                            </>
                        )}
                    </Form>
                </Section>

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
                                        <option value="">All branches</option>
                                        {branches
                                            .filter(
                                                (branch) =>
                                                    branch.status === 'active',
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
                                    <InputError message={errors.branch_id} />
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
                                        message={errors.parent_department_id}
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
        </>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-4">
            <h2 className="text-lg font-medium">{title}</h2>
            {children}
        </section>
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
            <Label htmlFor={id}>{label}</Label>
            <Input id={id} name={name} />
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
        return <p className="text-sm text-muted-foreground">None yet.</p>;
    }

    return (
        <ul className="divide-y rounded-lg border">
            {items.map((item) => (
                <li key={item.id} className="flex items-center gap-2 p-3">
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
