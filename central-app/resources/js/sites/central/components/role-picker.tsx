import { X } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/shared/components/input-error';
import { Label } from '@/shared/components/ui/label';

export type PickerRole = {
    id: string;
    name: string;
    applicationId: string;
    application: string;
    scopes: string[];
    /** False for roles only the owner can hand out. */
    delegable?: boolean;
};

type Option = { id: string; name: string };

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm';

const scopeLabels: Record<string, string> = {
    organization: 'Whole organization',
    branch: 'One branch',
    department: 'One department',
};

/**
 * Any number of roles, from any of the applications: pick an application,
 * tick its roles, pick another for more. Every chosen role is given at the
 * one place chosen under "Where", so only places they all allow are offered.
 * Submits role_ids[], scope_type, branch_id and department_id.
 */
export default function RolePicker({
    roles,
    branches,
    departments,
    optional = false,
    errors = {},
}: {
    roles: PickerRole[];
    branches: Option[];
    departments: Option[];
    optional?: boolean;
    errors?: Partial<
        Record<'role_ids' | 'role_id' | 'branch_id' | 'department_id', string>
    >;
}) {
    const [applicationId, setApplicationId] = useState('');
    const [selected, setSelected] = useState<string[]>([]);
    const [scopeType, setScopeType] = useState('organization');

    const applications = [
        ...new Map(roles.map((role) => [role.applicationId, role.application])),
    ];
    const applicationRoles = roles.filter(
        (role) => role.applicationId === applicationId,
    );
    const chosen = roles.filter((role) => selected.includes(role.id));
    const scopes = Object.keys(scopeLabels).filter((scope) =>
        chosen.every((role) => role.scopes.includes(scope)),
    );
    const effectiveScope = scopes.includes(scopeType) ? scopeType : scopes[0];

    const toggle = (id: string) =>
        setSelected((current) =>
            current.includes(id)
                ? current.filter((candidate) => candidate !== id)
                : [...current, id],
        );

    return (
        <>
            <div className="grid gap-1.5">
                <Label htmlFor="application_id">
                    {optional ? 'Application (optional)' : 'Application'}
                </Label>
                <select
                    id="application_id"
                    className={selectClass}
                    value={applicationId}
                    onChange={(event) => setApplicationId(event.target.value)}
                >
                    <option value="">
                        {optional
                            ? 'No application yet'
                            : 'Choose an application'}
                    </option>
                    {applications.map(([id, name]) => (
                        <option key={id} value={id}>
                            {name}
                        </option>
                    ))}
                </select>
            </div>

            {applicationId !== '' && (
                <fieldset className="grid gap-1.5">
                    <legend className="mb-1.5 text-sm font-medium">
                        Roles
                    </legend>
                    {applicationRoles.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            This application has no roles to give.
                        </p>
                    )}
                    <div className="grid gap-1 rounded-md border p-2">
                        {applicationRoles.map((role) => (
                            <label
                                key={role.id}
                                className="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-sm hover:bg-muted"
                            >
                                <input
                                    type="checkbox"
                                    checked={selected.includes(role.id)}
                                    onChange={() => toggle(role.id)}
                                    className="accent-primary"
                                />
                                <span>
                                    {role.name}
                                    {role.delegable === false && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            (owner only)
                                        </span>
                                    )}
                                </span>
                            </label>
                        ))}
                    </div>
                </fieldset>
            )}

            {chosen.length > 0 && (
                <div className="grid gap-1.5">
                    <p className="text-sm font-medium">
                        {chosen.length === 1
                            ? '1 role chosen'
                            : `${chosen.length} roles chosen`}
                    </p>
                    <ul className="flex flex-wrap gap-1.5">
                        {chosen.map((role) => (
                            <li
                                key={role.id}
                                className="inline-flex items-center gap-1 rounded-full border bg-muted/50 py-0.5 pr-1 pl-2.5 text-xs"
                            >
                                <input
                                    type="hidden"
                                    name="role_ids[]"
                                    value={role.id}
                                />
                                {role.name}
                                <span className="text-muted-foreground">
                                    · {role.application}
                                </span>
                                <button
                                    type="button"
                                    onClick={() => toggle(role.id)}
                                    aria-label={`Remove ${role.name} (${role.application})`}
                                    className="grid size-5 place-items-center rounded-full hover:bg-muted"
                                >
                                    <X className="size-3" aria-hidden />
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            <InputError message={errors.role_ids ?? errors.role_id} />

            {chosen.length > 0 && effectiveScope === undefined && (
                <p className="text-sm text-destructive">
                    These roles cannot be given in the same place. Give them
                    separately.
                </p>
            )}

            {chosen.length > 0 && effectiveScope !== undefined && (
                <div className="grid gap-1.5">
                    <Label htmlFor="scope_type">Where</Label>
                    <select
                        id="scope_type"
                        name="scope_type"
                        className={selectClass}
                        value={effectiveScope}
                        onChange={(event) => setScopeType(event.target.value)}
                    >
                        {scopes.map((scope) => (
                            <option key={scope} value={scope}>
                                {scopeLabels[scope]}
                            </option>
                        ))}
                    </select>
                </div>
            )}

            {chosen.length > 0 && effectiveScope === 'branch' && (
                <div className="grid gap-1.5">
                    <Label htmlFor="branch_id">Branch</Label>
                    <select
                        id="branch_id"
                        name="branch_id"
                        className={selectClass}
                        required
                        defaultValue=""
                    >
                        <option value="" disabled>
                            Choose a branch
                        </option>
                        {branches.map((branch) => (
                            <option key={branch.id} value={branch.id}>
                                {branch.name}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.branch_id} />
                </div>
            )}

            {chosen.length > 0 && effectiveScope === 'department' && (
                <div className="grid gap-1.5">
                    <Label htmlFor="department_id">Department</Label>
                    <select
                        id="department_id"
                        name="department_id"
                        className={selectClass}
                        required
                        defaultValue=""
                    >
                        <option value="" disabled>
                            Choose a department
                        </option>
                        {departments.map((department) => (
                            <option key={department.id} value={department.id}>
                                {department.name}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.department_id} />
                </div>
            )}
        </>
    );
}
