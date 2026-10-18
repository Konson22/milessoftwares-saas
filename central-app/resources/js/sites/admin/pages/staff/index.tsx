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

type Props = {
    staff: Staff[];
    roles: Role[];
};

const selectClass =
    'h-8 rounded-md border border-input bg-transparent px-2 text-sm';

export default function StaffIndex({ staff, roles }: Props) {
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
                                        No MFA — cannot sign in
                                    </Badge>
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {member.email}
                            </p>

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
                </ul>

                <aside className="space-y-5">
                    <section className="space-y-3">
                        <h2 className="text-lg font-medium">Add staff</h2>
                        <Form
                            action="/staff"
                            method="post"
                            resetOnSuccess
                            className="space-y-3"
                        >
                            {({ errors: formErrors, processing }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="email">
                                            Account email
                                        </Label>
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
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        Add
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
