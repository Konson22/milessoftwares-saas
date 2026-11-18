import { Form, Head, router, usePage } from '@inertiajs/react';
import { Crown, Plus, Search, ShieldCheck, UserRound, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import InputError from '@/shared/components/input-error';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/shared/components/ui/dialog';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';
import { appIcon } from '@/shared/lib/app-icons';
import RolePicker from '@/sites/central/components/role-picker';
import type { PickerRole } from '@/sites/central/components/role-picker';

type Assignment = {
    id: string;
    role: string;
    application: string;
    applicationCode: string;
    scope: string;
    expiresAt: string | null;
};

type Member = {
    id: string;
    name: string;
    email: string;
    isOwner: boolean;
    /** Apps this member is stopped in (by code); the rest are enabled. */
    applicationAccess: Record<string, 'disabled' | 'suspended'>;
    assignments: Assignment[];
};

type Sign = 'active' | 'disabled' | 'suspended';

const SIGN_LABELS: Record<Sign, string> = {
    active: 'Can sign in',
    disabled: 'Sign-in disabled',
    suspended: 'Suspended',
};

function setSignIn(member: Member, application: string, status: Sign) {
    router.put(
        '/manage/access/application',
        { membership_id: member.id, application, status },
        { preserveScroll: true },
    );
}

type Role = PickerRole & {
    sensitivity: number;
    delegable: boolean;
};

type Option = { id: string; name: string };

type Application = { code: string; name: string };

type Props = {
    members: Member[];
    roles: Role[];
    applications: Application[];
    branches: Option[];
    departments: Option[];
};

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

/** A member's roles, one group per application. */
function byApplication(assignments: Assignment[]) {
    const groups = new Map<
        string,
        { code: string; name: string; items: Assignment[] }
    >();
    for (const assignment of assignments) {
        const group = groups.get(assignment.applicationCode) ?? {
            code: assignment.applicationCode,
            name: assignment.application,
            items: [],
        };
        group.items.push(assignment);
        groups.set(assignment.applicationCode, group);
    }

    return [...groups.values()];
}

function remove(assignment: Assignment) {
    router.delete(`/manage/access/${assignment.id}`, { preserveScroll: true });
}

export default function Access({
    members,
    roles,
    applications,
    branches,
    departments,
}: Props) {
    const [query, setQuery] = useState('');
    const [giving, setGiving] = useState<Member | null>(null);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    const shown = useMemo(() => {
        const needle = query.trim().toLowerCase();
        const sorted = [...members].sort(
            (a, b) => Number(b.isOwner) - Number(a.isOwner),
        );

        return needle === ''
            ? sorted
            : sorted.filter((member) =>
                  `${member.name} ${member.email}`
                      .toLowerCase()
                      .includes(needle),
              );
    }, [members, query]);

    const withoutRoles = members.filter(
        (member) => !member.isOwner && member.assignments.length === 0,
    ).length;

    return (
        <>
            <Head title="Access" />

            <PageHeader title="Access" />

            <dl className="grid grid-cols-3 gap-3 sm:max-w-xl">
                <Stat label="People" value={members.length} />
                <Stat label="Apps" value={applications.length} />
                <Stat
                    label="Without a role"
                    value={withoutRoles}
                    tone={withoutRoles > 0 ? 'warn' : undefined}
                />
            </dl>

            {errors.status && (
                <p className="mt-4 text-sm text-destructive" role="alert">
                    {errors.status}
                </p>
            )}

            <Panel className="mt-5 rounded-lg">
                <PanelHeader
                    title="People and their roles"
                    actions={
                        <div className="relative w-full sm:w-64">
                            <Search
                                className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden
                            />
                            <Input
                                type="search"
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Search by name or email"
                                aria-label="Search people"
                                className="h-8 pl-8"
                            />
                        </div>
                    }
                />

                {shown.length === 0 ? (
                    <EmptyState
                        icon={Search}
                        title="Nobody matches that search"
                    />
                ) : (
                    <ul className="divide-y">
                        {shown.map((member) => (
                            <MemberRow
                                key={member.id}
                                member={member}
                                applications={applications}
                                onGive={() => setGiving(member)}
                            />
                        ))}
                    </ul>
                )}
            </Panel>

            <Dialog
                open={giving !== null}
                onOpenChange={(open) => !open && setGiving(null)}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                    {giving && (
                        <GiveRoles
                            member={giving}
                            roles={roles}
                            branches={branches}
                            departments={departments}
                            onDone={() => setGiving(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function Stat({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone?: 'warn';
}) {
    return (
        <div className="rounded-lg border bg-card px-3 py-2.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd
                className={
                    tone === 'warn'
                        ? 'text-xl font-semibold text-amber-600 dark:text-amber-400'
                        : 'text-xl font-semibold'
                }
            >
                {value}
            </dd>
        </div>
    );
}

function MemberRow({
    member,
    applications,
    onGive,
}: {
    member: Member;
    applications: Application[];
    onGive: () => void;
}) {
    const groups = byApplication(member.assignments);

    return (
        <li className="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-start">
            <div className="flex min-w-0 items-center gap-3 sm:w-64 sm:shrink-0">
                <span
                    className={
                        member.isOwner
                            ? 'grid size-10 shrink-0 place-items-center rounded-full bg-primary text-sm font-semibold text-primary-foreground'
                            : 'grid size-10 shrink-0 place-items-center rounded-full bg-muted text-sm font-semibold text-muted-foreground'
                    }
                    aria-hidden
                >
                    {initials(member.name) || <UserRound className="size-4" />}
                </span>
                <div className="min-w-0">
                    <p className="flex items-center gap-1.5 truncate font-medium">
                        {member.name}
                        {member.isOwner && (
                            <Badge className="gap-1">
                                <Crown className="size-3" aria-hidden />
                                Owner
                            </Badge>
                        )}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {member.email}
                    </p>
                </div>
            </div>

            <div className="min-w-0 flex-1 space-y-3">
                {member.isOwner ? (
                    <OwnerAccess applications={applications} />
                ) : groups.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No roles yet, so they cannot open any app.
                    </p>
                ) : null}

                {member.isOwner && groups.length > 0 && (
                    <p className="text-xs text-muted-foreground">
                        Roles also held. The owner does not need them, so you
                        can remove them.
                    </p>
                )}

                {groups.length > 0 && (
                    <div className="space-y-2">
                        {groups.map((group) => (
                            <RoleGroup
                                key={group.code}
                                group={group}
                                signIn={
                                    member.isOwner
                                        ? null
                                        : (member.applicationAccess[
                                              group.code
                                          ] ?? 'active')
                                }
                                onSignIn={(status) =>
                                    setSignIn(member, group.code, status)
                                }
                            />
                        ))}
                    </div>
                )}
            </div>

            {!member.isOwner && (
                <Button
                    size="sm"
                    variant="outline"
                    className="self-start"
                    onClick={onGive}
                >
                    <Plus className="size-4" aria-hidden />
                    Give roles
                </Button>
            )}
        </li>
    );
}

function OwnerAccess({ applications }: { applications: Application[] }) {
    return (
        <div className="rounded-md border border-primary/30 bg-primary/5 px-3 py-2.5">
            <p className="flex items-center gap-1.5 text-sm font-medium">
                <ShieldCheck className="size-4 text-primary" aria-hidden />
                Full access to every app
            </p>
            {applications.length > 0 ? (
                <ul className="mt-2 flex flex-wrap gap-1.5">
                    {applications.map((application) => {
                        const Icon = appIcon(application.code);

                        return (
                            <li
                                key={application.code}
                                className="inline-flex items-center gap-1.5 rounded-full border bg-background px-2.5 py-0.5 text-xs"
                            >
                                <Icon className="size-3.5" aria-hidden />
                                {application.name}
                            </li>
                        );
                    })}
                </ul>
            ) : (
                <p className="mt-1 text-xs text-muted-foreground">
                    Apps you add to the business are included automatically.
                </p>
            )}
        </div>
    );
}

function RoleGroup({
    group,
    signIn,
    onSignIn,
}: {
    group: { code: string; name: string; items: Assignment[] };
    /** Null for the owner, who always signs in. */
    signIn: Sign | null;
    onSignIn: (status: Sign) => void;
}) {
    const Icon = appIcon(group.code);

    return (
        <div className="flex flex-col gap-1.5 sm:flex-row sm:items-start sm:gap-3">
            <p className="flex shrink-0 items-center gap-1.5 pt-1 text-xs font-medium text-muted-foreground sm:w-36">
                <Icon className="size-3.5" aria-hidden />
                <span className="truncate">{group.name}</span>
            </p>
            <ul className="flex flex-wrap gap-1.5">
                {group.items.map((assignment) => (
                    <li
                        key={assignment.id}
                        className="inline-flex items-center gap-1.5 rounded-md border bg-muted/40 py-1 pr-1 pl-2.5 text-xs"
                    >
                        <span className="font-medium">{assignment.role}</span>
                        <span className="text-muted-foreground">
                            {assignment.scope}
                            {assignment.expiresAt &&
                                ` · until ${assignment.expiresAt}`}
                        </span>
                        <button
                            type="button"
                            onClick={() => remove(assignment)}
                            aria-label={`Remove ${assignment.role} in ${assignment.application}`}
                            className="grid size-5 place-items-center rounded hover:bg-muted"
                        >
                            <X className="size-3" aria-hidden />
                        </button>
                    </li>
                ))}
            </ul>
            {signIn !== null && (
                <select
                    value={signIn}
                    onChange={(event) => onSignIn(event.target.value as Sign)}
                    aria-label={`Sign-in to ${group.name}`}
                    className={
                        signIn === 'active'
                            ? 'h-7 rounded-md border border-input bg-background px-1.5 text-xs sm:ml-auto'
                            : 'h-7 rounded-md border border-destructive bg-background px-1.5 text-xs font-medium text-destructive sm:ml-auto'
                    }
                >
                    {(Object.keys(SIGN_LABELS) as Sign[]).map((value) => (
                        <option key={value} value={value}>
                            {SIGN_LABELS[value]}
                        </option>
                    ))}
                </select>
            )}
        </div>
    );
}

function GiveRoles({
    member,
    roles,
    branches,
    departments,
    onDone,
}: {
    member: Member;
    roles: Role[];
    branches: Option[];
    departments: Option[];
    onDone: () => void;
}) {
    return (
        <>
            <DialogHeader>
                <DialogTitle>Give roles to {member.name}</DialogTitle>
                <DialogDescription>
                    Pick an app, tick its roles, then choose where they apply.
                    You can add roles from several apps at once.
                </DialogDescription>
            </DialogHeader>

            <Form
                action="/manage/access"
                method="post"
                onSuccess={onDone}
                options={{ preserveScroll: true }}
                className="space-y-4"
            >
                {({ errors, processing }) => (
                    <>
                        <input
                            type="hidden"
                            name="membership_id"
                            value={member.id}
                        />
                        <InputError message={errors.membership_id} />

                        <RolePicker
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

                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={onDone}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                Give roles
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
