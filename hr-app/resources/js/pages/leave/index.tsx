import { Form, Head, Link } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatDate, SelectField, TextField } from '@/components/fields';
import type { Option } from '@/components/fields';
import { LeaveRequestsTable } from '@/components/leave-requests';
import type { LeaveRequestRow } from '@/components/leave-requests';
import { useCan } from '@/lib/access';
import { show as balances } from '@/routes/employees/balances';
import { index, settings, store } from '@/routes/leave';

type Props = {
    requests: LeaveRequestRow[];
    away: {
        id: string;
        employeeId: string;
        name: string;
        type: string;
        endsOn: string;
    }[];
    filters: { status: string };
    statuses: Option[];
    truncated: boolean;
    canRecord: boolean;
    /** Null without hr.leave.manage. */
    options: { employees: Option[]; types: Option[] } | null;
};

export default function Leave({
    requests,
    away,
    filters,
    statuses,
    truncated,
    canRecord,
    options,
}: Props) {
    const can = useCan();
    const [recording, setRecording] = useState(false);
    const tabs = [...statuses, { value: 'all', label: 'All' }];

    return (
        <>
            <Head title="Leave" />
            <PageHeader
                title="Leave"
                description="Requests wait for a decision by someone other than whoever recorded them. Days are working days, without weekends and public holidays."
                actions={
                    <>
                        {can('hr.leave.settings') && (
                            <Button asChild variant="outline">
                                <Link href={settings()}>Leave settings</Link>
                            </Button>
                        )}
                        {canRecord && !recording && (
                            <Button onClick={() => setRecording(true)}>
                                Record a request
                            </Button>
                        )}
                    </>
                }
            />

            {recording && options !== null && (
                <Panel className="mb-3">
                    <PanelHeader
                        title="Record a request"
                        description="For someone on the staff list. It waits for a decision; the days are held meanwhile."
                    />
                    <PanelBody>
                        {options.types.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                There are no kinds of leave yet. Add them in
                                Leave settings first.
                            </p>
                        ) : (
                            <Form
                                {...store.form()}
                                onSuccess={() => setRecording(false)}
                                className="grid gap-3 sm:grid-cols-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="sm:col-span-2">
                                            <SelectField
                                                name="employee_id"
                                                label="Who"
                                                options={options.employees}
                                                error={errors.employee_id}
                                                empty="Choose someone"
                                            />
                                        </div>
                                        <div className="sm:col-span-2">
                                            <SelectField
                                                name="leave_type_id"
                                                label="Kind of leave"
                                                options={options.types}
                                                error={errors.leave_type_id}
                                            />
                                        </div>
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
                                        <div className="sm:col-span-2">
                                            <TextField
                                                name="reason"
                                                label="Reason (optional)"
                                                error={errors.reason}
                                            />
                                        </div>
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
                                                onClick={() =>
                                                    setRecording(false)
                                                }
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}
                    </PanelBody>
                </Panel>
            )}

            <Panel className="mb-3">
                <PanelHeader title="Away today" />
                {away.length === 0 ? (
                    <p className="px-3 py-4 text-sm text-muted-foreground">
                        Nobody is on leave today.
                    </p>
                ) : (
                    <Table>
                        <TableBody>
                            {away.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        <Link
                                            href={balances(row.employeeId)}
                                            className="font-medium hover:underline"
                                        >
                                            {row.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{row.type}</TableCell>
                                    <TableCell className="tabular-nums">
                                        Back after {formatDate(row.endsOn)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            <Panel>
                <PanelToolbar>
                    <nav
                        aria-label="Requests by status"
                        className="flex flex-wrap gap-1"
                    >
                        {tabs.map((tab) => (
                            <Button
                                key={tab.value}
                                asChild
                                size="sm"
                                variant={
                                    filters.status === tab.value
                                        ? 'secondary'
                                        : 'ghost'
                                }
                            >
                                <Link
                                    href={index({
                                        query: { status: tab.value },
                                    })}
                                    preserveState
                                    aria-current={
                                        filters.status === tab.value
                                            ? 'page'
                                            : undefined
                                    }
                                >
                                    {tab.label}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </PanelToolbar>
                {requests.length === 0 ? (
                    <EmptyState
                        icon={CalendarDays}
                        title={
                            filters.status === 'pending'
                                ? 'Nothing waits for a decision'
                                : 'No requests here'
                        }
                    />
                ) : (
                    <LeaveRequestsTable requests={requests} />
                )}
                {truncated && (
                    <p className="px-3 py-2 text-xs text-muted-foreground">
                        Showing the first {requests.length}.
                    </p>
                )}
            </Panel>
        </>
    );
}
