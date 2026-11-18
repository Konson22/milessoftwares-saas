import { Form, Head, router, usePage } from '@inertiajs/react';
import {
    Clock,
    KeyRound,
    Mail,
    RotateCw,
    Trash2,
    UserMinus,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/shared/components/ui/dialog';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';
import { cn } from '@/shared/lib/utils';
import RolePicker from '@/sites/central/components/role-picker';
import type { PickerRole } from '@/sites/central/components/role-picker';

type Status = 'pending' | 'accepted' | 'revoked' | 'expired';

type Invitation = {
    id: string;
    email: string;
    status: Status;
    invitedBy: string;
    expiresAt: string;
    proposedRoles: number;
};

type LoginKind = 'email' | 'username';

/** Added here: before they choose their own password, or a username account. */
type Awaiting = {
    id: string;
    name: string;
    login: string;
    loginKind: LoginKind;
    /** Still on the temporary password. */
    pending: boolean;
    expiresAt: string | null;
    expired: boolean;
};

type Option = { id: string; name: string };

/** Flashed once after adding someone or issuing a new temporary password. */
type Credentials = {
    name: string;
    /** The email, or the username of an account without one. */
    login: string;
    loginKind: LoginKind;
    password: string;
    expiresAt: string;
    signInUrl: string;
    roleNote: string | null;
};

type Props = {
    invitations: Invitation[];
    awaiting: Awaiting[];
    roles: PickerRole[];
    branches: Option[];
    departments: Option[];
};

/** What a delete or remove button is about to do, waiting for a yes. */
type Confirming =
    | { kind: 'invitation'; invitation: Invitation }
    | { kind: 'member'; person: Awaiting };

const statusStyles: Record<Status, string> = {
    pending:
        'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30',
    accepted:
        'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
    expired:
        'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
    revoked: 'bg-muted text-muted-foreground ring-border',
};

const filters = ['all', 'pending', 'expired', 'accepted', 'revoked'] as const;
type Filter = (typeof filters)[number];

const dangerButton =
    'text-destructive hover:bg-destructive/10 hover:text-destructive';

export default function Invitations({
    invitations,
    awaiting,
    roles,
    branches,
    departments,
}: Props) {
    const [mode, setMode] = useState<'invite' | 'add'>('invite');
    const [addWith, setAddWith] = useState<LoginKind>('email');
    // Remounts the role picker after each submit, clearing its choices.
    const [round, setRound] = useState(0);
    const [filter, setFilter] = useState<Filter>('all');
    const [confirming, setConfirming] = useState<Confirming | null>(null);
    const page = usePage();
    const credentials = page.flash?.credentials as Credentials | undefined;
    // Refusals of a delete or remove (not allowed, the owner, yourself).
    const errors = (page.props?.errors ?? {}) as Record<string, string>;
    const refusal = errors.status ?? errors.invitation;

    const count = (status: Status) =>
        invitations.filter((invitation) => invitation.status === status).length;
    const shown =
        filter === 'all'
            ? invitations
            : invitations.filter((invitation) => invitation.status === filter);

    const confirm = () => {
        if (confirming?.kind === 'invitation') {
            router.delete(`/manage/invitations/${confirming.invitation.id}`, {
                preserveScroll: true,
            });
        } else if (confirming?.kind === 'member') {
            // A username account this business made is deleted; anyone
            // else is removed from the workspace and keeps their account.
            if (confirming.person.loginKind === 'username') {
                router.delete(
                    `/manage/members/${confirming.person.id}/account`,
                    { preserveScroll: true },
                );
                setConfirming(null);

                return;
            }
            router.put(
                `/manage/members/${confirming.person.id}/status`,
                { status: 'removed' },
                { preserveScroll: true },
            );
        }
        setConfirming(null);
    };

    return (
        <>
            <Head title="Invitations" />

            <PageHeader title="Invitations" />

            <dl className="grid grid-cols-3 gap-3 sm:max-w-xl">
                <Stat label="Waiting to accept" value={count('pending')} />
                <Stat
                    label="Expired"
                    value={count('expired')}
                    warn={count('expired') > 0}
                />
                <Stat label="Added directly" value={awaiting.length} />
            </dl>

            {refusal && (
                <p
                    role="alert"
                    className="mt-3 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
                >
                    {refusal}
                </p>
            )}

            <div className="mt-5 grid items-start gap-5 lg:grid-cols-[1fr_24rem]">
                <div className="min-w-0 space-y-5">
                    {credentials && <CredentialsCard {...credentials} />}

                    <Panel className="rounded-lg">
                        <PanelHeader
                            title="Sent invitations"
                            actions={
                                <div
                                    role="group"
                                    aria-label="Show invitations"
                                    className="flex flex-wrap gap-1"
                                >
                                    {filters.map((value) => (
                                        <button
                                            key={value}
                                            type="button"
                                            aria-pressed={filter === value}
                                            onClick={() => setFilter(value)}
                                            className={cn(
                                                'rounded-full px-2.5 py-1 text-xs font-medium capitalize',
                                                filter === value
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'text-muted-foreground hover:bg-muted',
                                            )}
                                        >
                                            {value}
                                        </button>
                                    ))}
                                </div>
                            }
                        />
                        {shown.length === 0 ? (
                            <EmptyState
                                icon={Mail}
                                title={
                                    invitations.length === 0
                                        ? 'No invitations yet'
                                        : 'None with this status'
                                }
                            />
                        ) : (
                            <ul className="divide-y">
                                {shown.map((invitation) => (
                                    <InvitationRow
                                        key={invitation.id}
                                        invitation={invitation}
                                        onDelete={() =>
                                            setConfirming({
                                                kind: 'invitation',
                                                invitation,
                                            })
                                        }
                                    />
                                ))}
                            </ul>
                        )}
                    </Panel>

                    {awaiting.length > 0 && (
                        <Panel className="rounded-lg">
                            <PanelHeader
                                title="Added directly"
                                description="Still on a temporary password, or signing in with a username (no email to reset by)."
                            />
                            <ul className="divide-y">
                                {awaiting.map((person) => (
                                    <AwaitingRow
                                        key={person.id}
                                        person={person}
                                        onRemove={() =>
                                            setConfirming({
                                                kind: 'member',
                                                person,
                                            })
                                        }
                                    />
                                ))}
                            </ul>
                        </Panel>
                    )}
                </div>

                <Panel className="space-y-4 rounded-lg p-4 lg:sticky lg:top-4">
                    <div
                        role="tablist"
                        aria-label="How to add someone"
                        className="grid grid-cols-2 rounded-md bg-muted p-1 text-sm"
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
                                className={cn(
                                    'rounded px-3 py-1.5 font-medium',
                                    mode === value
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground',
                                )}
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
                            onSuccess={() => setRound((current) => current + 1)}
                            options={{ preserveScroll: true }}
                            className="space-y-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <p className="text-sm text-muted-foreground">
                                        They get a link by email that works once
                                        and expires after 7 days.
                                    </p>
                                    <EmailField error={errors.email} />
                                    <RolePicker
                                        key={round}
                                        roles={roles}
                                        branches={branches}
                                        departments={departments}
                                        optional
                                        errors={errors}
                                    />
                                    <SeparationOfDuties
                                        error={errors.acknowledge_sod}
                                    />
                                    <Button
                                        type="submit"
                                        className="w-full"
                                        disabled={processing}
                                    >
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
                            onSuccess={() => setRound((current) => current + 1)}
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
                                    <fieldset className="grid gap-1.5">
                                        <legend className="mb-1.5 text-sm font-medium">
                                            Signs in with
                                        </legend>
                                        <div className="flex gap-4 text-sm">
                                            {(
                                                [
                                                    ['email', 'Email'],
                                                    [
                                                        'username',
                                                        'Username (no email)',
                                                    ],
                                                ] as const
                                            ).map(([value, label]) => (
                                                <label
                                                    key={value}
                                                    className="flex items-center gap-2"
                                                >
                                                    <input
                                                        type="radio"
                                                        name="add_with"
                                                        checked={
                                                            addWith === value
                                                        }
                                                        onChange={() =>
                                                            setAddWith(value)
                                                        }
                                                    />
                                                    {label}
                                                </label>
                                            ))}
                                        </div>
                                    </fieldset>
                                    {addWith === 'email' ? (
                                        <EmailField error={errors.email} />
                                    ) : (
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="username">
                                                Username
                                            </Label>
                                            <Input
                                                id="username"
                                                name="username"
                                                required
                                                autoCapitalize="none"
                                                autoComplete="off"
                                                spellCheck={false}
                                                maxLength={32}
                                                placeholder="e.g. john.deng"
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                For someone without an email,
                                                such as a pump attendant. They
                                                sign in with this and their
                                                password, on the web or in the
                                                mobile app.
                                            </p>
                                            <InputError
                                                message={errors.username}
                                            />
                                        </div>
                                    )}
                                    <RolePicker
                                        key={round}
                                        roles={roles}
                                        branches={branches}
                                        departments={departments}
                                        optional
                                        errors={errors}
                                    />
                                    <SeparationOfDuties
                                        error={errors.acknowledge_sod}
                                    />
                                    <Button
                                        type="submit"
                                        className="w-full"
                                        disabled={processing}
                                    >
                                        Add member
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </Panel>
            </div>

            <Dialog
                open={confirming !== null}
                onOpenChange={(open) => !open && setConfirming(null)}
            >
                <DialogContent className="sm:max-w-md">
                    {confirming?.kind === 'invitation' && (
                        <DialogHeader>
                            <DialogTitle>Delete this invitation?</DialogTitle>
                            <DialogDescription>
                                {confirming.invitation.status === 'pending'
                                    ? `The link sent to ${confirming.invitation.email} stops working at once, and the invitation leaves this list. You can invite them again later.`
                                    : `The invitation to ${confirming.invitation.email} leaves this list. You can invite them again later.`}
                            </DialogDescription>
                        </DialogHeader>
                    )}
                    {confirming?.kind === 'member' &&
                        confirming.person.loginKind === 'username' && (
                            <DialogHeader>
                                <DialogTitle>
                                    Delete {confirming.person.name}&apos;s
                                    account?
                                </DialogTitle>
                                <DialogDescription>
                                    The username {confirming.person.login} stops
                                    working at once, on the web and in the
                                    mobile app, and can be given to someone new.
                                    Their past records (sales, shifts) keep
                                    their name. This cannot be undone.
                                </DialogDescription>
                            </DialogHeader>
                        )}
                    {confirming?.kind === 'member' &&
                        confirming.person.loginKind !== 'username' && (
                            <DialogHeader>
                                <DialogTitle>
                                    Remove {confirming.person.name}?
                                </DialogTitle>
                                <DialogDescription>
                                    They leave this workspace and lose every
                                    role in it, so they can no longer sign in to
                                    its apps. Their past records stay. You can
                                    add or invite them again later.
                                </DialogDescription>
                            </DialogHeader>
                        )}
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setConfirming(null)}
                        >
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={confirm}>
                            {confirming?.kind !== 'member'
                                ? 'Delete'
                                : confirming.person.loginKind === 'username'
                                  ? 'Delete account'
                                  : 'Remove'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function Stat({
    label,
    value,
    warn = false,
}: {
    label: string;
    value: number;
    warn?: boolean;
}) {
    return (
        <div className="rounded-lg border bg-card px-3 py-2.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={cn(
                    'text-xl font-semibold',
                    warn && 'text-amber-600 dark:text-amber-400',
                )}
            >
                {value}
            </dd>
        </div>
    );
}

function StatusPill({ status }: { status: Status }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ring-1 ring-inset',
                statusStyles[status],
            )}
        >
            {status}
        </span>
    );
}

