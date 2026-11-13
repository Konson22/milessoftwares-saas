import { Form, Head, usePage } from '@inertiajs/react';
import {
    Check,
    Copy,
    Eye,
    EyeOff,
    KeyRound,
    Pencil,
    Plus,
    UserRound,
    Wand2,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { cn } from '@miles/ui/lib/utils';
import { Field, Select } from '@/components/field';
import { store, update } from '@/routes/attendants';

type Attendant = {
    id: string;
    name: string;
    employeeCode: string | null;
    phone: string | null;
    userId: string | null;
    /** The email, or the username of an account without one. */
    signIn: string | null;
    active: boolean;
    atStation: boolean;
};

type UserOption = { id: string; name: string; email: string };

type Props = {
    attendants: Attendant[];
    users: UserOption[];
    canManage: boolean;
};

type SignInMode = 'none' | 'create' | 'link';

export default function Attendants({ attendants, users, canManage }: Props) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<string | null>(null);
    const handOver = usePage().props.flash.attendantSignIn;

    // A sign-in belongs to one attendant: offer only the free ones.
    const linked = new Set(
        attendants.map((attendant) => attendant.userId).filter(Boolean),
    );
    const freeUsers = users.filter((user) => !linked.has(user.id));
    const showAdd = canManage && (adding || attendants.length === 0);
    const here = attendants.filter(
        (attendant) => attendant.active && attendant.atStation,
    ).length;

    return (
        <>
            <Head title="Attendants" />
            <PageHeader
                title="Attendants"
                badges={
                    attendants.length > 0 && (
                        <StatusBadge>{here} at this station</StatusBadge>
                    )
                }
                actions={
                    canManage &&
                    !showAdd && (
                        <Button onClick={() => setAdding(true)}>
                            <Plus aria-hidden />
                            Add attendant
                        </Button>
                    )
                }
            />

            <div className="grid gap-3">
                <p className="text-sm text-muted-foreground">
                    The people who sell fuel at the pumps. Give one a sign-in to
                    sell as themselves in the mobile app: a username and a
                    temporary password you hand over; they choose their own the
                    first time they sign in.
                </p>

                {handOver && <HandOver {...handOver} />}

                {showAdd && (
                    <AddAttendant
                        users={freeUsers}
                        canCancel={attendants.length > 0}
                        onDone={() => setAdding(false)}
                    />
                )}

                {attendants.length === 0 ? (
                    !canManage && (
                        <Panel>
                            <EmptyState
                                icon={UserRound}
                                title="No attendants yet"
                            >
                                A station manager adds the people who work the
                                pumps.
                            </EmptyState>
                        </Panel>
                    )
                ) : (
                    <Panel>
                        <PanelHeader title="Roster" />
                        <ul className="divide-y">
                            {attendants.map((attendant) =>
                                editing === attendant.id ? (
                                    <EditAttendant
                                        key={attendant.id}
                                        attendant={attendant}
                                        users={[
                                            ...users.filter(
                                                (user) =>
                                                    user.id ===
                                                    attendant.userId,
                                            ),
                                            ...freeUsers,
                                        ]}
                                        onDone={() => setEditing(null)}
                                    />
                                ) : (
                                    <AttendantRow
                                        key={attendant.id}
                                        attendant={attendant}
                                        canManage={canManage}
                                        onEdit={() => setEditing(attendant.id)}
                                    />
                                ),
                            )}
                        </ul>
                    </Panel>
                )}
            </div>
        </>
    );
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

function AttendantRow({
    attendant,
    canManage,
    onEdit,
}: {
    attendant: Attendant;
    canManage: boolean;
    onEdit: () => void;
}) {
    return (
        <li
            className={cn(
                'grid gap-3 px-3 py-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-center',
                !attendant.active && 'opacity-70',
            )}
        >
            <div className="flex min-w-0 items-center gap-3">
                <span
                    className="grid size-10 shrink-0 place-items-center bg-primary/10 text-sm font-semibold text-primary"
                    aria-hidden
                >
                    {initials(attendant.name)}
                </span>
                <div className="min-w-0">
                    <p className="flex flex-wrap items-center gap-2 font-medium">
                        <span className="truncate">{attendant.name}</span>
                        {!attendant.active && (
                            <StatusBadge>Inactive</StatusBadge>
                        )}
                        {attendant.active && !attendant.atStation && (
                            <StatusBadge tone="info">
                                Not at this station
                            </StatusBadge>
                        )}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {[attendant.employeeCode, attendant.phone]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                </div>
            </div>

            <div className="text-sm">
                <p className="text-xs text-muted-foreground">Sign-in</p>
                {attendant.signIn ? (
                    <p className="flex items-center gap-1.5 font-medium">
                        <KeyRound
                            className="size-3.5 text-emerald-600"
                            aria-hidden
                        />
                        {attendant.signIn}
                    </p>
                ) : (
                    <p className="text-muted-foreground">None</p>
                )}
            </div>

            {canManage && (
                <div className="sm:justify-self-end">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={onEdit}
                        aria-label={`Edit ${attendant.name}`}
                    >
                        <Pencil aria-hidden />
                        Edit
                    </Button>
                </div>
            )}
        </li>
    );
}

/** Twelve characters without look-alikes (0/O, 1/l/I): read out at the pump. */
function generatePassword(): string {
    const alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const values = crypto.getRandomValues(new Uint32Array(12));
    const chars = Array.from(
        values,
        (value) => alphabet[value % alphabet.length],
    );

    return `${chars.slice(0, 4).join('')}-${chars.slice(4, 8).join('')}-${chars.slice(8).join('')}`;
}

/**
 * How the attendant signs in: not at all, a new username account with a
 * temporary password, or an existing account.
 */
function SignInChoice({
    idPrefix,
    users,
    errors,
    allowLink,
}: {
    idPrefix: string;
    users: UserOption[];
    errors: Record<string, string>;
    allowLink: boolean;
}) {
    const [mode, setMode] = useState<SignInMode>('none');
    const [password, setPassword] = useState('');
    const [visible, setVisible] = useState(false);

    const options: [SignInMode, string][] = [
        ['none', 'No sign-in'],
        ['create', 'Create a sign-in'],
        ...(allowLink && users.length > 0
            ? ([['link', 'Use an existing account']] as [SignInMode, string][])
            : []),
    ];

    return (
        <fieldset className="grid gap-3 sm:col-span-full">
            <legend className="mb-1.5 text-sm font-medium">
                Sign-in for the mobile app
            </legend>
            <div className="flex flex-wrap gap-2">
                {options.map(([value, label]) => (
                    <label
                        key={value}
                        className={cn(
                            'flex cursor-pointer items-center gap-2 border px-3 py-1.5 text-sm',
                            mode === value && 'border-primary bg-primary/5',
                        )}
                    >
                        <input
                            type="radio"
                            name={`${idPrefix}-sign-in-mode`}
                            checked={mode === value}
                            onChange={() => setMode(value)}
                            className="accent-primary"
                        />
                        {label}
                    </label>
                ))}
            </div>
            <input
                type="hidden"
                name="create_sign_in"
                value={mode === 'create' ? '1' : '0'}
            />

            {mode === 'create' && (
                <div className="grid gap-3 border-l-2 border-primary/30 pl-3 sm:grid-cols-2">
                    <Field
                        id={`${idPrefix}-username`}
                        label="Username"
                        error={errors.username}
                        hint="What they type to sign in. Letters, digits, dots, dashes."
                    >
                        <Input
                            id={`${idPrefix}-username`}
                            name="username"
                            autoCapitalize="none"
                            autoComplete="off"
                            spellCheck={false}
                            maxLength={32}
                            placeholder="e.g. amina.lual"
                            required
                        />
                    </Field>
                    <Field
                        id={`${idPrefix}-password`}
                        label="Temporary password"
                        error={errors.password}
                        hint="At least 8 characters. They choose their own the first time they sign in."
                    >
                        <div className="flex gap-1">
                            <Input
                                id={`${idPrefix}-password`}
                                name="password"
                                type={visible ? 'text' : 'password'}
                                value={password}
                                onChange={(event) =>
                                    setPassword(event.target.value)
                                }
                                autoComplete="new-password"
                                minLength={8}
                                maxLength={128}
                                required
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                onClick={() => setVisible(!visible)}
                                aria-label={
                                    visible ? 'Hide password' : 'Show password'
                                }
                            >
                                {visible ? (
                                    <EyeOff aria-hidden />
                                ) : (
                                    <Eye aria-hidden />
                                )}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                onClick={() => {
                                    setPassword(generatePassword());
                                    setVisible(true);
                                }}
                                aria-label="Suggest a password"
                                title="Suggest a password"
                            >
                                <Wand2 aria-hidden />
                            </Button>
                        </div>
                    </Field>
                    {errors.sign_in && (
                        <p className="text-sm text-destructive sm:col-span-2">
                            {errors.sign_in}
                        </p>
                    )}
                </div>
            )}

            {mode === 'link' && (
                <div className="border-l-2 border-primary/30 pl-3 sm:max-w-sm">
                    <Field
                        id={`${idPrefix}-user`}
                        label="Account"
                        error={errors.user_id}
                    >
                        <Select
                            id={`${idPrefix}-user`}
                            name="user_id"
                            placeholder="Choose…"
                            options={users.map((user) => ({
                                value: user.id,
                                label: `${user.name} (${user.email})`,
                            }))}
                            required
                        />
                    </Field>
                </div>
            )}
        </fieldset>
    );
}

function StationCheckbox({ defaultChecked }: { defaultChecked: boolean }) {
    return (
        <label className="flex items-center gap-2 text-sm">
            <input type="hidden" name="at_station" value="0" />
            <input
                type="checkbox"
                name="at_station"
                value="1"
                defaultChecked={defaultChecked}
                className="accent-primary"
            />
            Works at this station
        </label>
    );
}

function AddAttendant({
    users,
    canCancel,
    onDone,
}: {
    users: UserOption[];
    canCancel: boolean;
    onDone: () => void;
}) {
    // Remounts the form after each success, clearing the sign-in choice.
    const [round, setRound] = useState(0);

    return (
        <Panel>
            <PanelHeader
                title="Add an attendant"
                actions={
                    canCancel && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={onDone}
                            aria-label="Close"
                        >
                            <X aria-hidden />
                        </Button>
                    )
                }
            />
            <PanelBody>
                <Form
                    key={round}
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setRound((current) => current + 1)}
                    className="grid gap-4 sm:grid-cols-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field id="name" label="Name" error={errors.name}>
                                <Input id="name" name="name" required />
                            </Field>
                            <Field
                                id="employee_code"
                                label="Employee code"
                                error={errors.employee_code}
                            >
                                <Input
                                    id="employee_code"
                                    name="employee_code"
                                    className="uppercase"
                                    required
                                />
                            </Field>
                            <Field
                                id="phone"
                                label="Phone"
                                error={errors.phone}
                            >
                                <Input
                                    id="phone"
                                    name="phone"
                                    type="tel"
                                    inputMode="tel"
                                />
                            </Field>
                            <SignInChoice
                                idPrefix="new"
                                users={users}
                                errors={errors}
                                allowLink
                            />
                            <div className="flex flex-wrap items-center justify-between gap-3 sm:col-span-full">
                                <StationCheckbox defaultChecked />
                                <Button type="submit" disabled={processing}>
                                    <Plus aria-hidden />
                                    Add attendant
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}

function EditAttendant({
    attendant,
    users,
    onDone,
}: {
    attendant: Attendant;
    users: UserOption[];
    onDone: () => void;
}) {
    const [active, setActive] = useState(attendant.active);

    return (
        <li className="bg-muted/40 px-3 py-3">
            <Form
                {...update.form(attendant.id)}
                options={{ preserveScroll: true }}
                onSuccess={onDone}
                className="grid gap-4 sm:grid-cols-3"
            >
                {({ errors, processing }) => (
                    <>
                        <Field
                            id={`name-${attendant.id}`}
                            label="Name"
                            error={errors.name}
                        >
                            <Input
                                id={`name-${attendant.id}`}
                                name="name"
                                defaultValue={attendant.name}
                                autoFocus
                                required
                            />
                        </Field>
                        <Field
                            id={`code-${attendant.id}`}
                            label="Employee code"
                            error={errors.employee_code}
                        >
                            <Input
                                id={`code-${attendant.id}`}
                                name="employee_code"
                                defaultValue={attendant.employeeCode ?? ''}
                                className="uppercase"
                                required
                            />
                        </Field>
                        <Field
                            id={`phone-${attendant.id}`}
                            label="Phone"
                            error={errors.phone}
                        >
                            <Input
                                id={`phone-${attendant.id}`}
                                name="phone"
                                type="tel"
                                defaultValue={attendant.phone ?? ''}
                            />
                        </Field>

                        {attendant.userId ? (
                            <Field
                                id={`user-${attendant.id}`}
                                label="Sign-in"
                                error={errors.user_id}
                                className="sm:col-span-2"
                                hint="Choose None to unlink it. The account itself stays; remove it in the workspace's members if needed."
                            >
                                <Select
                                    id={`user-${attendant.id}`}
                                    name="user_id"
                                    placeholder="None"
                                    defaultValue={attendant.userId}
                                    options={users.map((user) => ({
                                        value: user.id,
                                        label: `${user.name} (${user.email})`,
                                    }))}
                                />
                            </Field>
                        ) : (
                            <SignInChoice
                                idPrefix={attendant.id}
                                users={users}
                                errors={errors}
                                allowLink
                            />
                        )}

                        <div className="flex flex-wrap items-center gap-x-6 gap-y-3 sm:col-span-full">
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="hidden"
                                    name="active"
                                    value={active ? '1' : '0'}
                                />
                                <input
                                    type="checkbox"
                                    checked={active}
                                    onChange={() => setActive(!active)}
                                    className="accent-primary"
                                />
                                Active
                            </label>
                            <StationCheckbox
                                defaultChecked={attendant.atStation}
                            />
                            <div className="ml-auto flex gap-2">
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    <Check aria-hidden />
                                    Save
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={onDone}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </div>
                    </>
                )}
            </Form>
        </li>
    );
}

/**
 * What to hand the attendant, once. The password is the one the manager
 * typed: it is not shown again.
 */
function HandOver({
    name,
    username,
    expiresAt,
    signInUrl,
    roleNote,
}: {
    name: string;
    username: string;
    expiresAt: string;
    signInUrl: string;
    roleNote: string | null;
}) {
    const [copied, setCopied] = useState(false);
    const text = `Sign in to the Petrol app (or ${signInUrl})\nUsername: ${username}\nPassword: the temporary one you were given\nYou choose your own the first time. It works until ${expiresAt}.`;

    return (
        <section
            aria-labelledby="hand-over-title"
            className="grid gap-2 border border-emerald-300 bg-emerald-50 p-3 text-sm dark:border-emerald-500/40 dark:bg-emerald-500/10"
        >
            <h2 id="hand-over-title" className="font-semibold">
                Sign-in ready for {name}
            </h2>
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                <dt className="text-muted-foreground">Username</dt>
                <dd className="font-mono font-semibold">{username}</dd>
                <dt className="text-muted-foreground">Password</dt>
                <dd>The temporary password you just typed</dd>
                <dt className="text-muted-foreground">Works until</dt>
                <dd>{expiresAt}</dd>
            </dl>
            <p className="text-muted-foreground">
                They sign in with these in the Petrol mobile app and choose
                their own password straight away.
            </p>
            {roleNote && (
                <p className="text-amber-800 dark:text-amber-300">{roleNote}</p>
            )}
            <div>
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
                    <Copy aria-hidden />
                    {copied ? 'Copied' : 'Copy instructions'}
                </Button>
            </div>
        </section>
    );
}
