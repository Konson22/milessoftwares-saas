import { Form, Head, Link } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatDate } from '@/components/fields';
import { create, index, show } from '@/routes/employees';

type Employee = {
    id: string;
    number: string;
    name: string;
    jobTitle: string | null;
    branch: string | null;
    department: string | null;
    employmentType: string;
    status: 'active' | 'left';
    hiredOn: string;
};

type Props = {
    employees: Employee[];
    filters: {
        q: string;
        status: string;
        branch: string | null;
        department: string | null;
    };
    branches: { id: string; name: string }[];
    departments: { id: string; name: string }[];
    truncated: boolean;
    canHire: boolean;
};

const filterClass = 'h-9 rounded-md border bg-transparent px-2 text-sm';

export default function Employees({
    employees,
    filters,
    branches,
    departments,
    truncated,
    canHire,
}: Props) {
    const hire = canHire ? (
        <Button asChild>
            <Link href={create()}>Add employee</Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Staff" />
            <PageHeader title="Staff" actions={hire} />

            <Panel>
                <PanelToolbar>
                    <Form
                        {...index.form()}
                        options={{ preserveState: true }}
                        className="flex flex-wrap items-center gap-3"
                    >
                        <SearchField
                            name="q"
                            defaultValue={filters.q}
                            placeholder="Name, number or work email"
                            aria-label="Search staff"
                            className="max-w-sm"
                        />
                        <select
                            name="status"
                            defaultValue={filters.status}
                            aria-label="Status"
                            className={filterClass}
                        >
                            <option value="active">Current staff</option>
                            <option value="left">Former staff</option>
                            <option value="all">Everyone</option>
                        </select>
                        {branches.length > 0 && (
                            <select
                                name="branch"
                                defaultValue={filters.branch ?? ''}
                                aria-label="Branch"
                                className={filterClass}
                            >
                                <option value="">All branches</option>
                                {branches.map((branch) => (
                                    <option key={branch.id} value={branch.id}>
                                        {branch.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        {departments.length > 0 && (
                            <select
                                name="department"
                                defaultValue={filters.department ?? ''}
                                aria-label="Department"
                                className={filterClass}
                            >
                                <option value="">All departments</option>
                                {departments.map((department) => (
                                    <option
                                        key={department.id}
                                        value={department.id}
                                    >
                                        {department.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                    </Form>
                </PanelToolbar>

                {employees.length === 0 ? (
                    <EmptyState icon={Users} title="Nobody here" action={hire}>
                        Nobody matches, or nobody you can see works here yet.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Number</TableHead>
                                <TableHead>Job</TableHead>
                                <TableHead>Branch</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead>Contract</TableHead>
                                <TableHead>Since</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {employees.map((employee) => (
                                <TableRow key={employee.id}>
                                    <TableCell>
                                        <Link
                                            href={show(employee.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {employee.name}
                                        </Link>
                                        {employee.status === 'left' && (
                                            <StatusBadge className="ml-2">
                                                Left
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground tabular-nums">
                                        {employee.number}
                                    </TableCell>
                                    <TableCell>
                                        {employee.jobTitle ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {employee.branch ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {employee.department ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {employee.employmentType}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {formatDate(employee.hiredOn)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
                {truncated && (
                    <p className="border-t px-3 py-2 text-xs text-muted-foreground">
                        Showing the first {employees.length}: search or filter
                        to find others.
                    </p>
                )}
            </Panel>
        </>
    );
}