function InvitationRow({
    invitation,
    onDelete,
}: {
    invitation: Invitation;
    onDelete: () => void;
}) {
    return (
        <li className="flex flex-wrap items-center gap-3 px-4 py-3">
            <span
                className="grid size-9 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground"
                aria-hidden
            >
                <Mail className="size-4" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="flex flex-wrap items-center gap-2">
                    <span className="truncate font-medium">
                        {invitation.email}
                    </span>
                    <StatusPill status={invitation.status} />
                </p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    Invited by {invitation.invitedBy}
                    {invitation.status === 'pending' &&
                        ` · expires ${invitation.expiresAt}`}
                    {invitation.proposedRoles > 0 &&
                        ` · with ${invitation.proposedRoles === 1 ? 'a role' : `${invitation.proposedRoles} roles`}`}
                </p>
            </div>
            <div className="flex gap-1">
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
                        <RotateCw className="size-3.5" aria-hidden />
                        Resend
                    </Button>
                )}
                <Button
                    size="sm"
                    variant="ghost"
                    className={dangerButton}
                    aria-label={`Delete the invitation to ${invitation.email}`}
                    onClick={onDelete}
                >
                    <Trash2 className="size-3.5" aria-hidden />
                    Delete
                </Button>
            </div>
        </li>
    );
}

