import { Form, Head } from '@inertiajs/react';
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
    Field,
    formatDate,
    selectClass,
    SelectField,
    TextField,
} from '@/components/payroll/fields';
import type { StructureOptions } from '@/components/payroll/fields';
import { formatMoney, percent } from '@/lib/payroll/money';
import { PAY_BASES } from '@/lib/payroll/pay';
import { leave, update } from '@/routes/payroll/employees';
import { end, store as storeComponent } from '@/routes/payroll/employees/components';
import { store as storePay } from '@/routes/payroll/employees/pay';
import { update as updatePaymentDetails } from '@/routes/payroll/employees/payment-details';

type Props = {
    employee: {
        id: string;
        number: string;
        name: string;
        firstName: string;
        lastName: string;
        workEmail: string | null;
        jobTitle: string | null;
        branchId: string | null;
        branch: string | null;
        departmentId: string | null;
        department: string | null;
        payBasis: 'monthly' | 'hourly';
        status: 'active' | 'left';
        hiredOn: string;
        leftOn: string | null;
        source: string | null;
    };
    pay:
        | {
              id: string;
              effectiveFrom: string;
              amountMinor: number;
              note: string | null;
              by: string;
          }[]
        | null;
    components:
        | {
              id: string;
              name: string;
              kind: 'earning' | 'deduction';
              calculation: 'fixed' | 'percent';
              amountMinor: number | null;
              rateBp: number | null;
              startsOn: string;
              endsOn: string | null;
          }[]
        | null;
    paymentDetails: Record<string, string | null> | null;
    componentOptions: {
        id: string;
        name: string;
        kind: 'earning' | 'deduction';
        calculation: 'fixed' | 'percent';
    }[];
    options: StructureOptions;
    can: { edit: boolean; managePay: boolean };
};

export default function ShowEmployee({
    employee,
    pay,
    components,
    paymentDetails,
    componentOptions,
    options,
    can,
}: Props) {
    const linked = employee.source !== null;

    return (
        <>
            <Head title={employee.name} />
            <PageHeader
                title={`${employee.name} (${employee.number})`}
                badges={
                    <>
                        {employee.status === 'left' && (
                            <StatusBadge>
                                Left {formatDate(employee.leftOn)}
                            </StatusBadge>
                        )}
                        {linked && (
                            <StatusBadge tone="info">
                                Kept in step by {employee.source}
                            </StatusBadge>
                        )}
                    </>
                }
            />

            <div className="grid gap-3">
                <WorkPanel
                    employee={employee}
                    options={options}
                    canEdit={can.edit}
                />
                {pay !== null && (
                    <PayPanel
                        employee={employee}
                        pay={pay}
                        canManage={can.managePay}
                    />
                )}
                {components !== null && (
                    <ComponentsPanel
                        employee={employee}
                        components={components}
                        componentOptions={componentOptions}
                        canManage={can.managePay}
                    />
                )}
                {paymentDetails !== null && (
                    <PaymentPanel
                        employee={employee}
                        details={paymentDetails}
                        canManage={can.managePay}
                    />
                )}
            </div>
        </>
    );
}

