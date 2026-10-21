import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/shared/components/input-error';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';
import RolePicker from '@/sites/central/components/role-picker';
import type { PickerRole } from '@/sites/central/components/role-picker';

type Assignment = {
    id: string;
    role: string;
    application: string;
    scope: string;
    expiresAt: string | null;
};

type Member = {
    id: string;
    name: string;
    email: string;
    isOwner: boolean;
    assignments: Assignment[];
};

type Role = PickerRole & {
    sensitivity: number;
    delegable: boolean;
};

type Option = { id: string; name: string };

type Props = {
    members: Member[];
    roles: Role[];
    branches: Option[];
    departments: Option[];
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm';

export default function Access({
    members,
    roles,
    branches,
    departments,
}: Props) {
    // Remounts the role picker after each assignment, clearing its choices.
    const [round, setRound] = useState(0);

    return (
        <>
            <Head title="Access" />

            <h1 className="text-2xl font-semibold">Access</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                Roles decide what each member can do in each application, and
                where. A member can hold several roles. You can only hand out
                roles at scopes you manage, up to your own level in that
                application.
            </p>

            <div className="mt-5 grid gap-5 lg:grid-cols-[1fr_22rem]">
                <ul className="space-y-4">
                    {members.map((member) => (
                        <li key={member.id} className="rounded-lg border p-4">
                            <div className="flex items-center gap-2">
                                <p className="font-medium">{member.name}</p>
                                {member.isOwner && <Badge>Owner</Badge>}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {member.email}
                            </p>

                            {member.assignments.length > 0 ? (
                                <ul className="mt-3 divide-y text-sm">
                                    {member.assignments.map((assignment) => (
                                        <li
                                            key={assignment.id}
                                            className="flex items-center gap-2 py-2"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <span className="font-medium">
                                                    {assignment.role}
                                                </span>{' '}
                                                <span className="text-muted-foreground">
                                                    in {assignment.application}{' '}
                                                    · {assignment.scope}
                                                    {assignment.expiresAt &&
                                                        ` · until ${assignment.expiresAt}`}
                                                </span>
                                            </div>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.delete(
                                                        `/manage/access/${assignment.id}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Remove
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="mt-3 text-sm text-muted-foreground">
                                    No roles.
                                </p>
                            )}
                        </li>
                    ))}
                </ul>

                <section className="space-y-4">
                    <h2 className="text-lg font-medium">Assign roles</h2>
                    <Form
                        action="/manage/access"
                        method="post"
                        resetOnSuccess
                        onSuccess={() => setRound((current) => current + 1)}
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="membership_id">
                                        Member
                                    </Label>
                                    <select
                                        id="membership_id"
                                        name="membership_id"
                                        className={selectClass}
                                        defaultValue=""
                                        required
                                    >
                                        <option value="" disabled>
                                            Choose a member
                                        </option>
                                        {members.map((member) => (
                                            <option
                                                key={member.id}
                                                value={member.id}
                                            >
                                                {member.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.membership_id}
                                    />
                                </div>

                                <RolePicker
                                    key={round}
                                    roles={roles}
                                    branches={branches}
                                    departments={departments}
                                    errors={errors}
                                />

                                <div className="grid gap-1.5">
                                    <Label htmlFor="expires_at">
                                        Ends on (optional)
                                    </Label>
                                    <Input
                                        id="expires_at"
                                        name="expires_at"
                                        type="date"
                                    />
                                    <InputError message={errors.expires_at} />
                                </div>

                                {errors.acknowledge_sod && (
                                    <div className="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-500/40 dark:bg-amber-500/10">
                                        <p className="font-medium">
                                            Separation of duties
                                        </p>
                                        <p>{errors.acknowledge_sod}</p>
                                        <label className="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                name="acknowledge_sod"
                                                value="1"
                                            />
                                            Assign anyway
                                        </label>
                                    </div>
                                )}

                                <Button type="submit" disabled={processing}>
                                    Assign roles
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}
