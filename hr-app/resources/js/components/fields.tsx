import { useState } from 'react';
import type { ReactNode } from 'react';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';

export const selectClass =
    'h-9 w-full rounded-md border bg-transparent px-3 text-sm';

export type Option = { value: string; label: string };

export type StructureOptions = {
    branches: { id: string; name: string }[];
    departments: { id: string; name: string; branchId: string | null }[];
    positions: { id: string; title: string; departmentId: string | null }[];
};

export function Field({
    id,
    label,
    error,
    hint,
    className,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    hint?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={`grid content-start gap-1.5 ${className ?? ''}`}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}

export function TextField({
    name,
    label,
    error,
    defaultValue,
    type = 'text',
    required = false,
    prefix = '',
}: {
    name: string;
    label: string;
    error?: string;
    defaultValue?: string | number | null;
    type?: string;
    required?: boolean;
    prefix?: string;
}) {
    const id = `${prefix}${name}`;

    return (
        <Field id={id} label={label} error={error}>
            <Input
                id={id}
                name={name}
                type={type}
                defaultValue={defaultValue ?? ''}
                required={required}
            />
        </Field>
    );
}

export function SelectField({
    name,
    label,
    options,
    error,
    defaultValue,
    empty,
    prefix = '',
}: {
    name: string;
    label: string;
    options: Option[];
    error?: string;
    defaultValue?: string | null;
    /** Label of the "nothing chosen" choice; leave out to require one. */
    empty?: string;
    prefix?: string;
}) {
    const id = `${prefix}${name}`;

    return (
        <Field id={id} label={label} error={error}>
            <select
                id={id}
                name={name}
                defaultValue={defaultValue ?? ''}
                className={selectClass}
            >
                {empty !== undefined && <option value="">{empty}</option>}
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </Field>
    );
}

/**
 * Branch, department and position. Departments of another branch are not
 * offered (company-wide ones always are); positions follow the department.
 */
export function AssignmentFields({
    options,
    errors,
    defaults,
    prefix = '',
}: {
    options: StructureOptions;
    errors: Record<string, string | undefined>;
    defaults?: {
        branchId: string | null;
        departmentId: string | null;
        positionId: string | null;
        jobTitle: string | null;
    };
    prefix?: string;
}) {
    const [branchId, setBranchId] = useState(defaults?.branchId ?? '');
    const [departmentId, setDepartmentId] = useState(
        defaults?.departmentId ?? '',
    );

    const departments = options.departments.filter(
        (department) =>
            department.branchId === null || department.branchId === branchId,
    );
    const positions = options.positions.filter(
        (position) =>
            position.departmentId === null ||
            position.departmentId === departmentId,
    );

    return (
        <>
            <Field
                id={`${prefix}branch_id`}
                label="Branch"
                error={errors.branch_id}
            >
                <select
                    id={`${prefix}branch_id`}
                    name="branch_id"
                    value={branchId}
                    onChange={(event) => {
                        setBranchId(event.target.value);
                        setDepartmentId('');
                    }}
                    className={selectClass}
                >
                    <option value="">No branch (organization-wide)</option>
                    {options.branches.map((branch) => (
                        <option key={branch.id} value={branch.id}>
                            {branch.name}
                        </option>
                    ))}
                </select>
            </Field>
            <Field
                id={`${prefix}department_id`}
                label="Department"
                error={errors.department_id}
            >
                <select
                    id={`${prefix}department_id`}
                    name="department_id"
                    value={departmentId}
                    onChange={(event) => setDepartmentId(event.target.value)}
                    className={selectClass}
                >
                    <option value="">No department</option>
                    {departments.map((department) => (
                        <option key={department.id} value={department.id}>
                            {department.name}
                        </option>
                    ))}
                </select>
            </Field>
            <Field
                id={`${prefix}position_id`}
                label="Position"
                error={errors.position_id}
            >
                <select
                    id={`${prefix}position_id`}
                    name="position_id"
                    defaultValue={defaults?.positionId ?? ''}
                    key={departmentId}
                    className={selectClass}
                >
                    <option value="">No position</option>
                    {positions.map((position) => (
                        <option key={position.id} value={position.id}>
                            {position.title}
                        </option>
                    ))}
                </select>
            </Field>
            <TextField
                name="job_title"
                label="Job title (if not the position's)"
                defaultValue={defaults?.jobTitle}
                error={errors.job_title}
                prefix={prefix}
            />
        </>
    );
}

export function formatDate(date: string | null): string {
    if (date === null) {
        return '—';
    }

    return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
