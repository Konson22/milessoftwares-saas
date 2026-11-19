import { Head, router, usePage } from '@inertiajs/react';
import {
    Clock,
    KeyRound,
    Mail,
    Search,
    ShieldCheck,
    UserPlus,
    Users,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Errors, SelectField, TextField } from '@/components/fields';
import { invite } from '@/routes/people';
import accounts from '@/routes/people/accounts';
import { cancel as cancelInvitation } from '@/routes/people/invitations';
import roleRoutes from '@/routes/people/roles';

type Role = {
    key: string;
    name: string;
    description: string;
    delegable: boolean;
    assignable: boolean;
};

type Person = {
    membershipId: string;
    userId: string;
    name: string;
    signInName: string;
    isOwner: boolean;
    access: string;
    roles: string[];
};

type Props = {
    unavailable: boolean;
    isOwner: boolean;
    me: string;
    roles: Role[];
    roleNames: Record<string, string>;
    people: Person[];
    others: { membershipId: string; name: string; signInName: string }[];
    invitations: {
        id: string;
        email: string;
        invitedBy: string;
        expiresAt: string;
        roles: string[];
    }[];
};

type Panel = 'invite' | 'account' | null;

/**
 * Logbook's people: who holds which Logbook role, invitations waiting, and
 * adding someone, all without leaving Logbook. The platform keeps the
 * workspace's rules: a Manager gives Contributor, Reviewer and Viewer; the
 * owner gives every role.
 */
