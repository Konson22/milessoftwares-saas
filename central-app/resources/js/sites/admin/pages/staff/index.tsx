import { Form, Head, router, usePage } from '@inertiajs/react';
import InputError from '@/shared/components/input-error';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';

type Staff = {
    id: string;
    name: string;
    email: string;
    status: 'active' | 'suspended' | 'removed';
    mfa: boolean;
    roles: { key: string; name: string }[];
    isYou: boolean;
};

type Role = {
    key: string;
    name: string;
    description: string;
    permissions: string[];
};

type Invitation = {
    id: string;
    email: string;
    name: string | null;
    role: string | null;
    status: 'pending' | 'expired';
    expiresAt: string;
};

type Props = {
    staff: Staff[];
    invitations: Invitation[];
    roles: Role[];
};

const selectClass =
    'h-8 rounded-md border border-input bg-transparent px-2 text-sm';

export default function StaffIndex({ staff, invitations, roles }: Props) {
    const roleName = (key: string | null) =>
        roles.find((role) => role.key === key)?.name ?? 'No role yet';

    const { errors } = usePage().props as { errors: Record<string, string> };

    return (
        <>
            <Head title="Staff" />

            <h1 className="text-2xl font-semibold">Staff</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                Nobody can change their own roles or status, and the last active
                super admin cannot be removed.
            </p>
            <InputError message={errors.staff} className="mt-2" />

            <div className="mt-5 grid gap-5 lg:grid-cols-[1fr_20rem]">
                <ul className="space-y-4">
                    {staff.map((member) => (
                        <li key={member.id} className="rounded-lg border p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="font-medium">{member.name}</p>
                                {member.isYou && (
                                    <Badge variant="secondary">You</Badge>
                                )}
                                {member.status !== 'active' && (
                                    <Badge variant="destructive">
                                        {member.status}
                                    </Badge>
                                )}
                                {!member.mfa && (
                                    <Badge variant="outline">
                                        Two-factor not set up
                                    </Badge>
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {member.email}
                            </p>
                            {!member.mfa && !member.isYou && (
                                <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                    They cannot sign in until they set up
                                    two-factor authentication.
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                '/staff',
                                                {
                                                    email: member.email,
                                                    name: member.name,
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Email a setup link
                                    </Button>
                                </div>
                            )}

                            <div className="mt-3 flex flex-wrap gap-2">
                                {member.roles.map((role) => (
                                    <Badge key={role.key} className="gap-1">
                                        {role.name}
                                        {!member.isYou && (
                                            <button
                                                type="button"
                                                aria-label={`Remove ${role.name}`}
                                                className="ml-1"
                                                onClick={() =>
                                                    router.delete(
                                                        `/staff/${member.id}/roles/${role.key}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                ×
                                            </button>
                                        )}
                                    </Badge>
                                ))}
                            </div>

                            {!member.isYou && (
                                <div className="mt-3 flex flex-wrap items-center gap-2">
                                    <Form
                                        action={`/staff/${member.id}/roles`}
                                        method="post"
                                        options={{ preserveScroll: true }}
                                        className="flex gap-2"
                                    >
                                        <select
                                            name="role"
                                            className={selectClass}
                                            defaultValue=""
                                            aria-label="Role to grant"
                                            required
                                        >
                                            <option value="" disabled>
                                                Add role…
                                            </option>
                                            {roles
                                                .filter(
                                                    (role) =>
                                                        !member.roles.some(
                                                            (held) =>
                                                                held.key ===
                                                                role.key,
                                                        ),
                                                )
                                                .map((role) => (
                                                    <option
                                                        key={role.key}
                                                        value={role.key}
                                                    >
                                                        {role.name}
                                                    </option>
                                                ))}
                                        </select>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="outline"
                                        >
                                            Grant
                                        </Button>
                                    </Form>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() =>
                                            router.put(
                                                `/staff/${member.id}/status`,
                                                {
                                                    status:
                                                        member.status ===
                                                        'active'
                                                            ? 'suspended'
                                                            : 'active',
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {member.status === 'active'
                                            ? 'Suspend'
                                            : 'Reactivate'}
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                    {invitations.length > 0 && (
                        <li className="space-y-3 pt-2">
                            <h2 className="text-lg font-medium">Invitations</h2>
                            <ul className="space-y-3">
                                {invitations.map((invitation) => (
                                    <li
                                        key={invitation.id}
                                        className="flex flex-wrap items-center gap-3 rounded-lg border border-dashed p-4"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium">
                                                {invitation.name ??
                                                    invitation.email}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {invitation.email} ·{' '}
                                                {roleName(invitation.role)} ·{' '}
                                                {invitation.status === 'expired'
                                                    ? 'expired'
                                                    : `expires ${invitation.expiresAt}`}
                                            </p>
                                        </div>
                                        <Badge
                                            variant={
                                                invitation.status === 'expired'
                                                    ? 'destructive'
                                                    : 'secondary'
                                            }
                                        >
                                            {invitation.status === 'expired'
                                                ? 'Expired'
                                                : 'Invited'}
                                        </Badge>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    `/staff/invitations/${invitation.id}/resend`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Resend
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.delete(
                                                    `/staff/invitations/${invitation.id}`,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Cancel
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        </li>
                    )}
                </ul>

                <aside className="space-y-5">
                    <section className="space-y-3">
                        <h2 className="text-lg font-medium">Invite staff</h2>
                        <p className="text-sm text-muted-foreground">
                            They get an email to choose a password and set up
                            two-factor authentication, then join with the role
                            you pick.
                        </p>
                        <Form
                            action="/staff"
                            method="post"
                            resetOnSuccess
                            className="space-y-3"
                        >
                            {({ errors: formErrors, processing }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="name">Name</Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            autoComplete="off"
                                        />
                                        <InputError message={formErrors.name} />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            required
                                        />
                                        <InputError
                                            message={formErrors.email}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="role">Role</Label>
                                        <select
                                            id="role"
                                            name="role"
                                            className={selectClass}
                                            defaultValue=""
                                        >
                                            <option value="">
                                                Choose later
                                            </option>
                                            {roles.map((role) => (
                                                <option
                                                    key={role.key}
                                                    value={role.key}
                                                >
                                                    {role.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={formErrors.role} />
                                    </div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        Send invitation
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>

                    <section className="space-y-3">
                        <h2 className="text-lg font-medium">Roles</h2>
                        <dl className="space-y-3 text-sm">
                            {roles.map((role) => (
                                <div key={role.key}>
                                    <dt className="font-medium">{role.name}</dt>
                                    <dd className="text-muted-foreground">
                                        {role.description}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </section>
                </aside>
            </div>
        </>
    );
}
