import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
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
import {
    AssignmentFields,
    formatDate,
    SelectField,
    TextField,
} from '@/components/fields';
import type { Option, StructureOptions } from '@/components/fields';
import { show, update } from '@/routes/employees';
import { store as storeContract } from '@/routes/employees/contracts';
import { index as documents } from '@/routes/employees/documents';
import { leave } from '@/routes/employees';
import { store as storeMove } from '@/routes/employees/moves';
import { show as personal } from '@/routes/employees/personal';

type Employee = {
    id: string;
    number: string;
    name: string;
    fullName: string;
    firstName: string;
    middleName: string | null;
    lastName: string;
    preferredName: string | null;
    workEmail: string | null;
    workPhone: string | null;
    branchId: string | null;
    branch: string | null;
    departmentId: string | null;
    department: string | null;
    positionId: string | null;
    position: string | null;
    jobTitle: string | null;
    managerId: string | null;
    manager: { id: string; name: string; visible: boolean } | null;
    employmentType: string;
    status: 'active' | 'left';
    hiredOn: string;
    leftOn: string | null;
    leavingReason: string | null;
};

type Record = {
    id: string;
    kind: string;
    kindLabel: string;
    effectiveOn: string;
    branch: string | null;
    department: string | null;
    jobTitle: string | null;
    note: string | null;
    recordedBy: string;
};

type Contract = {
    id: string;
    type: string;
    startsOn: string;
    endsOn: string | null;
    probationEndsOn: string | null;
    noticeDays: number | null;
    note: string | null;
    status: 'current' | 'ended';
    endedOn: string | null;
};

type Props = {
    employee: Employee;
    records: Record[];
    contracts: Contract[];
    can: {
        edit: boolean;
        employment: boolean;
        personal: boolean;
        documents: boolean;
    };
    options:
        | (StructureOptions & {
              managers: { id: string; name: string }[];
              employmentTypes: Option[];
              moveKinds: Option[];
              leavingReasons: Option[];
          })
        | null;
};

type Action = 'edit' | 'move' | 'contract' | 'leave' | null;

function Detail({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5">{children || '—'}</dd>
        </div>
    );
}

function Actions({
    onCancel,
    processing,
    label,
}: {
    onCancel: () => void;
    processing: boolean;
    label: string;
}) {
    return (
        <div className="flex gap-2 sm:col-span-4">
            <Button type="submit" disabled={processing}>
                {label}
            </Button>
            <Button type="button" variant="ghost" onClick={onCancel}>
                Cancel
            </Button>
        </div>
    );
}

