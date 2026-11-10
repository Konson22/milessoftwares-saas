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
import { selectClass } from '@/components/fields';
import { formatMoney } from '@/lib/money';
import { create, index, show } from '@/routes/employees';

type Employee = {
    id: string;
    number: string;
    name: string;
    jobTitle: string | null;
    branch: string | null;
    department: string | null;
    payBasis: 'monthly' | 'hourly';
    status: 'active' | 'left';
    source: string | null;
    payMinor: number | null;
};

type Props = {
    employees: Employee[];
    filters: { q: string; status: string };
    truncated: boolean;
    canAdd: boolean;
    seesPay: boolean;
};

export default function Employees({
    employees,
    filters,
    truncated,
    canAdd,
    seesPay,
}: Props) {
    const add = canAdd ? (
        <Button asChild>
            <Link href={create()}>Add person</Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="People" />
            <PageHeader title="People" actions={add} />

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
                            placeholder="Name or number"
                            aria-label="Search people"
                            className="max-w-sm"
                        />
                        <select
                            name="status"
                            defaultValue={filters.status}
                            aria-label="Status"
                            className={`${selectClass} w-auto`}
                        >
                            <option value="active">Paid now</option>
                            <option value="left">Left</option>
                            <option value="all">Everyone</option>
                        </select>
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                    </Form>
                </PanelToolbar>

                {employees.length === 0 ? (
                    <EmptyState icon={Users} title="Nobody here" action={add}>
                        Add people here, or connect HR so its staff arrive on
                        their own.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Number</TableHead>
                                <TableHead>Job</TableHead>
                                <TableHead>Branch</TableHead>
                                <TableHead>Paid</TableHead>
                                {seesPay && (
                                    <TableHead className="text-right">
                                        Basic pay
                                    </TableHead>
                                )}
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
                                        {employee.source && (
                                            <StatusBadge
                                                tone="info"
                                                className="ml-2"
                                            >
                                                From {employee.source}
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
                                        {employee.payBasis === 'hourly'
                                            ? 'By the hour'
                                            : 'Monthly'}
                                    </TableCell>
                                    {seesPay && (
                                        <TableCell className="text-right tabular-nums">
                                            {employee.payMinor === null ? (
                                                <StatusBadge tone="warning">
                                                    Not set
                                                </StatusBadge>
                                            ) : (
                                                formatMoney(employee.payMinor)
                                            )}
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
                {truncated && (
                    <p className="border-t px-3 py-2 text-xs text-muted-foreground">
                        Showing the first {employees.length}: search to find
                        others.
                    </p>
                )}
            </Panel>
        </>
    );
}