export default function People({
    unavailable,
    isOwner,
    me,
    roles,
    roleNames,
    people,
    others,
    invitations,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [panel, setPanel] = useState<Panel>(null);
    const [query, setQuery] = useState('');
    const [busy, setBusy] = useState(false);
    const assignable = roles.filter((r) => r.assignable);
    const firstRole = assignable[0]?.key ?? '';

    const shown = useMemo(() => {
        const q = query.trim().toLowerCase();

        return q === ''
            ? people
            : people.filter(
                  (p) =>
                      p.name.toLowerCase().includes(q) ||
                      p.signInName.toLowerCase().includes(q),
              );
    }, [people, query]);

    const post = (
        url: string,
        data: Record<string, unknown>,
        done?: () => void,
    ) =>
        router.post(url, data as never, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => done?.(),
        });

    return (
        <>
            <Head title="People" />
            <PageHeader
                title="People"
                actions={
                    !unavailable && (
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setPanel('account')}
                            >
                                <KeyRound /> Add account
                            </Button>
                            <Button onClick={() => setPanel('invite')}>
                                <Mail /> Invite by email
                            </Button>
                        </>
                    )
                }
            />
            <p className="-mt-1 mb-5 max-w-2xl text-sm text-muted-foreground">
                Everyone who works in Logbook and what they may do.{' '}
                {isOwner
                    ? 'As the owner you give every role, Manager included.'
                    : 'You give Contributor, Reviewer and Viewer; the owner gives Manager, Auditor and administrator.'}
            </p>

            {unavailable && (
                <div className="mb-4 rounded-md border border-[#f1bbbc] bg-[#fdf3f4] px-4 py-3 text-sm text-[#a4262c]">
                    The platform could not be reached, so people cannot be shown
                    or changed right now. Try again in a moment.
                </div>
            )}

            {errors.people && panel === null && (
                <div className="mb-4">
                    <Errors errors={{ people: errors.people }} />
                </div>
            )}

            {panel === 'invite' && (
                <InviteForm
                    roles={assignable}
                    firstRole={firstRole}
                    errors={errors}
                    busy={busy}
                    onCancel={() => setPanel(null)}
                    onSubmit={(data) =>
                        post(invite().url, data, () => setPanel(null))
                    }
                />
            )}
            {panel === 'account' && (
                <AccountForm
                    roles={assignable}
                    firstRole={firstRole}
                    errors={errors}
                    busy={busy}
                    onCancel={() => setPanel(null)}
                    onSubmit={(data) =>
                        post(accounts.store().url, data, () => setPanel(null))
                    }
                />
            )}

            <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start">
                <div className="grid gap-5">
                    <section className="overflow-hidden rounded-lg border bg-card shadow-[0_1px_2px_#e3e3e3]">
                        <header className="flex flex-wrap items-center justify-between gap-3 border-b border-[#ebebeb] px-4 py-3">
                            <h2 className="text-sm font-semibold">
                                In Logbook{' '}
                                <span className="font-normal text-muted-foreground tabular-nums">
                                    {people.length}
                                </span>
                            </h2>
                            <label className="relative w-full sm:w-64">
                                <Search
                                    className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                                    aria-hidden
                                />
                                <span className="sr-only">Search people</span>
                                <Input
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder="Search by name"
                                    className="pl-8"
                                />
                            </label>
                        </header>
                        {shown.length === 0 ? (
                            <EmptyState
                                icon={Users}
                                title={
                                    people.length === 0
                                        ? 'Nobody has a Logbook role yet'
                                        : 'Nobody matches'
                                }
                            >
                                {people.length === 0
                                    ? 'Invite someone by email, add an account for someone without one, or give a role to someone already in the workspace.'
                                    : 'Try another name.'}
                            </EmptyState>
                        ) : (
                            <ul className="divide-y divide-[#ebebeb]">
                                {shown.map((p) => (
                                    <PersonRow
                                        key={p.membershipId}
                                        person={p}
                                        isMe={p.userId === me}
                                        roles={roles}
                                        roleNames={roleNames}
                                        onGive={(role) =>
                                            post(roleRoutes.store().url, {
                                                membership_id: p.membershipId,
                                                role,
                                            })
                                        }
                                        onTakeBack={(role) =>
                                            post(roleRoutes.revoke().url, {
                                                membership_id: p.membershipId,
                                                role,
                                            })
                                        }
                                    />
                                ))}
                            </ul>
                        )}
                        {errors.role && panel === null && (
                            <p className="border-t border-[#ebebeb] px-4 py-2 text-sm text-destructive">
                                {errors.role}
                            </p>
                        )}
                    </section>

                    {others.length > 0 && (
                        <ExistingMembers
                            others={others}
                            roles={assignable}
                            firstRole={firstRole}
                            onGive={(membershipId, role) =>
                                post(roleRoutes.store().url, {
                                    membership_id: membershipId,
                                    role,
                                })
                            }
                        />
                    )}
                </div>

                <aside className="grid gap-5">
                    <Card title="Waiting to join" count={invitations.length}>
                        {invitations.length === 0 ? (
                            <p className="px-4 py-3 text-sm text-muted-foreground">
                                No open invitations.
                            </p>
                        ) : (
                            <ul className="divide-y divide-[#ebebeb]">
                                {invitations.map((i) => (
                                    <li
                                        key={i.id}
                                        className="flex items-start gap-3 px-4 py-3"
                                    >
                                        <Clock
                                            className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                            aria-hidden
                                        />
                                        <span className="grid min-w-0 flex-1 gap-0.5">
                                            <span className="truncate text-sm font-medium">
                                                {i.email}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {i.roles.join(', ')} · until{' '}
                                                {new Date(
                                                    i.expiresAt,
                                                ).toLocaleDateString()}
                                            </span>
                                        </span>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                post(
                                                    cancelInvitation(i.id).url,
                                                    {},
                                                )
                                            }
                                        >
                                            Cancel
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>

                    <Card title="Roles">
                        <ul className="divide-y divide-[#ebebeb]">
                            {roles.map((r) => (
                                <li
                                    key={r.key}
                                    className="grid gap-0.5 px-4 py-2.5"
                                >
                                    <span className="flex items-center gap-2 text-sm font-medium">
                                        {r.name}
                                        {!r.delegable && (
                                            <span className="inline-flex items-center gap-1 rounded-sm bg-[#f0f0f0] px-1.5 text-[0.6875rem] font-normal text-muted-foreground">
                                                <ShieldCheck
                                                    className="size-3"
                                                    aria-hidden
                                                />
                                                Owner gives
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {r.description}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </Card>
                </aside>
            </div>
        </>
    );
}

function PersonRow({
    person: p,
    isMe,
    roles,
    roleNames,
    onGive,
    onTakeBack,
}: {
    person: Person;
    isMe: boolean;
    roles: Role[];
    roleNames: Record<string, string>;
    onGive: (role: string) => void;
    onTakeBack: (role: string) => void;
}) {
    const canGive = roles.filter(
        (r) => r.assignable && !p.roles.includes(r.key),
    );
    const assignable = new Set(
        roles.filter((r) => r.assignable).map((r) => r.key),
    );

    return (
        <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
            <span className="flex min-w-0 flex-1 basis-56 items-center gap-3">
                <Initials name={p.name} />
                <span className="grid min-w-0">
                    <span className="flex items-center gap-2 truncate text-sm font-medium">
                        {p.name}
                        {isMe && (
                            <span className="text-xs font-normal text-muted-foreground">
                                (you)
                            </span>
                        )}
                        {p.access !== 'active' && (
                            <span className="rounded-sm bg-[#fde7e9] px-1.5 text-[0.6875rem] text-[#a4262c] capitalize">
                                {p.access}
                            </span>
                        )}
                    </span>
                    <span className="truncate text-xs text-muted-foreground">
                        {p.signInName}
                    </span>
                </span>
            </span>
            <span className="flex flex-wrap items-center gap-1.5">
                {p.isOwner ? (
                    <span className="rounded-sm bg-[#e8efff] px-2 py-0.5 text-xs text-primary">
                        Owner · full access
                    </span>
                ) : (
                    p.roles.map((key) => (
                        <span
                            key={key}
                            className="inline-flex items-center gap-1 rounded-sm border border-[#d1d1d1] bg-[#f5f5f5] py-0.5 pr-1 pl-2 text-xs"
                        >
                            {roleNames[key] ?? key}
                            {assignable.has(key) && !isMe && (
                                <button
                                    type="button"
                                    onClick={() => onTakeBack(key)}
                                    className="grid size-4 place-items-center rounded-sm text-muted-foreground hover:bg-[#e0e0e0] hover:text-foreground"
                                    aria-label={`Take back ${roleNames[key] ?? key} from ${p.name}`}
                                >
                                    <X className="size-3" aria-hidden />
                                </button>
                            )}
                        </span>
                    ))
                )}
                {!p.isOwner && !isMe && canGive.length > 0 && (
                    <select
                        value=""
                        onChange={(e) =>
                            e.target.value && onGive(e.target.value)
                        }
                        aria-label={`Give ${p.name} a role`}
                        className="h-7 rounded-md border border-dashed border-[#c7c7c7] bg-card px-2 text-xs text-muted-foreground hover:border-primary hover:text-primary"
                    >
                        <option value="">+ Give role</option>
                        {canGive.map((r) => (
                            <option key={r.key} value={r.key}>
                                {r.name}
                            </option>
                        ))}
                    </select>
                )}
            </span>
        </li>
    );
}

function ExistingMembers({
    others,
    roles,
    firstRole,
    onGive,
}: {
    others: Props['others'];
    roles: Role[];
    firstRole: string;
    onGive: (membershipId: string, role: string) => void;
}) {
    const [member, setMember] = useState('');
    const [role, setRole] = useState(firstRole);

    return (
        <Card title="Already in the workspace">
            <form
                className="grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_12rem_auto] sm:items-end"
                onSubmit={(e) => {
                    e.preventDefault();
                    if (member && role) {
                        onGive(member, role);
                        setMember('');
                    }
                }}
            >
                <SelectField
                    label="Person"
                    value={member}
                    onChange={setMember}
                    empty="Choose someone"
                    options={others.map((o) => ({
                        value: o.membershipId,
                        label: `${o.name} (${o.signInName})`,
                    }))}
                />
                <SelectField
                    label="Logbook role"
                    value={role}
                    onChange={setRole}
                    options={roles.map((r) => ({
                        value: r.key,
                        label: r.name,
                    }))}
                />
                <Button type="submit" variant="outline" disabled={!member}>
                    <UserPlus /> Give role
                </Button>
            </form>
        </Card>
    );
}

function InviteForm({
    roles,
    firstRole,
    errors,
    busy,
    onCancel,
    onSubmit,
}: {
    roles: Role[];
    firstRole: string;
    errors: Record<string, string>;
    busy: boolean;
    onCancel: () => void;
    onSubmit: (data: { email: string; role: string }) => void;
}) {
    const [data, setData] = useState({ email: '', role: firstRole });

    return (
        <FormCard
            error={errors.people}
            busy={busy}
            title="Invite by email"
            note="They get an email with a link, and join Logbook with this role when they accept."
            onCancel={onCancel}
            onSubmit={() => onSubmit(data)}
            submit={
                <>
                    <Mail /> Send invitation
                </>
            }
        >
            <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_14rem]">
                <TextField
                    label="Email"
                    type="email"
                    value={data.email}
                    onChange={(email) => setData({ ...data, email })}
                    error={errors.email}
                    required
                />
                <SelectField
                    label="Logbook role"
                    value={data.role}
                    onChange={(role) => setData({ ...data, role })}
                    options={roles.map((r) => ({
                        value: r.key,
                        label: r.name,
                    }))}
                    error={errors.role}
                />
            </div>
        </FormCard>
    );
}

function AccountForm({
    roles,
    firstRole,
    errors,
    busy,
    onCancel,
    onSubmit,
}: {
    roles: Role[];
    firstRole: string;
    errors: Record<string, string>;
    busy: boolean;
    onCancel: () => void;
    onSubmit: (data: {
        name: string;
        username: string;
        password: string;
        role: string;
    }) => void;
}) {
    const [data, setData] = useState({
        name: '',
        username: '',
        password: '',
        role: firstRole,
    });

    return (
        <FormCard
            error={errors.people}
            busy={busy}
            title="Add an account"
            note="For someone without an email, such as field staff on the phone app. Give them the username and this temporary password; they choose their own at first sign-in."
            onCancel={onCancel}
            onSubmit={() => onSubmit(data)}
            submit={
                <>
                    <KeyRound /> Add account
                </>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <TextField
                    label="Full name"
                    value={data.name}
                    onChange={(name) => setData({ ...data, name })}
                    error={errors.name}
                    required
                />
                <TextField
                    label="Username"
                    value={data.username}
                    onChange={(username) =>
                        setData({ ...data, username: username.toLowerCase() })
                    }
                    error={errors.username}
                    hint="Letters, digits, dots, dashes or underscores."
                    required
                />
                <TextField
                    label="Temporary password"
                    value={data.password}
                    onChange={(password) => setData({ ...data, password })}
                    error={errors.password}
                    hint="At least 8 characters."
                    required
                />
                <SelectField
                    label="Logbook role"
                    value={data.role}
                    onChange={(role) => setData({ ...data, role })}
                    options={roles.map((r) => ({
                        value: r.key,
                        label: r.name,
                    }))}
                    error={errors.role}
                />
            </div>
        </FormCard>
    );
}

function FormCard({
    title,
    note,
    children,
    submit,
    error,
    busy,
    onCancel,
    onSubmit,
}: {
    title: string;
    note: string;
    children: ReactNode;
    submit: ReactNode;
    error?: string;
    busy?: boolean;
    onCancel: () => void;
    onSubmit: () => void;
}) {
    const dialog = useRef<HTMLDialogElement>(null);
    const titleId = useId();

    // The browser's own modal: focus stays inside, Esc closes, the page
    // behind can't be clicked.
    useEffect(() => {
        const element = dialog.current;
        if (element && !element.open) {
            element.showModal();
        }

        return () => element?.close();
    }, []);

    return (
        <dialog
            ref={dialog}
            aria-labelledby={titleId}
            onCancel={(e) => {
                e.preventDefault();
                onCancel();
            }}
            onClick={(e) => {
                // A click on the backdrop (outside the box) closes it.
                if (e.target === dialog.current) {
                    onCancel();
                }
            }}
            // Centred on the screen, both ways, whatever the page's resets.
            style={{
                position: 'fixed',
                inset: 0,
                margin: 'auto',
                height: 'fit-content',
                maxHeight: 'calc(100dvh - 2rem)',
            }}
            className="w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-lg border bg-card p-0 text-foreground shadow-[0_8px_24px_#1b1b1b] backdrop:bg-[#3b3f46]"
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    onSubmit();
                }}
            >
                <header className="flex items-start gap-3 border-b border-[#ebebeb] px-5 py-4">
                    <div className="grid flex-1 gap-0.5">
                        <h2 id={titleId} className="text-base font-semibold">
                            {title}
                        </h2>
                        <p className="text-xs text-muted-foreground">{note}</p>
                    </div>
                    <button
                        type="button"
                        onClick={onCancel}
                        className="grid size-8 shrink-0 place-items-center rounded-md text-muted-foreground hover:bg-[#f0f0f0] hover:text-foreground"
                        aria-label="Close"
                    >
                        <X className="size-4" aria-hidden />
                    </button>
                </header>
                <div className="grid gap-4 px-5 py-4">
                    {error && <Errors errors={{ people: error }} />}
                    {children}
                </div>
                <div className="flex justify-end gap-2 border-t border-[#ebebeb] bg-[#fafafa] px-5 py-3">
                    <Button type="button" variant="ghost" onClick={onCancel}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={busy}>
                        {submit}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}

function Card({
    title,
    count,
    children,
}: {
    title: string;
    count?: number;
    children: ReactNode;
}) {
    return (
        <section className="overflow-hidden rounded-lg border bg-card shadow-[0_1px_2px_#e3e3e3]">
            <h2 className="border-b border-[#ebebeb] px-4 py-3 text-sm font-semibold">
                {title}
                {count !== undefined && (
                    <span className="font-normal text-muted-foreground tabular-nums">
                        {' '}
                        {count}
                    </span>
                )}
            </h2>
            {children}
        </section>
    );
}

function Initials({ name }: { name: string }) {
    const letters = name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join('');

    return (
        <span
            className="grid size-9 shrink-0 place-items-center rounded-full bg-[#e8efff] text-xs font-semibold text-primary"
            aria-hidden
        >
            {letters || '?'}
        </span>
    );
}