export default function ShowEmployee({
    employee,
    records,
    contracts,
    can,
    options,
}: Props) {
    const [action, setAction] = useState<Action>(null);
    const close = () => setAction(null);
    const job = employee.jobTitle ?? employee.position;

    return (
        <>
            <Head title={employee.name} />
            <PageHeader
                title={employee.name}
                description={[employee.number, job, employee.branch]
                    .filter(Boolean)
                    .join(' · ')}
                badges={
                    employee.status === 'left' ? (
                        <StatusBadge>
                            Left {formatDate(employee.leftOn)}
                        </StatusBadge>
                    ) : (
                        <StatusBadge tone="success">
                            {employee.employmentType}
                        </StatusBadge>
                    )
                }
                actions={
                    <>
                        {can.documents && (
                            <Button asChild variant="outline">
                                <Link href={documents(employee.id)}>
                                    Documents
                                </Link>
                            </Button>
                        )}
                        {can.personal && (
                            <Button asChild variant="outline">
                                <Link href={personal(employee.id)}>
                                    Personal details
                                </Link>
                            </Button>
                        )}
                        {can.edit && (
                            <Button
                                variant="outline"
                                onClick={() => setAction('edit')}
                            >
                                Edit
                            </Button>
                        )}
                        {can.employment && (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() => setAction('move')}
                                >
                                    Move or promote
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => setAction('contract')}
                                >
                                    New contract
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => setAction('leave')}
                                >
                                    Leaving
                                </Button>
                            </>
                        )}
                    </>
                }
            />

            {options && action === 'edit' && (
                <Panel className="mb-3">
                    <PanelHeader
                        title="Work details"
                        description="Moves between branches, departments and jobs are recorded with Move or promote."
                    />
                    <PanelBody>
                        <Form
                            {...update.form(employee.id)}
                            onSuccess={close}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <TextField
                                        prefix="edit-"
                                        name="first_name"
                                        label="First name"
                                        required
                                        defaultValue={employee.firstName}
                                        error={errors.first_name}
                                    />
                                    <TextField
                                        prefix="edit-"
                                        name="middle_name"
                                        label="Middle name"
                                        defaultValue={employee.middleName}
                                        error={errors.middle_name}
                                    />
                                    <TextField
                                        prefix="edit-"
                                        name="last_name"
                                        label="Last name"
                                        required
                                        defaultValue={employee.lastName}
                                        error={errors.last_name}
                                    />
                                    <TextField
                                        prefix="edit-"
                                        name="preferred_name"
                                        label="Known as"
                                        defaultValue={employee.preferredName}
                                        error={errors.preferred_name}
                                    />
                                    <TextField
                                        prefix="edit-"
                                        name="work_email"
                                        label="Work email"
                                        type="email"
                                        defaultValue={employee.workEmail}
                                        error={errors.work_email}
                                    />
                                    <TextField
                                        prefix="edit-"
                                        name="work_phone"
                                        label="Work phone"
                                        defaultValue={employee.workPhone}
                                        error={errors.work_phone}
                                    />
                                    <SelectField
                                        prefix="edit-"
                                        name="manager_id"
                                        label="Reports to"
                                        empty="Nobody"
                                        defaultValue={employee.managerId}
                                        options={options.managers.map(
                                            (manager) => ({
                                                value: manager.id,
                                                label: manager.name,
                                            }),
                                        )}
                                        error={errors.manager_id}
                                    />
                                    <Actions
                                        onCancel={close}
                                        processing={processing}
                                        label="Save"
                                    />
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {options && action === 'move' && (
                <Panel className="mb-3">
                    <PanelHeader
                        title="Move or promote"
                        description="Recorded in their history from the date it takes effect."
                    />
                    <PanelBody>
                        <Form
                            {...storeMove.form(employee.id)}
                            onSuccess={close}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <SelectField
                                        prefix="move-"
                                        name="kind"
                                        label="What"
                                        options={options.moveKinds}
                                        error={errors.kind}
                                    />
                                    <TextField
                                        prefix="move-"
                                        name="effective_on"
                                        label="From"
                                        type="date"
                                        required
                                        error={errors.effective_on}
                                    />
                                    <TextField
                                        prefix="move-"
                                        name="note"
                                        label="Note"
                                        error={errors.note}
                                    />
                                    <div className="hidden sm:block" />
                                    <AssignmentFields
                                        prefix="move-"
                                        options={options}
                                        errors={errors}
                                        defaults={{
                                            branchId: employee.branchId,
                                            departmentId: employee.departmentId,
                                            positionId: employee.positionId,
                                            jobTitle: employee.jobTitle,
                                        }}
                                    />
                                    <Actions
                                        onCancel={close}
                                        processing={processing}
                                        label="Record"
                                    />
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {options && action === 'contract' && (
                <Panel className="mb-3">
                    <PanelHeader
                        title="New contract"
                        description="The current contract ends the day before this one starts."
                    />
                    <PanelBody>
                        <Form
                            {...storeContract.form(employee.id)}
                            onSuccess={close}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <SelectField
                                        prefix="contract-"
                                        name="contract_type"
                                        label="Type"
                                        options={options.employmentTypes}
                                        error={errors.contract_type}
                                    />
                                    <TextField
                                        prefix="contract-"
                                        name="starts_on"
                                        label="Starts on"
                                        type="date"
                                        required
                                        error={errors.starts_on}
                                    />
                                    <TextField
                                        prefix="contract-"
                                        name="ends_on"
                                        label="Ends on (fixed term)"
                                        type="date"
                                        error={errors.ends_on}
                                    />
                                    <TextField
                                        prefix="contract-"
                                        name="probation_ends_on"
                                        label="Probation ends"
                                        type="date"
                                        error={errors.probation_ends_on}
                                    />
                                    <TextField
                                        prefix="contract-"
                                        name="notice_days"
                                        label="Notice (days)"
                                        type="number"
                                        error={errors.notice_days}
                                    />
                                    <TextField
                                        prefix="contract-"
                                        name="note"
                                        label="Note"
                                        error={errors.note}
                                    />
                                    <Actions
                                        onCancel={close}
                                        processing={processing}
                                        label="Record contract"
                                    />
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {options && action === 'leave' && (
                <Panel className="mb-3 border-destructive/40">
                    <PanelHeader
                        title="Leaving"
                        description="Their contract ends on their last day; anyone reporting to them is left without a manager. This cannot be undone here."
                    />
                    <PanelBody>
                        <Form
                            {...leave.form(employee.id)}
                            onSuccess={close}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <TextField
                                        prefix="leave-"
                                        name="left_on"
                                        label="Last day"
                                        type="date"
                                        required
                                        error={errors.left_on}
                                    />
                                    <SelectField
                                        prefix="leave-"
                                        name="reason"
                                        label="Reason"
                                        options={options.leavingReasons}
                                        error={errors.reason}
                                    />
                                    <TextField
                                        prefix="leave-"
                                        name="note"
                                        label="Note"
                                        error={errors.note}
                                    />
                                    {errors.employee && (
                                        <p className="text-sm text-destructive sm:col-span-4">
                                            {errors.employee}
                                        </p>
                                    )}
                                    <Actions
                                        onCancel={close}
                                        processing={processing}
                                        label="Record leaving"
                                    />
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <div className="grid gap-3 lg:grid-cols-3">
                <Panel>
                    <PanelHeader title="Work details" />
                    <PanelBody>
                        <dl className="grid grid-cols-2 gap-3 text-sm">
                            <Detail label="Full name">
                                {employee.fullName}
                            </Detail>
                            <Detail label="Number">{employee.number}</Detail>
                            <Detail label="Job">{job}</Detail>
                            <Detail label="Branch">{employee.branch}</Detail>
                            <Detail label="Department">
                                {employee.department}
                            </Detail>
                            <Detail label="Reports to">
                                {employee.manager &&
                                    (employee.manager.visible ? (
                                        <Link
                                            href={show(employee.manager.id)}
                                            className="hover:underline"
                                        >
                                            {employee.manager.name}
                                        </Link>
                                    ) : (
                                        employee.manager.name
                                    ))}
                            </Detail>
                            <Detail label="Work email">
                                {employee.workEmail}
                            </Detail>
                            <Detail label="Work phone">
                                {employee.workPhone}
                            </Detail>
                            <Detail label="Started">
                                {formatDate(employee.hiredOn)}
                            </Detail>
                            {employee.status === 'left' && (
                                <>
                                    <Detail label="Left">
                                        {formatDate(employee.leftOn)}
                                    </Detail>
                                    <Detail label="Reason">
                                        {employee.leavingReason}
                                    </Detail>
                                </>
                            )}
                        </dl>
                    </PanelBody>
                </Panel>

                <Panel className="lg:col-span-2">
                    <PanelHeader title="Employment history" />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>From</TableHead>
                                <TableHead>What</TableHead>
                                <TableHead>Job</TableHead>
                                <TableHead>Branch</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead>Note</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {records.map((record) => (
                                <TableRow key={record.id}>
                                    <TableCell className="tabular-nums">
                                        {formatDate(record.effectiveOn)}
                                    </TableCell>
                                    <TableCell>{record.kindLabel}</TableCell>
                                    <TableCell>
                                        {record.jobTitle ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {record.branch ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {record.department ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {record.note ?? ''}
                                        <span className="block text-xs">
                                            by {record.recordedBy}
                                        </span>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>

                <Panel className="lg:col-span-3">
                    <PanelHeader title="Contracts" />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Type</TableHead>
                                <TableHead>Starts</TableHead>
                                <TableHead>Ends</TableHead>
                                <TableHead>Probation until</TableHead>
                                <TableHead>Notice</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {contracts.map((contract) => (
                                <TableRow key={contract.id}>
                                    <TableCell>
                                        {contract.type}
                                        {contract.note && (
                                            <span className="block text-xs text-muted-foreground">
                                                {contract.note}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {formatDate(contract.startsOn)}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {contract.endsOn
                                            ? formatDate(contract.endsOn)
                                            : 'Open-ended'}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {formatDate(contract.probationEndsOn)}
                                    </TableCell>
                                    <TableCell>
                                        {contract.noticeDays === null
                                            ? '—'
                                            : `${contract.noticeDays} days`}
                                    </TableCell>
                                    <TableCell>
                                        {contract.status === 'current' ? (
                                            <StatusBadge tone="success">
                                                Current
                                            </StatusBadge>
                                        ) : (
                                            <StatusBadge>
                                                Ended{' '}
                                                {formatDate(contract.endedOn)}
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>
            </div>
        </>
    );
}
