import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
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
import { SelectField, TextField } from '@/components/fields';
import type { Option } from '@/components/fields';
import { LeaveRequestsTable } from '@/components/leave-requests';
import type { LeaveRequestRow } from '@/components/leave-requests';
import { show } from '@/routes/employees';
import { show as balancesFor, update } from '@/routes/employees/balances';
import { store } from '@/routes/leave';

type Balance = {
    typeId: string;
    type: string;
    paid: boolean;
    allowNegative: boolean;
    archived: boolean;
    days: number;
    carried: number;
    taken: number;
    pending: number;
    remaining: number;
    available: number;
};

type Props = {
    employee: { id: string; number: string; name: string; status: string };
    year: number;
    years: number[];
    balances: Balance[];
    requests: LeaveRequestRow[];
    canManage: boolean;
    types: Option[];
};

export default function EmployeeLeave({
    employee,
    year,
    years,
    balances,
    requests,
    canManage,
    types,
}: Props) {
    const [recording, setRecording] = useState(false);
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <>
            <Head title={`${employee.name}: leave`} />
            <PageHeader
                title={`Leave in ${year}`}
                description={
                    <>
                        <Link
                            href={show(employee.id)}
                            className="hover:underline"
                        >
                            {employee.name}
                        </Link>{' '}
                        · {employee.number}
                    </>
                }
                actions={
                    <>
                        {years.length > 1 && (
                            <nav
                                aria-label="Year"
                                className="flex flex-wrap gap-1"
                            >
                                {years.map((option) => (
                                    <Button
                                        key={option}
                                        asChild
                                        size="sm"
                                        variant={
                                            option === year
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                    >
                                        <Link
                                            href={balancesFor(employee.id, {
                                                query: { year: option },
                                            })}
                                            aria-current={
                                                option === year
                                                    ? 'page'
                                                    : undefined
                                            }
                                        >
                                            {option}
                                        </Link>
                                    </Button>
                                ))}
                            </nav>
                        )}
                        {canManage && types.length > 0 && !recording && (
                            <Button onClick={() => setRecording(true)}>
                                Record a request
                            </Button>
                        )}
                    </>
                }
            />

            {recording && (
                <Panel className="mb-3">
                    <PanelHeader
                        title="Record a request"
                        description="It waits for a decision by someone else; the days are held meanwhile."
                    />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            onSuccess={() => setRecording(false)}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="employee_id"
                                        value={employee.id}
                                    />
                                    <SelectField
                                        name="leave_type_id"
                                        label="Kind of leave"
                                        options={types}
                                        error={
                                            errors.leave_type_id ??
                                            errors.employee_id
                                        }
                                    />
                                    <TextField
                                        name="starts_on"
                                        label="First day"
                                        type="date"
                                        error={errors.starts_on}
                                        required
                                    />
                                    <TextField
                                        name="ends_on"
                                        label="Last day"
                                        type="date"
                                        error={errors.ends_on}
                                        required
                                    />
                                    <TextField
                                        name="reason"
                                        label="Reason (optional)"
                                        error={errors.reason}
                                    />
                                    <div className="flex gap-2 sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Record
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setRecording(false)}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel className="mb-3">
                <PanelHeader
                    title="Balances"
                    description="Days for the year plus days carried over, less approved leave. Waiting requests hold days until they are decided."
                />
                {balances.length === 0 ? (
                    <p className="px-3 py-4 text-sm text-muted-foreground">
                        There are no kinds of leave yet.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Kind</TableHead>
                                <TableHead className="text-right">
                                    Days for {year}
                                </TableHead>
                                <TableHead className="text-right">
                                    Carried over
                                </TableHead>
                                <TableHead className="text-right">
                                    Taken
                                </TableHead>
                                <TableHead className="text-right">
                                    Waiting
                                </TableHead>
                                <TableHead className="text-right">
                                    Available
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {balances.map((balance) => (
                                <TableRow key={balance.typeId}>
                                    <TableCell>
                                        {balance.type}
                                        {!balance.paid && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                (unpaid)
                                            </span>
                                        )}
                                        {balance.archived && (
                                            <StatusBadge className="ml-2">
                                                No longer in use
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {editing === balance.typeId ? (
                                            <Form
                                                {...update.form({
                                                    employee: employee.id,
                                                    leaveType: balance.typeId,
                                                })}
                                                onSuccess={() =>
                                                    setEditing(null)
                                                }
                                                className="inline-flex items-center gap-1"
                                            >
                                                {({ errors, processing }) => (
                                                    <>
                                                        <input
                                                            type="hidden"
                                                            name="year"
                                                            value={year}
                                                        />
                                                        <Input
                                                            name="days"
                                                            type="number"
                                                            min={0}
                                                            max={365}
                                                            defaultValue={
                                                                balance.days
                                                            }
                                                            aria-label={`${balance.type} days for ${year}`}
                                                            aria-invalid={
                                                                errors.days !==
                                                                undefined
                                                            }
                                                            className="h-8 w-20"
                                                            required
                                                        />
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Save
                                                        </Button>
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setEditing(null)
                                                            }
                                                        >
                                                            Cancel
                                                        </Button>
                                                    </>
                                                )}
                                            </Form>
                                        ) : (
                                            <>
                                                {balance.days}
                                                {canManage &&
                                                    !balance.archived && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            className="ml-1"
                                                            onClick={() =>
                                                                setEditing(
                                                                    balance.typeId,
                                                                )
                                                            }
                                                        >
                                                            Change
                                                            <span className="sr-only">
                                                                {' '}
                                                                {
                                                                    balance.type
                                                                }{' '}
                                                                days
                                                            </span>
                                                        </Button>
                                                    )}
                                            </>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {balance.carried}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {balance.taken}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {balance.pending}
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {balance.allowNegative ? (
                                            <span className="font-normal text-muted-foreground">
                                                No limit
                                            </span>
                                        ) : (
                                            balance.available
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            <Panel>
                <PanelHeader title={`Requests in ${year}`} />
                {requests.length === 0 ? (
                    <p className="px-3 py-4 text-sm text-muted-foreground">
                        No leave requested for {year}.
                    </p>
                ) : (
                    <LeaveRequestsTable
                        requests={requests}
                        showEmployee={false}
                    />
                )}
            </Panel>
        </>
    );
}
