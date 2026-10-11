import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/shared/components/input-error';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';

type Invitation = {
    id: string;
    email: string;
    status: 'pending' | 'accepted' | 'revoked' | 'expired';
    invitedBy: string;
    expiresAt: string;
    hasProposedRole: boolean;
};

type Role = { id: string; name: string; application: string; scopes: string[] };

type Props = {
    invitations: Invitation[];
    roles: Role[];
    branches: { id: string; name: string }[];
    departments: { id: string; name: string }[];
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';
const scopeLabels: Record<string, string> = {
    organization: 'Whole organization',
    branch: 'One branch',
    department: 'One department',
};

export default function Invitations({
    invitations,
    roles,
    branches,
    departments,
}: Props) {
    const [roleId, setRoleId] = useState('');
    const [scopeType, setScopeType] = useState('organization');
    const role = roles.find((candidate) => candidate.id === roleId);
    const scopes = (role?.scopes ?? []).filter((scope) => scope in scopeLabels);
    const effectiveScope = scopes.includes(scopeType) ? scopeType : scopes[0];

    return (
        <>
            <Head title="Invitations" />

            <h1 className="text-2xl font-semibold">Invitations</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                Each link works once and expires after 7 days. A proposed role
                is checked again when the person accepts.
            </p>

            <div className="mt-8 grid gap-10 lg:grid-cols-[1fr_22rem]">
                <ul className="divide-y rounded-lg border text-sm">
                    {invitations.length === 0 && (
                        <li className="p-4 text-muted-foreground">
                            No invitations yet.
                        </li>
                    )}
                    {invitations.map((invitation) => (
                        <li
                            key={invitation.id}
                            className="flex flex-wrap items-center gap-3 p-3"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">
                                    {invitation.email}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    by {invitation.invitedBy} · expires{' '}
                                    {invitation.expiresAt}
                                    {invitation.hasProposedRole &&
                                        ' · with a role'}
                                </p>
                            </div>
                            <Badge
                                variant={
                                    invitation.status === 'pending'
                                        ? 'secondary'
                                        : 'outline'
                                }
                            >
                                {invitation.status}
                            </Badge>
                            {(invitation.status === 'pending' ||
                                invitation.status === 'expired') && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        router.post(
                                            `/manage/invitations/${invitation.id}/resend`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Resend
                                </Button>
                            )}
                            {invitation.status === 'pending' && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        router.delete(
                                            `/manage/invitations/${invitation.id}`,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Revoke
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>

                <section className="space-y-4">
                    <h2 className="text-lg font-medium">Invite someone</h2>
                    <Form
                        action="/manage/invitations"
                        method="post"
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        required
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="role_id">
                                        Role (optional)
                                    </Label>
                                    <select
                                        id="role_id"
                                        name="role_id"
                                        className={selectClass}
                                        value={roleId}
                                        onChange={(event) =>
                                            setRoleId(event.target.value)
                                        }
                                    >
                                        <option value="">No role yet</option>
                                        {[
                                            ...new Set(
                                                roles.map(
                                                    (candidate) =>
                                                        candidate.application,
                                                ),
                                            ),
                                        ].map((application) => (
                                            <optgroup
                                                key={application}
                                                label={application}
                                            >
                                                {roles
                                                    .filter(
                                                        (candidate) =>
                                                            candidate.application ===
                                                            application,
                                                    )
                                                    .map((candidate) => (
                                                        <option
                                                            key={candidate.id}
                                                            value={candidate.id}
                                                        >
                                                            {candidate.name}
                                                        </option>
                                                    ))}
                                            </optgroup>
                                        ))}
                                    </select>
                                </div>

                                {role && effectiveScope && (
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="scope_type">
                                            Where
                                        </Label>
                                        <select
                                            id="scope_type"
                                            name="scope_type"
                                            className={selectClass}
                                            value={effectiveScope}
                                            onChange={(event) =>
                                                setScopeType(event.target.value)
                                            }
                                        >
                                            {scopes.map((scope) => (
                                                <option
                                                    key={scope}
                                                    value={scope}
                                                >
                                                    {scopeLabels[scope]}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                )}

                                {role && effectiveScope === 'branch' && (
                                    <select
                                        name="branch_id"
                                        aria-label="Branch"
                                        className={selectClass}
                                        required
                                        defaultValue=""
                                    >
                                        <option value="" disabled>
                                            Choose a branch
                                        </option>
                                        {branches.map((branch) => (
                                            <option
                                                key={branch.id}
                                                value={branch.id}
                                            >
                                                {branch.name}
                                            </option>
                                        ))}
                                    </select>
                                )}

                                {role && effectiveScope === 'department' && (
                                    <select
                                        name="department_id"
                                        aria-label="Department"
                                        className={selectClass}
                                        required
                                        defaultValue=""
                                    >
                                        <option value="" disabled>
                                            Choose a department
                                        </option>
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

                                <Button type="submit" disabled={processing}>
                                    Send invitation
                                </Button>
                            </>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}
