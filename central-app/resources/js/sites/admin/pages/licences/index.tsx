import { Form, Head, Link } from '@inertiajs/react';
import { ReasonForm } from '@/sites/admin/components/reason-form';
import InputError from '@/shared/components/input-error';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';

type LicenceKey = {
    id: string;
    hint: string;
    status: 'available' | 'active' | 'revoked';
    durationMonths: number | null;
    customerName: string | null;
    customerEmail: string | null;
    customerPhone: string | null;
    note: string | null;
    issuedAt: string | null;
    revokedAt: string | null;
    revokeReason: string | null;
    activation: {
        device: string;
        pharmacy: string;
        pharmacyName: string | null;
        activatedAt: string;
        expiresAt: string | null;
        channel: 'app' | 'web' | 'staff';
    } | null;
};

type Props = {
    search: string;
    status: 'all' | 'available' | 'active' | 'revoked';
    keys: LicenceKey[];
    issuedKeys: {
        customer: string | null;
        durationMonths: number | null;
        keys: string[];
    } | null;
    signedCode: { code: string; device: string; pharmacy: string } | null;
};

const tabs = [
    { key: 'all', label: 'All' },
    { key: 'available', label: 'Not activated' },
    { key: 'active', label: 'Activated' },
    { key: 'revoked', label: 'Cancelled' },
] as const;

const term = (months: number | null) =>
    months === null
        ? 'No end date'
        : months % 12 === 0
          ? `${months / 12} year${months === 12 ? '' : 's'}`
          : `${months} months`;

const day = (iso: string) => new Date(iso).toLocaleDateString();