function WorkPanel({
    employee,
    options,
    canEdit,
}: {
    employee: Props['employee'];
    options: StructureOptions;
    canEdit: boolean;
}) {
    const linked = employee.source !== null;
    const [editing, setEditing] = useState(false);

    if (!editing) {
        return (
            <Panel>
                <PanelHeader
                    title="Work"
                    actions={
                        canEdit && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setEditing(true)}
                            >
                                Change
                            </Button>
                        )
                    }
                />
                <PanelBody>
                    <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                        <Item label="Job">{employee.jobTitle ?? '—'}</Item>
                        <Item label="Branch">{employee.branch ?? '—'}</Item>
                        <Item label="Department">
                            {employee.department ?? '—'}
                        </Item>
                        <Item label="Work email">
                            {employee.workEmail ?? '—'}
                        </Item>
                        <Item label="Started">
                            {formatDate(employee.hiredOn)}
                        </Item>
                        <Item label="Paid">
                            {employee.payBasis === 'hourly'
                                ? 'By the hour'
                                : 'Monthly salary'}
                        </Item>
                    </dl>
                    {linked && (
                        <p className="mt-3 text-xs text-muted-foreground">
                            Name, job, branch and dates change in{' '}
                            {employee.source}; they arrive here on their own.
                        </p>
                    )}
                </PanelBody>
            </Panel>
        );
    }

    return (
        <Panel>
            <PanelHeader title="Change work details" />
            <PanelBody>
                <Form
                    {...update.form(employee.id)}
                    onSuccess={() => setEditing(false)}
                    className="grid gap-3 sm:grid-cols-3"
                >
                    {({ errors, processing }) => (
                        <>
                            {!linked && (
                                <>
                                    <TextField
                                        name="first_name"
                                        label="First name"
                                        defaultValue={employee.firstName}
                                        required
                                        error={errors.first_name}
                                    />
                                    <TextField
                                        name="last_name"
                                        label="Last name"
                                        defaultValue={employee.lastName}
                                        required
                                        error={errors.last_name}
                                    />
                                    <TextField
                                        name="work_email"
                                        label="Work email"
                                        type="email"
                                        defaultValue={employee.workEmail}
                                        error={errors.work_email}
                                    />
                                    <AssignmentFields
                                        options={options}
                                        errors={errors}
                                        defaults={{
                                            branchId: employee.branchId,
                                            departmentId: employee.departmentId,
                                            jobTitle: employee.jobTitle,
                                        }}
                                    />
                                    <TextField
                                        name="hired_on"
                                        label="Started on"
                                        type="date"
                                        defaultValue={employee.hiredOn}
                                        required
                                        error={errors.hired_on}
                                    />
                                </>
                            )}
                            <SelectField
                                name="pay_basis"
                                label="Paid"
                                options={PAY_BASES}
                                defaultValue={employee.payBasis}
                                error={errors.pay_basis}
                            />
                            <div className="flex items-end gap-2 sm:col-span-3">
                                <Button type="submit" disabled={processing}>
                                    Save
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setEditing(false)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
                {!linked && (
                    <Form
                        {...leave.form(employee.id)}
                        className="mt-4 flex flex-wrap items-end gap-3 border-t pt-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <TextField
                                    name="left_on"
                                    label="Last day paid"
                                    type="date"
                                    required
                                    error={errors.left_on}
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Record leaving
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </PanelBody>
        </Panel>
    );
}

function PayPanel({
    employee,
    pay,
    canManage,
}: {
    employee: Props['employee'];
    pay: NonNullable<Props['pay']>;
    canManage: boolean;
}) {
    const hourly = employee.payBasis === 'hourly';

    return (
        <Panel>
            <PanelHeader title={hourly ? 'Hourly rate' : 'Basic salary'} />
            {pay.length === 0 ? (
                <p className="px-3 py-3 text-sm text-muted-foreground">
                    No pay set: {employee.name} is left out of payroll runs
                    until it is.
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>From</TableHead>
                            <TableHead className="text-right">
                                {hourly ? 'An hour' : 'A month'}
                            </TableHead>
                            <TableHead>Note</TableHead>
                            <TableHead>Set by</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {pay.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell className="tabular-nums">
                                    {formatDate(row.effectiveFrom)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(row.amountMinor)}
                                </TableCell>
                                <TableCell>{row.note ?? '—'}</TableCell>
                                <TableCell>{row.by}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
            {canManage && (
                <PanelBody className="border-t">
                    <Form
                        {...storePay.form(employee.id)}
                        resetOnSuccess
                        className="grid gap-3 sm:grid-cols-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <TextField
                                    name="effective_from"
                                    label="From"
                                    type="date"
                                    required
                                    error={errors.effective_from}
                                />
                                <TextField
                                    name="amount"
                                    label={hourly ? 'Rate an hour' : 'A month'}
                                    type="number"
                                    required
                                    error={errors.amount}
                                />
                                <TextField
                                    name="note"
                                    label="Note"
                                    error={errors.note}
                                />
                                <div className="flex items-end">
                                    <Button type="submit" disabled={processing}>
                                        {pay.length === 0
                                            ? 'Set pay'
                                            : 'Change pay'}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </PanelBody>
            )}
        </Panel>
    );
}

function ComponentsPanel({
    employee,
    components,
    componentOptions,
    canManage,
}: {
    employee: Props['employee'];
    components: NonNullable<Props['components']>;
    componentOptions: Props['componentOptions'];
    canManage: boolean;
}) {
    const [chosen, setChosen] = useState(componentOptions[0]?.id ?? '');
    const percentage =
        componentOptions.find((option) => option.id === chosen)?.calculation ===
        'percent';

    return (
        <Panel>
            <PanelHeader title="Allowances and deductions" />
            {components.length === 0 ? (
                <p className="px-3 py-3 text-sm text-muted-foreground">None.</p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                            <TableHead>From</TableHead>
                            <TableHead>Until</TableHead>
                            {canManage && <TableHead />}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {components.map((component) => (
                            <TableRow key={component.id}>
                                <TableCell>
                                    {component.name}{' '}
                                    <StatusBadge
                                        tone={
                                            component.kind === 'earning'
                                                ? 'success'
                                                : 'neutral'
                                        }
                                    >
                                        {component.kind === 'earning'
                                            ? 'Allowance'
                                            : 'Deduction'}
                                    </StatusBadge>
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {component.calculation === 'percent'
                                        ? `${percent(component.rateBp ?? 0)} of basic`
                                        : formatMoney(component.amountMinor)}
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {formatDate(component.startsOn)}
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {formatDate(component.endsOn)}
                                </TableCell>
                                {canManage && (
                                    <TableCell>
                                        {component.endsOn === null && (
                                            <Form
                                                {...end.form({
                                                    employee: employee.id,
                                                    assigned: component.id,
                                                })}
                                                className="flex items-center justify-end gap-2"
                                            >
                                                <input
                                                    type="date"
                                                    name="ends_on"
                                                    required
                                                    aria-label={`Last month of ${component.name}`}
                                                    className={`${selectClass} w-auto`}
                                                />
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    End
                                                </Button>
                                            </Form>
                                        )}
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
            {canManage && componentOptions.length > 0 && (
                <PanelBody className="border-t">
                    <Form
                        {...storeComponent.form(employee.id)}
                        resetOnSuccess
                        className="grid gap-3 sm:grid-cols-5"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="pay_component_id"
                                    label="Add"
                                    error={errors.pay_component_id}
                                >
                                    <select
                                        id="pay_component_id"
                                        name="pay_component_id"
                                        value={chosen}
                                        onChange={(event) =>
                                            setChosen(event.target.value)
                                        }
                                        className={selectClass}
                                    >
                                        {componentOptions.map((option) => (
                                            <option
                                                key={option.id}
                                                value={option.id}
                                            >
                                                {option.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                {percentage ? (
                                    <TextField
                                        name="rate"
                                        label="% of basic"
                                        type="number"
                                        required
                                        error={errors.rate}
                                    />
                                ) : (
                                    <TextField
                                        name="amount"
                                        label="Amount a month"
                                        type="number"
                                        required
                                        error={errors.amount}
                                    />
                                )}
                                <TextField
                                    name="starts_on"
                                    label="From"
                                    type="date"
                                    required
                                    error={errors.starts_on}
                                />
                                <TextField
                                    name="ends_on"
                                    label="Until (optional)"
                                    type="date"
                                    error={errors.ends_on}
                                />
                                <div className="flex items-end">
                                    <Button type="submit" disabled={processing}>
                                        Add
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </PanelBody>
            )}
        </Panel>
    );
}

const METHODS = [
    { value: 'bank', label: 'Bank transfer' },
    { value: 'mobile_money', label: 'Mobile money' },
    { value: 'cash', label: 'Cash' },
];

function PaymentPanel({
    employee,
    details,
    canManage,
}: {
    employee: Props['employee'];
    details: Record<string, string | null>;
    canManage: boolean;
}) {
    const [method, setMethod] = useState(details.method ?? 'bank');

    return (
        <Panel>
            <PanelHeader title="Paid into" />
            <PanelBody>
                {canManage ? (
                    <Form
                        {...updatePaymentDetails.form(employee.id)}
                        className="grid gap-3 sm:grid-cols-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="method"
                                    label="How"
                                    error={errors.method}
                                >
                                    <select
                                        id="method"
                                        name="method"
                                        value={method}
                                        onChange={(event) =>
                                            setMethod(event.target.value)
                                        }
                                        className={selectClass}
                                    >
                                        {METHODS.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                {method === 'bank' && (
                                    <>
                                        <TextField
                                            name="bank_name"
                                            label="Bank"
                                            defaultValue={details.bank_name}
                                            error={errors.bank_name}
                                        />
                                        <TextField
                                            name="account_name"
                                            label="Account name"
                                            defaultValue={details.account_name}
                                            error={errors.account_name}
                                        />
                                        <TextField
                                            name="account_number"
                                            label="Account number"
                                            defaultValue={
                                                details.account_number
                                            }
                                            error={errors.account_number}
                                        />
                                    </>
                                )}
                                {method === 'mobile_money' && (
                                    <TextField
                                        name="mobile_number"
                                        label="Mobile number"
                                        defaultValue={details.mobile_number}
                                        error={errors.mobile_number}
                                    />
                                )}
                                <div className="flex items-end sm:col-span-4">
                                    <Button type="submit" disabled={processing}>
                                        Save
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                ) : (
                    <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                        <Item label="How">
                            {METHODS.find((m) => m.value === details.method)
                                ?.label ?? 'Not set'}
                        </Item>
                        {details.bank_name && (
                            <Item label="Bank">{details.bank_name}</Item>
                        )}
                        {details.account_number && (
                            <Item label="Account">
                                {details.account_number}
                            </Item>
                        )}
                        {details.mobile_number && (
                            <Item label="Mobile">{details.mobile_number}</Item>
                        )}
                    </dl>
                )}
            </PanelBody>
        </Panel>
    );
}

function Item({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}