function AwaitingRow({
    person,
    onRemove,
}: {
    person: Awaiting;
    onRemove: () => void;
}) {
    return (
        <li className="flex flex-wrap items-center gap-3 px-4 py-3">
            <span
                className="grid size-9 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground"
                aria-hidden
            >
                <UserRound className="size-4" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="truncate font-medium">{person.name}</p>
                <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                    <span>
                        {person.loginKind === 'username'
                            ? `Username: ${person.login}`
                            : person.login}
                    </span>
                    {!person.pending && <span>· signed in</span>}
                    {person.expiresAt && (
                        <span
                            className={cn(
                                'inline-flex items-center gap-1',
                                person.expired &&
                                    'text-amber-600 dark:text-amber-400',
                            )}
                        >
                            <Clock className="size-3" aria-hidden />
                            {`password ${person.expired ? 'expired' : 'expires'} ${person.expiresAt}`}
                        </span>
                    )}
                </p>
            </div>
            <div className="flex gap-1">
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
                    <KeyRound className="size-3.5" aria-hidden />
                    New password
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    className={dangerButton}
                    aria-label={
                        person.loginKind === 'username'
                            ? `Delete ${person.name}'s account`
                            : `Remove ${person.name}`
                    }
                    onClick={onRemove}
                >
                    {person.loginKind === 'username' ? (
                        <>
                            <Trash2 className="size-3.5" aria-hidden />
                            Delete account
                        </>
                    ) : (
                        <>
                            <UserMinus className="size-3.5" aria-hidden />
                            Remove
                        </>
                    )}
                </Button>
            </div>
        </li>
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

/**
 * The temporary password, shown this once: it is not stored in plain text
 * and cannot be shown again (issue a new one instead).
 */
function CredentialsCard({
    name,
    login,
    loginKind,
    password,
    expiresAt,
    signInUrl,
    roleNote,
}: Credentials) {
    const [copied, setCopied] = useState(false);
    const loginLabel = loginKind === 'username' ? 'Username' : 'Email';
    const text = `Sign in at ${signInUrl}\n${loginLabel}: ${login}\nTemporary password: ${password}\n(Choose your own at first sign-in. Expires ${expiresAt}.)`;

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
                <dt className="text-muted-foreground">{loginLabel}</dt>
                <dd>{login}</dd>
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