export default function Licences({
    search,
    status,
    keys,
    issuedKeys,
    signedCode,
}: Props) {
    return (
        <>
            <Head title="Licences" />

            <h1 className="text-2xl font-semibold">Desktop licences</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                Licence keys for Miles Pharmacy on one PC (offline). A key is
                tied to a PC when it is first activated; free it here to move it
                to another.
            </p>

            {issuedKeys && (
                <div
                    role="alert"
                    className="mt-4 space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-500/40 dark:bg-amber-500/10"
                >
                    <p className="font-medium">
                        {issuedKeys.keys.length === 1
                            ? 'New licence key'
                            : `${issuedKeys.keys.length} new licence keys`}
                        {issuedKeys.customer && ` for ${issuedKeys.customer}`} (
                        {term(issuedKeys.durationMonths)}): copy or print them
                        now, they will not be shown again.
                    </p>
                    <ul className="grid gap-1 font-mono text-base sm:grid-cols-2">
                        {issuedKeys.keys.map((key) => (
                            <li key={key} className="select-all">
                                {key}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {signedCode && (
                <div
                    role="alert"
                    className="mt-4 space-y-2 rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-sm dark:border-emerald-500/40 dark:bg-emerald-500/10"
                >
                    <p className="font-medium">
                        Activation code for PC {signedCode.device}, pharmacy{' '}
                        {signedCode.pharmacy}. Send it to the customer to paste
                        under Settings, Activation.
                    </p>
                    <p className="font-mono text-xs break-all select-all">
                        {signedCode.code}
                    </p>
                </div>
            )}

            <div className="mt-5 grid gap-5 xl:grid-cols-2">
                <section className="space-y-3 rounded-lg border p-4">
                    <h2 className="text-lg font-medium">Make licence keys</h2>
                    <Form
                        action="/licences"
                        method="post"
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-3 sm:grid-cols-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field label="How many" error={errors.count}>
                                    <Input
                                        name="count"
                                        type="number"
                                        min={1}
                                        max={50}
                                        defaultValue={1}
                                        required
                                    />
                                </Field>
                                <Field
                                    label="Runs for (months)"
                                    error={errors.duration_months}
                                    hint="Empty: no end date. 12 for a year."
                                >
                                    <Input
                                        name="duration_months"
                                        type="number"
                                        min={1}
                                        max={120}
                                    />
                                </Field>
                                <Field
                                    label="Customer"
                                    error={errors.customer_name}
                                >
                                    <Input name="customer_name" />
                                </Field>
                                <Field
                                    label="Customer’s phone"
                                    error={errors.customer_phone}
                                >
                                    <Input name="customer_phone" />
                                </Field>
                                <Field
                                    label="Customer’s email"
                                    error={errors.customer_email}
                                >
                                    <Input name="customer_email" type="email" />
                                </Field>
                                <Field label="Note" error={errors.note}>
                                    <Input
                                        name="note"
                                        placeholder="Paid by mobile money, ref …"
                                    />
                                </Field>
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        Make keys
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>

                <section className="space-y-3 rounded-lg border p-4">
                    <h2 className="text-lg font-medium">
                        Activate for a customer
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        For a PC with no internet: the customer reads out their
                        key and the two codes under Settings, Activation.
                    </p>
                    <Form
                        action="/licences/activate"
                        method="post"
                        options={{ preserveScroll: true }}
                        className="grid gap-3 sm:grid-cols-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="sm:col-span-2">
                                    <Field
                                        label="Licence key"
                                        error={errors.key}
                                    >
                                        <Input
                                            name="key"
                                            placeholder="MILES-XXXX-XXXX-XXXX"
                                            required
                                            className="font-mono uppercase"
                                        />
                                    </Field>
                                </div>
                                <Field
                                    label="Device code"
                                    error={errors.device}
                                >
                                    <Input
                                        name="device"
                                        placeholder="XXXX-XXXX-XXXX"
                                        required
                                        className="font-mono uppercase"
                                    />
                                </Field>
                                <Field
                                    label="Pharmacy ID"
                                    error={errors.pharmacy}
                                >
                                    <Input
                                        name="pharmacy"
                                        placeholder="XXXX-XXXX"
                                        required
                                        className="font-mono uppercase"
                                    />
                                </Field>
                                <div className="sm:col-span-2">
                                    <Field
                                        label="Pharmacy’s name"
                                        error={errors.name}
                                    >
                                        <Input name="name" />
                                    </Field>
                                </div>
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        Make the activation code
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </section>
            </div>

            <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
                <nav className="flex gap-2" aria-label="Licence status">
                    {tabs.map((tab) => (
                        <Link
                            key={tab.key}
                            href={`/licences?status=${tab.key}&search=${encodeURIComponent(search)}`}
                            className={`rounded-md px-3 py-1.5 text-sm ${status === tab.key ? 'bg-muted font-medium' : 'hover:bg-muted/50'}`}
                            aria-current={
                                status === tab.key ? 'page' : undefined
                            }
                        >
                            {tab.label}
                        </Link>
                    ))}
                </nav>
                <form method="get" action="/licences" className="flex gap-2">
                    <input type="hidden" name="status" value={status} />
                    <Input
                        name="search"
                        defaultValue={search}
                        placeholder="Last 4 of the key, customer, device code"
                        aria-label="Search licences"
                        className="w-80"
                    />
                    <Button type="submit" variant="outline">
                        Search
                    </Button>
                </form>
            </div>

            {keys.length === 0 ? (
                <p className="mt-4 text-sm text-muted-foreground">
                    No licence keys{search ? ' match' : ' yet'}.
                </p>
            ) : (
                <ul className="mt-4 space-y-3">
                    {keys.map((key) => (
                        <li
                            key={key.id}
                            className="rounded-lg border p-4 text-sm"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="font-mono font-medium">
                                    MILES-…-{key.hint}
                                </span>
                                <Badge
                                    variant={
                                        key.status === 'revoked'
                                            ? 'destructive'
                                            : key.status === 'active'
                                              ? 'default'
                                              : 'outline'
                                    }
                                >
                                    {key.status === 'available'
                                        ? 'not activated'
                                        : key.status === 'active'
                                          ? 'activated'
                                          : 'cancelled'}
                                </Badge>
                                <span className="text-muted-foreground">
                                    {term(key.durationMonths)}
                                    {key.issuedAt &&
                                        ` · made ${day(key.issuedAt)}`}
                                </span>
                            </div>
                            <p className="mt-1 text-muted-foreground">
                                {[
                                    key.customerName,
                                    key.customerPhone,
                                    key.customerEmail,
                                    key.note,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || 'No customer recorded'}
                            </p>
                            {key.activation && (
                                <p className="mt-1">
                                    On PC{' '}
                                    <span className="font-mono">
                                        {key.activation.device}
                                    </span>
                                    , pharmacy{' '}
                                    <span className="font-mono">
                                        {key.activation.pharmacy}
                                    </span>
                                    {key.activation.pharmacyName &&
                                        ` (${key.activation.pharmacyName})`}{' '}
                                    since {day(key.activation.activatedAt)}
                                    {key.activation.expiresAt &&
                                        `, until ${day(key.activation.expiresAt)}`}
                                    {key.activation.channel === 'staff' &&
                                        ', activated by staff'}
                                </p>
                            )}
                            {key.revokedAt && (
                                <p className="mt-1 text-destructive">
                                    Cancelled {day(key.revokedAt)}:{' '}
                                    {key.revokeReason}
                                </p>
                            )}
                            {key.status !== 'revoked' && (
                                <div className="mt-3 flex flex-wrap gap-3">
                                    {key.activation && (
                                        <ReasonForm
                                            action={`/licences/${key.id}/release`}
                                            field="reason"
                                            label="Free for another PC"
                                            placeholder="Why (new PC…)"
                                        />
                                    )}
                                    <ReasonForm
                                        action={`/licences/${key.id}/revoke`}
                                        field="reason"
                                        label="Cancel the key"
                                    />
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

function Field({
    label,
    error,
    hint,
    children,
}: {
    label: string;
    error?: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}
