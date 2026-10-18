import { Form, Head, router, usePage } from '@inertiajs/react';
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

type Awaiting = {
    id: string;
    name: string;
    email: string;
    expiresAt: string | null;
    expired: boolean;
};

type Role = { id: string; name: string; application: string; scopes: string[] };

type Option = { id: string; name: string };

/** Flashed once after adding someone or issuing a new temporary password. */
type Credentials = {
    name: string;
    email: string;
    password: string;
    expiresAt: string;
    signInUrl: string;
    roleNote: string | null;
};

type Props = {
    invitations: Invitation[];
    awaiting: Awaiting[];
    roles: Role[];
    branches: Option[];
    departments: Option[];
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm';
const scopeLabels: Record<string, string> = {
    organization: 'Whole organization',
    branch: 'One branch',
    department: 'One department',
};

export default function Invitations({
    invitations,
    awaiting,
    roles,
    branches,
    departments,
}: Props) {
    const [mode, setMode] = useState<'invite' | 'add'>('invite');
    const credentials = usePage().flash.credentials as Credentials | undefined;

    return (
        <>
            <Head title="Invitations" />

            <h1 className="text-2xl font-semibold">Invitations</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                Each link works once and expires after 7 days. A proposed role
                is checked again when the person accepts.
            </p>

            <div className="mt-5 grid gap-5 lg:grid-cols-[1fr_22rem]">
                <div className="space-y-5">
                    {credentials && <CredentialsCard {...credentials} />}

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

                    {awaiting.length > 0 && (
                        <section className="space-y-3">
                            <h2 className="text-lg font-medium">
                                Awaiting first sign-in
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Added with a temporary password and have not
                                chosen their own yet.
                            </p>
                            <ul className="divide-y rounded-lg border text-sm">
                                {awaiting.map((person) => (
                                    <li
                                        key={person.id}
                                        className="flex flex-wrap items-center gap-3 p-3"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium">
                                                {person.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {person.email}
                                                {person.expiresAt &&
                                                    ` · password ${person.expired ? 'expired' : 'expires'} ${person.expiresAt}`}
                                            </p>
                                        </div>
                                        {person.expired && (
                                            <Badge variant="outline">
                                                expired
                                            </Badge>
                                        )}
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(
                                                    `/manage/members/${person.id}/temporary-password`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            New password
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>

                <section className="space-y-4">
                    <div
                        role="tablist"
                        aria-label="How to add someone"
                        className="grid grid-cols-2 rounded-md border p-1 text-sm"
                    >
                        {(
                            [
                                ['invite', 'Invite by email'],
                                ['add', 'Add directly'],
                            ] as const
                        ).map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                role="tab"
                                aria-selected={mode === value}
                                onClick={() => setMode(value)}
                                className={`rounded px-3 py-1.5 font-medium ${
                                    mode === value
                                        ? 'bg-muted text-foreground'
                                        : 'text-muted-foreground'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>

                    {mode === 'invite' ? (
                        <Form
                            key="invite"
                            action="/manage/invitations"
                            method="post"
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <EmailField error={errors.email} />
                                    <RoleFields
                                        roles={roles}
                                        branches={branches}
                                        departments={departments}
                                    />
                                    <SeparationOfDuties
                                        error={errors.acknowledge_sod}
                                    />
                                    <Button type="submit" disabled={processing}>
                                        Send invitation
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : (
                        <Form
                            key="add"
                            action="/manage/members"
                            method="post"
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <p className="text-sm text-muted-foreground">
                                        For someone without an account who
                                        should start now. You get a temporary
                                        password to hand over; they choose their
                                        own at first sign-in.
                                    </p>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="name">Name</Label>
                                        <Input id="name" name="name" required />
                                        <InputError message={errors.name} />
                                    </div>
                                    <EmailField error={errors.email} />
                                    <RoleFields
                                        roles={roles}
                                        branches={branches}
                                        departments={departments}
                                    />
                                    <SeparationOfDuties
                                        error={errors.acknowledge_sod}
                                    />
                                    <Button type="submit" disabled={processing}>
                                        Add member
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </section>
            </div>
        </>
    );
}

/**
 * Shown when the proposed role combines permissions the catalog flags (a
 * pharmacy manager both sells and voids). Ticking it and sending again
 * gives the role anyway, as on the access page.
 */
function SeparationOfDuties({ error }: { error?: string }) {
    if (!error) {
        return null;
    }

    return (
        <div className="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-500/40 dark:bg-amber-500/10">
            <p className="font-medium">Separation of duties</p>
            <p>{error}</p>
            <label className="flex items-center gap-2">
                <input type="checkbox" name="acknowledge_sod" value="1" />
                Give the role anyway
            </label>
        </div>
    );
}

function EmailField({ error }: { error?: string }) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor="email">Email</Label>
            <Input id="email" name="email" type="email" required />
            <InputError message={error} />
        </div>
    );
}

function RoleFields({
    roles,
    branches,
    departments,
}: {
    roles: Role[];
    branches: Option[];
    departments: Option[];
}) {
    const [roleId, setRoleId] = useState('');
    const [scopeType, setScopeType] = useState('organization');
    const role = roles.find((candidate) => candidate.id === roleId);
    const scopes = (role?.scopes ?? []).filter((scope) => scope in scopeLabels);
    const effectiveScope = scopes.includes(scopeType) ? scopeType : scopes[0];

    return (
        <>
            <div className="grid gap-1.5">
                <Label htmlFor="role_id">Role (optional)</Label>
                <select
                    id="role_id"
                    name="role_id"
                    className={selectClass}
                    value={roleId}
                    onChange={(event) => setRoleId(event.target.value)}
                >
                    <option value="">No role yet</option>
                    {[
                        ...new Set(
                            roles.map((candidate) => candidate.application),
                        ),
                    ].map((application) => (
                        <optgroup key={application} label={application}>
                            {roles
                                .filter(
                                    (candidate) =>
                                        candidate.application === application,
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
                        <option key={branch.id} value={branch.id}>
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
                        <option key={department.id} value={department.id}>
                            {department.name}
                        </option>
                    ))}
                </select>
            )}
        </>
    );
}

/**
 * The temporary password, shown this once: it is not stored in plain text
 * and cannot be shown again (issue a new one instead).
 */
function CredentialsCard({
    name,
    email,
    password,
    expiresAt,
    signInUrl,
    roleNote,
}: Credentials) {
    const [copied, setCopied] = useState(false);
    const text = `Sign in at ${signInUrl}\nEmail: ${email}\nTemporary password: ${password}\n(Choose your own at first sign-in. Expires ${expiresAt}.)`;

    return (
        <section
            aria-labelledby="credentials-title"
            className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-700 dark:bg-amber-950/40"
        >
            <h2 id="credentials-title" className="font-medium">
                Hand these to {name}
            </h2>
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                <dt className="text-muted-foreground">Sign in at</dt>
                <dd className="break-all">{signInUrl}</dd>
                <dt className="text-muted-foreground">Email</dt>
                <dd>{email}</dd>
                <dt className="text-muted-foreground">Temporary password</dt>
                <dd className="font-mono text-base font-semibold tracking-wide">
                    {password}
                </dd>
                <dt className="text-muted-foreground">Works until</dt>
                <dd>{expiresAt}</dd>
            </dl>
            {roleNote && (
                <p className="text-amber-800 dark:text-amber-300">{roleNote}</p>
            )}
            <p className="text-muted-foreground">
                This is the only time the password is shown. If it is lost,
                issue a new one.
            </p>
            <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() =>
                    navigator.clipboard
                        .writeText(text)
                        .then(() => setCopied(true))
                        .catch(() => setCopied(false))
                }
            >
                {copied ? 'Copied' : 'Copy details'}
            </Button>
        </section>
    );
}
