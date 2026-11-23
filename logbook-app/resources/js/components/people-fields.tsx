import { BookOpen, Check } from 'lucide-react';
import { SelectField } from '@/components/fields';

/** Shared by the People page and the invitation page. */

export type Role = {
    key: string;
    name: string;
    description: string;
    delegable: boolean;
    assignable: boolean;
    /** The logbook membership this role makes useful; null for managers, who reach logbooks anyway. */
    membership?: 'contributor' | 'reviewer' | 'viewer' | null;
};

export type LogbookOption = {
    id: string;
    name: string;
    departmentId: string | null;
};

export const MEMBERSHIP_LABELS: Record<string, string> = {
    contributor: 'writes entries',
    reviewer: 'reviews entries',
    viewer: 'reads entries',
};

export type Department = { id: string; name: string };

export type Scope = {
    departments: Department[];
    /** The manager's own role is limited to departments: no whole-workspace option. */
    mustChoose: boolean;
};

export function firstDepartment(scope: Scope): string {
    return scope.mustChoose ? (scope.departments[0]?.id ?? '') : '';
}

/**
 * Where the role applies: the whole workspace, or one department, whose
 * logbooks are then all the person reaches. Hidden when the workspace has
 * no departments and the manager may give workspace-wide roles.
 */
export function DepartmentField({
    scope,
    value,
    onChange,
    error,
    className,
}: {
    scope: Scope;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    className?: string;
}) {
    if (scope.departments.length === 0 && !scope.mustChoose) {
        return null;
    }

    return (
        <SelectField
            label="Department"
            value={value}
            onChange={onChange}
            empty={scope.mustChoose ? undefined : 'Whole workspace'}
            options={scope.departments.map((d) => ({
                value: d.id,
                label: d.name,
            }))}
            error={error}
            hint="They see only the logbooks of this department."
            className={className}
        />
    );
}

/**
 * The logbooks the person will work on: those of the chosen department
 * (all of them for the whole workspace). A person reaches a logbook only
 * as its member. Not shown for manager roles, which reach them anyway.
 */
export function LogbookPicker({
    logbooks,
    department,
    role,
    value,
    onChange,
    error,
}: {
    logbooks: LogbookOption[];
    department: string;
    role: Role | undefined;
    value: string[];
    onChange: (value: string[]) => void;
    error?: string;
}) {
    if (!role || !role.membership) {
        return role ? (
            <p className="rounded-lg bg-[#f4f6f8] px-3 py-2.5 text-sm text-muted-foreground">
                {role.name}s reach every logbook of their department without
                being added to them.
            </p>
        ) : null;
    }
    const shown = logbooks.filter(
        (l) => department === '' || l.departmentId === department,
    );
    const chosen = new Set(value);
    const toggle = (id: string) =>
        onChange(
            chosen.has(id) ? value.filter((v) => v !== id) : [...value, id],
        );

    return (
        <fieldset className="grid gap-1.5">
            <legend className="mb-1.5 flex w-full items-baseline justify-between gap-2 text-sm font-medium">
                <span>Logbooks</span>
                {shown.length > 1 && (
                    <button
                        type="button"
                        className="text-xs font-medium text-primary hover:underline"
                        onClick={() =>
                            onChange(
                                chosen.size === shown.length
                                    ? []
                                    : shown.map((l) => l.id),
                            )
                        }
                    >
                        {chosen.size === shown.length
                            ? 'Clear all'
                            : 'Choose all'}
                    </button>
                )}
            </legend>
            {shown.length === 0 ? (
                <p className="rounded-lg border border-dashed px-3 py-3 text-sm text-muted-foreground">
                    No logbooks here yet. You can add them to logbooks later,
                    from People.
                </p>
            ) : (
                <div className="grid max-h-60 gap-1.5 overflow-y-auto rounded-lg border p-1.5 sm:grid-cols-2">
                    {shown.map((l) => {
                        const on = chosen.has(l.id);

                        return (
                            <label
                                key={l.id}
                                className={`flex cursor-pointer items-center gap-2.5 rounded-md px-2.5 py-2 text-sm has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary ${
                                    on ? 'bg-[#eef8f5]' : 'hover:bg-[#f5f5f5]'
                                }`}
                            >
                                <input
                                    type="checkbox"
                                    checked={on}
                                    onChange={() => toggle(l.id)}
                                    className="sr-only"
                                />
                                <span
                                    className={`grid size-4 shrink-0 place-items-center rounded border ${
                                        on
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-[#b5b5b5] bg-card'
                                    }`}
                                >
                                    {on && (
                                        <Check
                                            className="size-3"
                                            strokeWidth={3}
                                        />
                                    )}
                                </span>
                                <BookOpen className="size-4 shrink-0 text-muted-foreground" />
                                <span className="truncate">{l.name}</span>
                            </label>
                        );
                    })}
                </div>
            )}
            <p className="text-xs text-muted-foreground">
                They reach only the logbooks they are added to, and{' '}
                {MEMBERSHIP_LABELS[role.membership]} there.
            </p>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </fieldset>
    );
}
