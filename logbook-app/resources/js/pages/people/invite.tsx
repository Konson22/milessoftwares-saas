import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Mail, MailCheck, UserCheck } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Errors } from '@/components/fields';
import { PageHeader } from '@/components/page-header';
import {
    DepartmentField,
    firstDepartment,
    LogbookPicker,
} from '@/components/people-fields';
import type {
    Department,
    LogbookOption,
    Role,
    Scope,
} from '@/components/people-fields';
import { index as peopleIndex, invite } from '@/routes/people';

type Props = {
    roles: Role[];
    departments: Department[];
    mustChooseDepartment: boolean;
    logbooks: LogbookOption[];
};

function isGmail(email: string): boolean {
    const domain = email.trim().toLowerCase().split('@')[1] ?? '';

    return domain === 'gmail.com' || domain === 'googlemail.com';
}

/**
 * Invite by email, on its own page: who, as what, and where, with what the
 * invitee will be asked to do. Once sent, back to People.
 */
export default function InvitePerson({
    roles,
    departments,
    mustChooseDepartment,
    logbooks,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const scope: Scope = { departments, mustChoose: mustChooseDepartment };
    const [email, setEmail] = useState('');
    const [role, setRole] = useState(roles[0]?.key ?? '');
    const [department, setDepartment] = useState(firstDepartment(scope));
    const [chosen, setChosen] = useState<string[]>([]);
    const [busy, setBusy] = useState(false);
    const emailId = useId();

    const address = email.trim();
    const looksValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address);
    const roleName = roles.find((r) => r.key === role)?.name ?? 'a role';
    const departmentName =
        departments.find((d) => d.id === department)?.name ?? null;
    const gmail = looksValid && isGmail(address);

    return (
        <>
            <Head title="Invite by email" />
            <PageHeader
                title="Invite by email"
                actions={
                    <Button variant="outline" asChild>
                        <Link href={peopleIndex().url}>
                            <ArrowLeft /> People
                        </Link>
                    </Button>
                }
            />

            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start">
                <form
                    className="overflow-hidden rounded-lg border bg-card shadow-[0_1px_2px_#e3e3e3]"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            invite().url,
                            {
                                email: address,
                                role,
                                department_id: department || null,
                                logbook_ids: roles.find((r) => r.key === role)
                                    ?.membership
                                    ? chosen.filter((id) =>
                                          logbooks.some(
                                              (l) =>
                                                  l.id === id &&
                                                  (department === '' ||
                                                      l.departmentId ===
                                                          department),
                                          ),
                                      )
                                    : [],
                                from_page: true,
                            },
                            {
                                onStart: () => setBusy(true),
                                onFinish: () => setBusy(false),
                            },
                        );
                    }}
                >
                    <header className="flex items-start gap-3 border-b border-[#ebebeb] px-5 py-4">
                        <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-[#e3f3ee] text-primary">
                            <Mail className="size-5" />
                        </span>
                        <div className="grid gap-0.5">
                            <h2 className="text-base font-semibold">
                                Who are you inviting?
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                They get a link by email and join Logbook with
                                the role you choose.
                            </p>
                        </div>
                    </header>

                    <div className="grid gap-6 px-5 py-5">
                        {errors.people && (
                            <Errors errors={{ people: errors.people }} />
                        )}

                        <div className="grid gap-1.5">
                            <label
                                htmlFor={emailId}
                                className="text-sm font-medium"
                            >
                                Email address
                            </label>
                            <div className="relative max-w-md">
                                <Mail className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    id={emailId}
                                    type="email"
                                    autoFocus
                                    required
                                    value={email}
                                    onChange={(e) => setEmail(e.target.value)}
                                    placeholder="name@example.com"
                                    aria-invalid={
                                        errors.email ? true : undefined
                                    }
                                    className="h-10 pl-9"
                                />
                            </div>
                            {errors.email ? (
                                <p className="text-xs text-destructive">
                                    {errors.email}
                                </p>
                            ) : (
                                looksValid && (
                                    <p className="text-xs text-muted-foreground">
                                        {gmail
                                            ? 'A Gmail address: they join with one tap on Accept.'
                                            : 'They set their name and a password when they accept.'}
                                    </p>
                                )
                            )}
                        </div>

                        <fieldset className="grid gap-2">
                            <legend className="mb-1.5 text-sm font-medium">
                                Logbook role
                            </legend>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {roles.map((r) => {
                                    const chosen = r.key === role;

                                    return (
                                        <label
                                            key={r.key}
                                            className={`flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-3 transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary ${
                                                chosen
                                                    ? 'border-primary bg-[#eef8f5]'
                                                    : 'border-[#e1e1e1] hover:border-[#c7c7c7] hover:bg-[#fafafa]'
                                            }`}
                                        >
                                            <input
                                                type="radio"
                                                name="role"
                                                value={r.key}
                                                checked={chosen}
                                                onChange={() => setRole(r.key)}
                                                className="sr-only"
                                            />
                                            <span
                                                className={`mt-0.5 grid size-4 shrink-0 place-items-center rounded-full border ${
                                                    chosen
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'border-[#b5b5b5] bg-card'
                                                }`}
                                            >
                                                {chosen && (
                                                    <Check
                                                        className="size-3"
                                                        strokeWidth={3}
                                                    />
                                                )}
                                            </span>
                                            <span className="grid gap-0.5">
                                                <span className="text-sm font-medium">
                                                    {r.name}
                                                </span>
                                                {r.description && (
                                                    <span className="text-xs text-muted-foreground">
                                                        {r.description}
                                                    </span>
                                                )}
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                            {errors.role && (
                                <p className="text-xs text-destructive">
                                    {errors.role}
                                </p>
                            )}
                        </fieldset>

                        <div className="max-w-md">
                            <DepartmentField
                                scope={scope}
                                value={department}
                                onChange={(value) => {
                                    setDepartment(value);
                                    setChosen([]);
                                }}
                                error={errors.department_id}
                            />
                        </div>

                        <LogbookPicker
                            logbooks={logbooks}
                            department={department}
                            role={roles.find((r) => r.key === role)}
                            value={chosen}
                            onChange={setChosen}
                            error={errors.logbook_ids}
                        />
                    </div>

                    <footer className="flex flex-col gap-3 border-t border-[#ebebeb] bg-[#fafafa] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-sm text-muted-foreground">
                            {looksValid ? (
                                <>
                                    <span className="font-medium text-foreground">
                                        {address}
                                    </span>{' '}
                                    joins as{' '}
                                    <span className="font-medium text-foreground">
                                        {roleName}
                                    </span>
                                    {departmentName
                                        ? ` in ${departmentName}`
                                        : ' for the whole workspace'}
                                    .
                                </>
                            ) : (
                                'Enter an email address to continue.'
                            )}
                        </p>
                        <div className="flex gap-2">
                            <Button variant="outline" asChild>
                                <Link href={peopleIndex().url}>Cancel</Link>
                            </Button>
                            <Button
                                type="submit"
                                disabled={busy || !looksValid || !role}
                            >
                                <Mail /> {busy ? 'Sending…' : 'Send invitation'}
                            </Button>
                        </div>
                    </footer>
                </form>

                <aside className="rounded-lg border bg-card p-5 shadow-[0_1px_2px_#e3e3e3]">
                    <h2 className="mb-4 text-sm font-semibold">
                        What happens next
                    </h2>
                    <ol className="grid gap-4">
                        <Step
                            icon={<MailCheck className="size-4" />}
                            title="They get an email"
                            text="With a link to join this workspace. You can cancel it from People until they accept."
                        />
                        <Step
                            icon={<Check className="size-4" />}
                            title="They accept"
                            text={
                                gmail
                                    ? 'Gmail addresses join with one tap on Accept.'
                                    : 'Gmail addresses join with one tap; others choose their name and a password.'
                            }
                        />
                        <Step
                            icon={<UserCheck className="size-4" />}
                            title="They appear in People"
                            text="With their role, already a member of the logbooks you chose. Change their logbooks there at any time."
                        />
                    </ol>
                </aside>
            </div>
        </>
    );
}

function Step({
    icon,
    title,
    text,
}: {
    icon: ReactNode;
    title: string;
    text: string;
}) {
    return (
        <li className="flex gap-3">
            <span className="grid size-8 shrink-0 place-items-center rounded-full bg-[#eef2f6] text-[#16202c]">
                {icon}
            </span>
            <span className="grid gap-0.5">
                <span className="text-sm font-medium">{title}</span>
                <span className="text-xs text-muted-foreground">{text}</span>
            </span>
        </li>
    );
}
